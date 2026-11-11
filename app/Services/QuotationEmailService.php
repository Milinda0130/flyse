<?php

namespace App\Services;

use App\Enums\EmailStatus;
use App\Enums\QuotationStatus;
use App\Jobs\SendQuotationEmailJob;
use App\Mail\QuotationMail;
use App\Models\FlightQuotation;
use App\Models\FlightQuotationPdf;
use App\Models\QuotationEmail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Emails a quotation PDF to the customer as a real attachment. The exact PDF version is recorded
 * with the attempt, so the agency can always see which version the customer received.
 * Reuses the ticket-email mail checks, size limit and queue setting.
 */
class QuotationEmailService
{
    /** Statuses from which a quotation may be emailed (content completeness is checked separately). */
    public const SENDABLE = [QuotationStatus::Draft, QuotationStatus::Ready, QuotationStatus::Sent, QuotationStatus::Accepted];

    public function __construct(
        private TicketEmailService $mail,
        private QuotationPdfService $pdfs,
        private QuotationService $quotations,
    ) {}

    public function subject(FlightQuotation $q): string
    {
        $from = $q->origin_code ?: $q->origin_city;
        $to = $q->destination_code ?: $q->destination_city;

        return "Your Flight Quotation – {$q->reference} – $from to $to";
    }

    /** Why this quotation cannot be emailed now, or null. */
    public function problem(FlightQuotation $q): ?string
    {
        if (! in_array($q->status, self::SENDABLE, true)) {
            return 'A quotation that is '.$q->status->label().' cannot be emailed.';
        }

        return $this->mail->mailProblem();
    }

    /** @return array{0: string, 1: bool} [recipient, isOverride] */
    public function resolveRecipient(FlightQuotation $q, ?string $override): array
    {
        $saved = $q->customer?->email;
        $recipient = trim((string) ($override ?: $saved));
        if ($recipient === '') {
            throw ValidationException::withMessages(['recipient' => $q->customer
                ? 'The customer has no email address. Add it to the customer, or enter an address for this email.'
                : 'Enter the email address to send this quotation to.']);
        }
        if (Validator::make(['r' => $recipient], ['r' => 'email:rfc,strict'])->fails()) {
            throw ValidationException::withMessages(['recipient' => "\"$recipient\" is not a valid email address."]);
        }

        return [$recipient, $saved === null || strcasecmp($recipient, $saved) !== 0];
    }

    public function request(FlightQuotation $q, ?string $override, ?string $key, bool $confirmResend, User $user): QuotationEmail
    {
        if ($key && ($existing = QuotationEmail::where('idempotency_key', $key)->first())) {
            abort_unless($existing->flight_quotation_id === $q->id, 422, 'This request key belongs to another quotation.');

            return $existing;
        }
        if ($problem = $this->problem($q)) {
            throw ValidationException::withMessages(['quotation' => $problem]);
        }
        [$recipient, $isOverride] = $this->resolveRecipient($q->loadMissing('customer'), $override);
        $pdf = $this->pdfs->current($q, $user);
        $this->checkFile($pdf);

        $previous = $q->emails()->where('flight_quotation_pdf_id', $pdf->id)->where('recipient', $recipient)
            ->whereIn('status', [EmailStatus::Sent->value, EmailStatus::Queued->value, EmailStatus::Sending->value])->first();
        if ($previous && ! $confirmResend) {
            throw ValidationException::withMessages(['confirm_resend' => sprintf(
                'Version %d of this quotation was already %s to %s on %s. Confirm to send it again.',
                $pdf->version, $previous->status === EmailStatus::Sent ? 'sent' : 'queued', $recipient, $previous->requested_at->format('d M Y H:i'),
            )]);
        }

        $email = QuotationEmail::create([
            'flight_quotation_id' => $q->id,
            'customer_id' => $q->customer_id,
            'flight_quotation_pdf_id' => $pdf->id,
            'recipient' => $recipient,
            'recipient_is_override' => $isOverride,
            'subject' => $this->subject($q),
            'attachment_name' => $pdf->filename(),
            'size_bytes' => $pdf->size_bytes,
            'status' => EmailStatus::Queued,
            'idempotency_key' => $key,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);
        $this->dispatch($email);

        return $email->refresh();
    }

    public function retry(QuotationEmail $email): QuotationEmail
    {
        abort_unless($email->status === EmailStatus::Failed, 422, 'Only failed emails can be retried.');
        if ($problem = $this->mail->mailProblem()) {
            throw ValidationException::withMessages(['mail' => $problem]);
        }
        $email->update(['status' => EmailStatus::Queued, 'failure_reason' => null, 'failed_at' => null]);
        $this->dispatch($email);

        return $email->refresh();
    }

    private function dispatch(QuotationEmail $email): void
    {
        config('ticket_email.queue') ? SendQuotationEmailJob::dispatch($email->id) : SendQuotationEmailJob::dispatchSync($email->id);
    }

    /** Sends one queued email; the atomic claim makes duplicate jobs harmless. */
    public function deliver(int $id): void
    {
        $claimed = QuotationEmail::whereKey($id)->where('status', EmailStatus::Queued->value)
            ->update(['status' => EmailStatus::Sending->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $email = QuotationEmail::with(['quotation.customer', 'pdf'])->findOrFail($id);

        try {
            $this->checkFile($email->pdf);
            $sent = Mail::to($email->recipient)->send(new QuotationMail($email->quotation, $email->subject, $email->pdf));
            $email->update(['status' => EmailStatus::Sent, 'sent_at' => now(), 'mailer' => (string) config('mail.default'), 'message_id' => $sent?->getMessageId()]);
            $this->quotations->markSent($email->quotation);
        } catch (Throwable $e) {
            $this->markFailed($email, $e);
        }
    }

    public function markFailed(QuotationEmail $email, Throwable $e): void
    {
        $reason = match (true) {
            $e instanceof ValidationException => implode(' ', array_merge(...array_values($e->errors()))),
            $e instanceof TransportExceptionInterface => 'The mail server could not send the message (connection, login or rejection). Check the mail settings, then retry.',
            default => 'Sending failed unexpectedly. Please retry; if it fails again, check the mail settings and the application log.',
        };
        $email->update(['status' => EmailStatus::Failed, 'failed_at' => now(), 'mailer' => (string) config('mail.default'), 'failure_reason' => mb_substr($reason, 0, 500)]);
        Log::warning('Quotation email failed', ['quotation_email_id' => $email->id, 'error' => $e->getMessage()]);
    }

    /** The attachment must exist, be non-empty and fit the provider's size limit. */
    private function checkFile(?FlightQuotationPdf $pdf): void
    {
        $disk = Storage::disk(DocumentStorageService::DISK);
        if (! $pdf || ! $disk->exists($pdf->storage_key) || $disk->size($pdf->storage_key) === 0) {
            throw ValidationException::withMessages(['quotation' => 'The quotation PDF is missing from storage. Generate it again.']);
        }
        if ($pdf->size_bytes > $this->mail->maxTotalBytes()) {
            throw ValidationException::withMessages(['quotation' => sprintf('The PDF is %.1f MB; the limit per email is %d MB.', $pdf->size_bytes / 1048576, config('ticket_email.max_total_mb'))]);
        }
    }
}
