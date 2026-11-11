<?php

namespace App\Mail;

use App\Enums\BookingStatus;
use App\Enums\EmailType;
use App\Models\Booking;
use App\Models\Document;
use App\Services\AgencyBrandingService;
use App\Services\DocumentStorageService;
use App\Services\SettingsService;
use App\Services\TicketEmailService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The customer-facing ticket email (HTML + plain text) with the selected PDFs attached.
 * Contains only customer-appropriate data: no costs, margins, internal notes, passport details,
 * or the internal source/verification of an alternative document.
 *
 * Alternative documents get their own wording: never "your tickets are ready/issued", and the
 * "official ticket still pending" sentence only when staff confirmed that fact.
 */
class TicketEmail extends Mailable
{
    /**
     * @param  list<array{document_id: int, name: string, size_bytes: int, passengers: list<string>, tickets: list<string>}>  $attachmentList
     * @param  list<Document>  $documents  the verified documents to attach (empty for previews)
     */
    public function __construct(
        public Booking $booking,
        public string $emailSubject,
        public array $attachmentList,
        public array $documents = [],
        public EmailType $emailType = EmailType::Official,
        public bool $officialPending = false,
    ) {}

    public function envelope(): Envelope
    {
        $agency = $this->agency();

        return new Envelope(
            // Sender address always comes from server mail config; only the display name is the agency's.
            from: new Address((string) config('mail.from.address'), $agency['name']),
            replyTo: $agency['email'] ? [new Address($agency['email'], $agency['name'])] : [],
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tickets',
            text: 'emails.tickets-text',
            with: $this->viewData(),
        );
    }

    /** Real PDF attachments read from private storage (never links). */
    public function attachments(): array
    {
        $names = collect($this->attachmentList)->pluck('name', 'document_id');

        return collect($this->documents)->map(fn (Document $doc) => Attachment::fromStorageDisk(DocumentStorageService::DISK, $doc->storage_key)
            ->as($names[$doc->id] ?? app(TicketEmailService::class)->safeFilename($doc->original_name))
            ->withMime('application/pdf'))->all();
    }

    /** @return array{name: string, email: ?string, phone: ?string, website: ?string, address: ?string, logo: ?string} */
    private function agency(): array
    {
        $s = app(SettingsService::class)->all();
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'name' => $clean($s['agency_name']) ?? 'Your Travel Agency',
            'email' => filter_var($s['agency_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $s['agency_email'] : null,
            'phone' => $clean($s['agency_phone'] ?? null),
            'website' => $clean($s['agency_website'] ?? null),
            'address' => $clean($s['agency_address'] ?? null),
            'logo' => str_starts_with((string) ($s['agency_logo_url'] ?? ''), 'https://') ? $s['agency_logo_url'] : null,
            // Embedded in the email when there is no public logo URL.
            'logo_png' => app(AgencyBrandingService::class)->logoPng(),
        ];
    }

    /** Everything the templates show, built from the booking's real records. */
    private function viewData(): array
    {
        $b = $this->booking->loadMissing(['customer:id,full_name', 'passengers:id,booking_id,full_name_as_document', 'flights']);
        $alternative = $this->emailType === EmailType::Alternative;
        // Alternative documents are never presented as the agency's issued tickets.
        $issued = ! $alternative && in_array($b->status, TicketEmailService::ISSUED, true);

        $segments = $b->flights->map(fn ($f) => [
            'airline' => $f->airline,
            'flight_number' => $f->flight_number,
            'origin' => $f->origin_code,
            'destination' => $f->destination_code,
            'departure' => $f->departure_at?->format('D, d M Y · H:i'),
            'arrival' => $f->arrival_at?->format('D, d M Y · H:i'),
            'cabin' => $f->cabin_class?->label(),
            'baggage' => $f->baggage_allowance,
            'cancelled' => $f->status?->value === 'cancelled',
        ])->values()->all();

        // "CMB → DOH → ARN"; a break in the chain (e.g. return trip) is shown with " / ".
        $route = '';
        $last = null;
        foreach ($b->flights as $f) {
            $route .= $last === null ? $f->origin_code : ($last !== $f->origin_code ? ' / '.$f->origin_code : '');
            $route .= ' → '.$f->destination_code;
            $last = $f->destination_code;
        }

        return [
            'agency' => $this->agency(),
            'issued' => $issued,
            'alternative' => $alternative,
            'officialPending' => $alternative && $this->officialPending,
            'heading' => match (true) {
                $alternative => 'Your Ticket Document',
                $issued => 'Your Flight Tickets Are Ready',
                default => 'Your Travel Documents',
            },
            'customerName' => $b->customer?->full_name ?: null,
            'bookingCode' => $b->booking_code,
            'statusLabel' => $b->status === BookingStatus::Inquiry ? null : $b->status->label(),
            'pnr' => $b->pnr,
            'emailDate' => now()->format('d M Y'),
            'routeSummary' => $route ?: null,
            'requestedRoute' => $route ? null : $b->requestedRoute(),
            'segments' => $segments,
            'passengers' => $b->passengers->pluck('full_name_as_document')->filter()->values()->all(),
            'attachmentsList' => $this->attachmentList,
        ];
    }
}
