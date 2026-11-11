<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\DocumentType;
use App\Enums\EmailStatus;
use App\Enums\EmailType;
use App\Enums\VerificationStatus;
use App\Jobs\SendTicketEmailJob;
use App\Mail\TicketEmail;
use App\Models\Booking;
use App\Models\BookingEmail;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Emails a booking's ticket PDFs to the customer as real attachments, in two workflows:
 *  - official:    e-ticket PDFs the agency received through its normal process;
 *  - alternative: separately uploaded / customer-provided ticket PDFs, sent when the official
 *                 one is not available. Only documents a person has verified can be sent, and the
 *                 sender must confirm the review. This never changes the booking or ticket status.
 *
 * Only PDFs of the selected type that belong to the booking can be attached; every file is
 * re-checked in private storage before sending. Each attempt is a BookingEmail row (queued -> sending ->
 * sent | failed), so the owner can see who sent what to whom and the outcome.
 */
class TicketEmailService
{
    /** Booking statuses whose documents are real, issued tickets (affects the email wording). */
    public const ISSUED = [BookingStatus::Ticketed, BookingStatus::Changed, BookingStatus::Completed];

    public function __construct(private SettingsService $settings) {}

    // ------------------------------------------------------------------ what can be sent

    /**
     * Ticket PDFs of this booking for the workflow, with who they belong to, whether the file is
     * readable and whether it may be sent (alternative documents must be verified).
     */
    public function eligibleDocuments(Booking $booking, EmailType $type = EmailType::Official): Collection
    {
        $booking->loadMissing(['passengers:id,booking_id,full_name_as_document', 'tickets:id,booking_id,passenger_id,ticket_number,e_ticket_document_id']);
        $passengers = $booking->passengers->keyBy('id');

        return Document::query()
            ->where('booking_id', $booking->id)
            ->where('document_type', $type === EmailType::Official ? DocumentType::ETicket->value : DocumentType::AlternativeTicket->value)
            ->where('mime_type', 'application/pdf')
            ->with(['uploader:id,name', 'verifier:id,name'])
            ->orderBy('id')
            ->get()
            ->map(function (Document $doc) use ($booking, $passengers, $type) {
                // toBase(): plain collection, so names can be pushed even when no ticket links to this PDF.
                $tickets = $booking->tickets->where('e_ticket_document_id', $doc->id)->toBase();
                $names = $tickets->map(fn ($t) => $passengers[$t->passenger_id]?->full_name_as_document)
                    ->push($doc->passenger_id ? $passengers[$doc->passenger_id]?->full_name_as_document : null)
                    ->filter()->unique()->values()->all();

                $readable = $this->readable($doc);

                return [
                    'document' => $doc,
                    'passengers' => $names,
                    'tickets' => $tickets->pluck('ticket_number')->values()->all(),
                    'readable' => $readable,
                    'sendable' => $readable && ($type === EmailType::Official || $doc->verification_status === VerificationStatus::Verified),
                ];
            });
    }

    public function readable(Document $doc): bool
    {
        $disk = Storage::disk(DocumentStorageService::DISK);
        try {
            return $disk->exists($doc->storage_key) && $disk->size($doc->storage_key) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function maxTotalBytes(): int
    {
        return (int) config('ticket_email.max_total_mb', 18) * 1024 * 1024;
    }

    public function maxFileBytes(): int
    {
        return (int) config('ticket_email.max_file_mb', 10) * 1024 * 1024;
    }

    /** Why this booking cannot be emailed at all, or null. */
    public function bookingProblem(Booking $booking): ?string
    {
        return $booking->status === BookingStatus::Cancelled
            ? 'This booking is cancelled, so its tickets cannot be emailed.'
            : null;
    }

    /**
     * Problems with the mail setup that would make sending fail, or null.
     * The "log" and "array" mailers are allowed (development): the message is recorded, not delivered.
     */
    public function mailProblem(): ?string
    {
        $mailer = (string) config('mail.default');
        $config = config("mail.mailers.$mailer");
        if (! $config) {
            return "Mail is not configured: MAIL_MAILER \"$mailer\" is unknown.";
        }
        if (($config['transport'] ?? null) === 'smtp' && empty($config['host'])) {
            return 'Mail is not configured: set MAIL_HOST, MAIL_PORT, MAIL_USERNAME and MAIL_PASSWORD in .env.';
        }
        if (! filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
            return 'Mail is not configured: set a valid MAIL_FROM_ADDRESS in .env.';
        }

        return null;
    }

    /** True when the configured mailer really delivers email (false for log/array in development). */
    public function deliversMail(): bool
    {
        return ! in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['log', 'array'], true);
    }

    // ------------------------------------------------------------------ checks

    /**
     * The recipient for this send: the customer's saved email, or a one-off override.
     *
     * @return array{0: string, 1: bool} [recipient, isOverride]
     */
    public function resolveRecipient(Booking $booking, ?string $override): array
    {
        $saved = $booking->customer?->email;
        $recipient = trim((string) ($override ?: $saved));
        if ($recipient === '') {
            throw ValidationException::withMessages(['recipient' => 'The customer has no email address. Add it to the customer, or enter an address for this email.']);
        }
        if (Validator::make(['r' => $recipient], ['r' => 'email:rfc,strict'])->fails()) {
            throw ValidationException::withMessages(['recipient' => "\"$recipient\" is not a valid email address."]);
        }

        return [$recipient, $saved === null || strcasecmp($recipient, $saved) !== 0];
    }

    /**
     * The selected documents, verified: e-ticket PDFs of this booking, present and readable, within size limits.
     *
     * @param  list<int>  $ids
     * @return Collection<int, array{document: Document, passengers: list<string>, tickets: list<string>, readable: bool}>
     */
    public function selectedDocuments(Booking $booking, array $ids, EmailType $type = EmailType::Official): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw ValidationException::withMessages(['document_ids' => 'Select at least one ticket PDF to send.']);
        }
        $eligible = $this->eligibleDocuments($booking, $type)->keyBy(fn ($row) => $row['document']->id);
        $what = $type === EmailType::Official ? 'an official e-ticket PDF' : 'an alternative ticket PDF';

