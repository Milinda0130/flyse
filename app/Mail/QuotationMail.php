<?php

namespace App\Mail;

use App\Models\FlightQuotation;
use App\Models\FlightQuotationPdf;
use App\Services\AgencyBrandingService;
use App\Services\DocumentStorageService;
use App\Services\SettingsService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The customer email carrying the quotation PDF (HTML + plain text). Customer-facing values only:
 * no costs, margins or staff notes.
 */
class QuotationMail extends Mailable
{
    /** @param  FlightQuotationPdf|null  $pdf  the stored version to attach (null for previews) */
    public function __construct(public FlightQuotation $quotation, public string $emailSubject, public ?FlightQuotationPdf $pdf = null, public ?string $previewFilename = null) {}

    public function envelope(): Envelope
    {
        $agency = $this->agency();

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $agency['name']),
            replyTo: $agency['email'] ? [new Address($agency['email'], $agency['name'])] : [],
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        $q = $this->quotation->loadMissing('customer');
        $pax = collect(['adults' => 'adult', 'children' => 'child', 'infants' => 'infant'])
            ->map(fn ($label, $f) => $q->{$f} > 0 ? $q->{$f}.' '.($q->{$f} === 1 ? $label : ($label === 'child' ? 'children' : $label.'s')) : null)
            ->filter()->implode(', ');

        return new Content(view: 'emails.quotation', text: 'emails.quotation-text', with: [
            'agency' => $this->agency(),
            'customerName' => $q->customer?->full_name,
            'reference' => $q->reference,
            'route' => $q->placeLabel('origin').($q->isReturn() ? ' ⇄ ' : ' → ').$q->placeLabel('destination'),
            'outbound' => $q->outbound_date->format('l, d F Y'),
            'return' => $q->return_date?->format('l, d F Y'),
            'passengers' => $pax,
            'cabin' => $q->cabin_class->label(),
            'validUntil' => $q->valid_until?->format('d F Y, H:i'),
            'customerMessage' => $q->message_to_customer,
            'filename' => $this->pdf?->filename() ?? $this->previewFilename,
        ]);
    }

    /** The stored PDF version itself, from private storage. */
    public function attachments(): array
    {
        if (! $this->pdf) {
            return [];
        }

        return [Attachment::fromStorageDisk(DocumentStorageService::DISK, $this->pdf->storage_key)->as($this->pdf->filename())->withMime('application/pdf')];
    }

    private function agency(): array
    {
        $agency = app(AgencyBrandingService::class)->agency();
        // Email clients block data: images; only a public https logo URL is used in emails.
        $url = (string) (app(SettingsService::class)->get('agency_logo_url') ?? '');
        $agency['logo'] = str_starts_with($url, 'https://') ? $url : null;
        $agency['logo_png'] = app(AgencyBrandingService::class)->logoPng();

        return $agency;
    }
}
