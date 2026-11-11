<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EmailType;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendTicketEmailRequest;
use App\Http\Resources\BookingEmailResource;
use App\Mail\TicketEmail;
use App\Models\Booking;
use App\Models\BookingEmail;
use App\Services\TicketEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Emailing a booking's ticket PDFs to the customer, with preview and history.
 * One set of endpoints serves both workflows; "email_type" chooses official or alternative.
 */
class TicketEmailController extends Controller
{
    public function __construct(private TicketEmailService $service) {}

    /** Everything the "Email tickets" dialog needs: recipient, eligible PDFs per workflow, limits, mail status and history. */
    public function show(Booking $booking): JsonResponse
    {
        Gate::authorize('email-tickets');
        $booking->load('customer:id,full_name,email');
        $docs = fn (EmailType $type) => $this->service->eligibleDocuments($booking, $type)->map(fn ($row) => [
            'id' => $row['document']->id,
            'name' => $row['document']->original_name,
            'size_bytes' => $row['document']->size_bytes,
            'passengers' => $row['passengers'],
            'tickets' => $row['tickets'],
            'readable' => $row['readable'],
            'sendable' => $row['sendable'],
            'uploaded_at' => $row['document']->created_at?->toIso8601String(),
            'uploaded_by' => $row['document']->uploader?->name,
            'source_type' => $row['document']->source_type?->value,
            'source_label' => $row['document']->source_type?->label(),
            'source_description' => $row['document']->source_description,
            'verification_status' => $row['document']->verification_status?->value,
            'verified_by' => $row['document']->verifier?->name,
            'verified_at' => $row['document']->verified_at?->toIso8601String(),
            'verification_note' => $row['document']->verification_note,
        ])->values();

        return response()->json([
            'customer' => ['name' => $booking->customer?->full_name, 'email' => $booking->customer?->email],
            'subject' => $this->service->subject($booking),
            'subjects' => [
                'official' => $this->service->subject($booking, EmailType::Official),
                'alternative' => $this->service->subject($booking, EmailType::Alternative),
            ],
            'documents' => $docs(EmailType::Official),
            'alternative_documents' => $docs(EmailType::Alternative),
            'limits' => ['max_total_bytes' => $this->service->maxTotalBytes(), 'max_file_bytes' => $this->service->maxFileBytes()],
            'booking_problem' => $this->service->bookingProblem($booking),
            'mail_problem' => $this->service->mailProblem(),
            'delivers_mail' => $this->service->deliversMail(),
            'mailer' => config('mail.default'),
            'queued' => (bool) config('ticket_email.queue'),
            'history' => BookingEmailResource::collection($booking->emails()->with('requester:id,name')->limit(20)->get()),
        ]);
    }

    /** Renders the real email template with the selected documents (no sending). */
    public function preview(Request $request, Booking $booking): JsonResponse
    {
        Gate::authorize('email-tickets');
        $data = $request->validate([
            'email_type' => ['sometimes', Rule::enum(EmailType::class)],
            'document_ids' => ['required', 'array', 'min:1', 'max:20'],
            'document_ids.*' => ['integer', 'distinct'],
            'recipient' => ['nullable', 'string', 'max:255'],
            'official_pending' => ['sometimes', 'boolean'],
        ]);
        $type = EmailType::tryFrom((string) ($data['email_type'] ?? '')) ?? EmailType::Official;
        [$recipient, $isOverride] = $this->service->resolveRecipient($booking, $data['recipient'] ?? null);
        $selected = $this->service->selectedDocuments($booking, $data['document_ids'], $type);
        $snapshot = $this->service->snapshot($selected);
        $subject = $this->service->subject($booking, $type);
        $officialPending = $type === EmailType::Alternative && $request->boolean('official_pending');

        return response()->json([
            'email_type' => $type->value,
            'subject' => $subject,
            'recipient' => $recipient,
            'recipient_is_override' => $isOverride,
            'attachments' => $snapshot,
            'total_bytes' => array_sum(array_column($snapshot, 'size_bytes')),
            'html' => (new TicketEmail($booking, $subject, $snapshot, [], $type, $officialPending))->render(),
        ]);
    }

    public function store(SendTicketEmailRequest $request, Booking $booking): JsonResponse
    {
        $data = $request->validated();
        $email = $this->service->request(
            $booking, $data['document_ids'], $data['recipient'] ?? null, $data['idempotency_key'] ?? null,
            $request->boolean('confirm_resend'), $request->user(),
            $request->emailType(), $request->boolean('confirm_reviewed'), $request->boolean('official_pending'),
        );

        return (new BookingEmailResource($email->load('requester:id,name')))
            ->response()->setStatusCode($email->wasRecentlyCreated ? 201 : 200);
    }

    public function retry(BookingEmail $bookingEmail): BookingEmailResource
    {
        Gate::authorize('email-tickets');

        return new BookingEmailResource($this->service->retry($bookingEmail)->load('requester:id,name'));
    }

    public function history(Booking $booking): AnonymousResourceCollection
    {
        Gate::authorize('email-tickets');

        return BookingEmailResource::collection($booking->emails()->with('requester:id,name')->limit(50)->get());
    }
}