        $errors = [];
        $selected = collect();
        foreach ($ids as $id) {
            $row = $eligible->get($id);
            if (! $row) {
                $errors[] = "Document #$id is not $what of booking {$booking->booking_code}.";

                continue;
            }
            $doc = $row['document'];
            if ($type === EmailType::Alternative && $doc->verification_status !== VerificationStatus::Verified) {
                $errors[] = $doc->verification_status === VerificationStatus::Rejected
                    ? "{$doc->original_name} was rejected during review and cannot be sent."
                    : "{$doc->original_name} has not been verified yet. Review it and mark it as verified before sending.";
            } elseif (! $row['readable']) {
                $errors[] = "{$doc->original_name} is missing from storage or cannot be read. Upload it again.";
            } elseif ($doc->size_bytes > $this->maxFileBytes()) {
                $errors[] = "{$doc->original_name} is larger than ".config('ticket_email.max_file_mb').' MB.';
            }
            $selected->push($row);
        }
        $total = $selected->sum(fn ($r) => $r['document']->size_bytes);
        if ($total > $this->maxTotalBytes()) {
            $errors[] = sprintf('The selected files total %.1f MB; the limit per email is %d MB. Send them in two emails.', $total / 1048576, config('ticket_email.max_total_mb'));
        }
        if ($errors) {
            throw ValidationException::withMessages(['document_ids' => $errors]);
        }

        return $selected;
    }

    public function subject(Booking $booking, EmailType $type = EmailType::Official): string
    {
        $what = match (true) {
            $type === EmailType::Alternative => 'Your Ticket Document',
            in_array($booking->status, self::ISSUED, true) => 'Your Flight Tickets',
            default => 'Your Travel Documents',
        };

        return $booking->booking_code ? "$what – Booking {$booking->booking_code}" : $what;
    }

    /** Attachment metadata snapshot stored with the email record (no file contents). */
    public function snapshot(Collection $selected): array
    {
        $used = [];

        return $selected->map(function (array $row) use (&$used) {
            $name = $this->safeFilename($row['document']->original_name, $used);

            return [
                'document_id' => $row['document']->id,
                'name' => $name,
                'size_bytes' => $row['document']->size_bytes,
                'passengers' => $row['passengers'],
                'tickets' => $row['tickets'],
                // Internal classification for the history; the customer email never shows it.
                'source_type' => $row['document']->source_type?->value,
            ];
        })->values()->all();
    }

    /** A filename safe for mail clients: no paths or odd characters, always .pdf, unique within the email. */
    public function safeFilename(string $original, array &$used = []): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base), ' ._-');
        $base = mb_substr($base !== '' ? $base : 'ticket', 0, 80);
        $name = "$base.pdf";
        for ($n = 2; in_array(strtolower($name), $used, true); $n++) {
            $name = "$base ($n).pdf";
        }
        $used[] = strtolower($name);

        return $name;
    }

    // ------------------------------------------------------------------ sending

    /**
     * Records the email and sends it (queued or immediately, per config).
     * The same idempotency key returns the existing attempt instead of sending again.
     */
    public function request(
        Booking $booking, array $documentIds, ?string $override, ?string $key, bool $confirmResend, User $user,
        EmailType $type = EmailType::Official, bool $reviewed = false, bool $officialPending = false,
    ): BookingEmail {
        if ($key && ($existing = BookingEmail::where('idempotency_key', $key)->first())) {
            abort_unless($existing->booking_id === $booking->id, 422, 'This request key belongs to another booking.');

            return $existing;
        }
        if ($problem = $this->bookingProblem($booking)) {
            throw ValidationException::withMessages(['booking' => $problem]);
        }
        if ($problem = $this->mailProblem()) {
            throw ValidationException::withMessages(['mail' => $problem]);
        }
        [$recipient, $isOverride] = $this->resolveRecipient($booking, $override);
        $selected = $this->selectedDocuments($booking, $documentIds, $type);
        if ($type === EmailType::Alternative && ! $reviewed) {
            throw ValidationException::withMessages(['confirm_reviewed' => 'Confirm that you reviewed the document and verified the recipient before sending.']);
        }

        // Deliberate resends only: the same tickets already sent to the same address need confirmation.
        $ids = $selected->map(fn ($r) => $r['document']->id)->sort()->values()->all();
        $previous = $booking->emails()->where('email_type', $type->value)->whereIn('status', [EmailStatus::Sent->value, EmailStatus::Queued->value, EmailStatus::Sending->value])
            ->where('recipient', $recipient)->get()
            ->first(fn (BookingEmail $e) => collect($e->documentIds())->sort()->values()->all() === $ids);
        if ($previous && ! $confirmResend) {
            throw ValidationException::withMessages(['confirm_resend' => sprintf(
                'These tickets were already %s to %s on %s. Confirm to send them again.',
                $previous->status === EmailStatus::Sent ? 'sent' : 'queued', $recipient, $previous->requested_at->format('d M Y H:i'),
            )]);
        }

        $snapshot = $this->snapshot($selected);
        $email = DB::transaction(fn () => BookingEmail::create([
            'booking_id' => $booking->id,
            'email_type' => $type,
            'customer_id' => $booking->customer_id,
            'recipient' => $recipient,
            'recipient_is_override' => $isOverride,
            'reviewed_confirmed' => $type === EmailType::Alternative && $reviewed,
            'official_pending' => $type === EmailType::Alternative && $officialPending,
            'subject' => $this->subject($booking, $type),
            'attachments' => $snapshot,
            'attachment_count' => count($snapshot),
            'total_bytes' => array_sum(array_column($snapshot, 'size_bytes')),
            'status' => EmailStatus::Queued,
            'idempotency_key' => $key,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]));

        $this->dispatch($email);

        return $email->refresh();
    }

    /** Puts a failed attempt back in the queue (the owner's "Retry"). */
    public function retry(BookingEmail $email): BookingEmail
    {
        abort_unless($email->status === EmailStatus::Failed, 422, 'Only failed emails can be retried.');
        if ($problem = $this->mailProblem()) {
            throw ValidationException::withMessages(['mail' => $problem]);
        }
        $email->update(['status' => EmailStatus::Queued, 'failure_reason' => null, 'failed_at' => null]);
        $this->dispatch($email);

        return $email->refresh();
    }

    private function dispatch(BookingEmail $email): void
    {
        if (config('ticket_email.queue')) {
            SendTicketEmailJob::dispatch($email->id);
        } else {
            SendTicketEmailJob::dispatchSync($email->id);
        }
    }

    /**
     * Sends one queued email. Called by the job; safe against duplicate jobs and retries because
     * the row is claimed atomically (queued -> sending) before anything is sent.
     */
    public function deliver(int $emailId): void
    {
        $claimed = BookingEmail::whereKey($emailId)->where('status', EmailStatus::Queued->value)
            ->update(['status' => EmailStatus::Sending->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed === 0) {
            return; // already sent, being sent, or cancelled by another worker
        }
        $email = BookingEmail::with('booking.customer')->findOrFail($emailId);

        try {
            // Re-check the files at send time: they may have been archived or removed since the request.
            $selected = $this->selectedDocuments($email->booking, $email->documentIds(), $email->email_type);
            $mailable = new TicketEmail($email->booking, $email->subject, $this->snapshot($selected), $selected->pluck('document')->all(),
                $email->email_type, $email->official_pending);
            $sent = Mail::to($email->recipient)->send($mailable);

            $email->update([
                'status' => EmailStatus::Sent,
                'sent_at' => now(),
                'mailer' => (string) config('mail.default'),
                'message_id' => $sent?->getMessageId(),
                'failure_reason' => null,
            ]);
        } catch (Throwable $e) {
            $this->markFailed($email, $e);
        }
    }

    public function markFailed(BookingEmail $email, Throwable $e): void
    {
        $email->update([
            'status' => EmailStatus::Failed,
            'failed_at' => now(),
            'mailer' => (string) config('mail.default'),
            'failure_reason' => mb_substr($this->safeReason($e), 0, 500),
        ]);
        // Log the technical detail for the developer, without file contents or credentials.
        Log::warning('Ticket email failed', ['booking_email_id' => $email->id, 'error' => $e->getMessage()]);
    }

    /** A message an agency user can act on; never a stack trace or credentials. */
    private function safeReason(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return implode(' ', array_merge(...array_values($e->errors())));
        }
        if ($e instanceof TransportExceptionInterface) {
            return 'The mail server could not send the message (connection, login or rejection). Check the mail settings, then retry.';
        }

        return 'Sending failed unexpectedly. Please retry; if it fails again, check the mail settings and the application log.';
    }
}
