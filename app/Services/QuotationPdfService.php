<?php

namespace App\Services;

use App\Enums\BaggageStatus;
use App\Enums\Refundability;
use App\Enums\YesNoUnknown;
use App\Models\Airline;
use App\Models\FlightQuotation;
use App\Models\FlightQuotationOption;
use App\Models\FlightQuotationPdf;
use App\Models\User;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The customer-facing quotation PDF (resources/views/pdf/quotation.blade.php), built only from the
 * saved quotation. Internal costs, margins and staff notes are never passed to the template.
 * Each distinct content is stored once as a numbered version, so the exact emailed file is kept.
 */
class QuotationPdfService
{
    public function __construct(
        private QuotationService $quotations,
        private AgencyBrandingService $branding,
        private AirlineLogoService $logos,
    ) {}

    /** Renders the current content (used for previews; nothing is stored). */
    public function render(FlightQuotation $quotation): string
    {
        return $this->renderData($this->viewData($quotation));
    }

    /**
     * The stored PDF for the current content: the latest version if nothing changed, otherwise a
     * new version. Incomplete quotations cannot produce an official PDF.
     */
    public function current(FlightQuotation $quotation, ?User $user): FlightQuotationPdf
    {
        if ($problems = $this->quotations->problems($quotation->load('options.segments'))) {
            throw ValidationException::withMessages(['quotation' => $problems]);
        }
        $data = $this->viewData($quotation);
        $hash = $this->hash($data);
        $latest = $quotation->pdfs()->first();
        $disk = Storage::disk(DocumentStorageService::DISK);
        if ($latest && $latest->content_hash === $hash && $disk->exists($latest->storage_key)) {
            return $latest;
        }

        try {
            $pdf = $this->renderData($data);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['quotation' => 'The PDF could not be created. Please try again; if it keeps failing, check the application log.']);
        }
        $key = 'quotations/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.pdf';
        $disk->put($key, $pdf);

        try {
            return DB::transaction(function () use ($quotation, $key, $pdf, $hash, $user) {
                $version = (int) $quotation->pdfs()->lockForUpdate()->max('version') + 1;

                return $quotation->pdfs()->create([
                    'version' => $version, 'storage_key' => $key, 'size_bytes' => strlen($pdf), 'content_hash' => $hash, 'generated_by' => $user?->id,
                ]);
            });
        } catch (Throwable $e) {
            $disk->delete($key);
            throw $e;
        }
    }

    /** True when the latest stored PDF still matches the saved quotation. */
    public function isCurrent(FlightQuotation $quotation): bool
    {
        $latest = $quotation->pdfs()->first();

        return $latest !== null && $latest->content_hash === $this->hash($this->viewData($quotation->load('options.segments')));
    }

    public function stream(FlightQuotationPdf $pdf, bool $inline = false): StreamedResponse
    {
        $disk = Storage::disk(DocumentStorageService::DISK);
        abort_unless($disk->exists($pdf->storage_key), 404, 'The PDF file is missing from storage. Generate it again.');
        $pdf->loadMissing('quotation');

        return $disk->download($pdf->storage_key, $pdf->filename(), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$pdf->filename().'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function renderData(array $data): string
    {
        $pdf = Pdf::loadView('pdf.quotation', $data)
            ->setPaper('a4')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isFontSubsettingEnabled' => true]);
        // "Page x of y" is stamped after layout (CSS cannot know the page count).
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $dompdf->getCanvas()->page_text(505, 812, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.36, 0.42, 0.52]);

        return $dompdf->output();
    }

    /** Content hash without the print date, so re-generating unchanged content makes no new version. */
    private function hash(array $data): string
    {
        unset($data['quotationDate']);

        return hash('sha256', json_encode($data));
    }

    // ------------------------------------------------------------------ template data

    public static function money(?int $cents, string $currency): ?string
    {
        if ($cents === null) {
            return null;
        }
        $decimals = $cents % 100 === 0 ? 0 : 2;

        return number_format($cents / 100, $decimals).' '.$currency;
    }

    /** Everything the template shows. Customer-facing values only. */
    public function viewData(FlightQuotation $q): array
    {
        $q->loadMissing(['customer', 'options.segments']);
        $airlineIds = $q->options->flatMap(fn ($o) => $o->segments->pluck('airline_id'))->filter()->unique();
        $logos = Airline::whereIn('id', $airlineIds)->get()->mapWithKeys(fn ($a) => [$a->id => $this->logos->dataUri($a)]);

        $options = $q->options->map(fn (FlightQuotationOption $o) => $this->optionData($q, $o, $logos))->values()->all();

        // Headline price: one option = its price; several in one currency = "from" the cheapest.
        $pricedOptions = collect($options)->filter(fn ($o) => $o['price']['party_total_cents'] !== null);
        $headline = null;
        if ($pricedOptions->isNotEmpty() && $pricedOptions->pluck('currency')->unique()->count() === 1) {
            $min = $pricedOptions->sortBy('price.party_total_cents')->first();
            $headline = [
                'prefix' => count($options) > 1 ? 'From' : null,
                'amount' => $min['price']['party_total'],
            ];
        }

        $pax = collect(['adults' => 'Adult', 'children' => 'Child', 'infants' => 'Infant'])
            ->map(fn ($label, $field) => $q->{$field} > 0 ? $q->{$field}.' '.($q->{$field} === 1 ? $label : ($label === 'Child' ? 'Children' : $label.'s')) : null)
            ->filter()->implode(', ');

        return [
            'agency' => $this->branding->agency(),
            'watermark' => $this->branding->quotationWatermark(),
            'reference' => $q->reference,
            'customerName' => $q->customer?->full_name,
            'route' => $q->placeLabel('origin').($q->isReturn() ? ' ⇄ ' : ' → ').$q->placeLabel('destination'),
            'isReturn' => $q->isReturn(),
            'outboundDate' => $q->outbound_date->format('d M Y'),
            'outboundDay' => $q->outbound_date->format('l'),
            'returnDate' => $q->return_date?->format('d M Y'),
            'returnDay' => $q->return_date?->format('l'),
            'passengers' => $pax,
            'cabin' => $q->cabin_class->label(),
            'quotationDate' => now()->format('d M Y'),
            'validUntil' => $q->valid_until?->format('d M Y, H:i'),
            'message' => $q->message_to_customer,
            'headline' => $headline,
            'options' => $options,
            'multiple' => count($options) > 1,
        ];
    }

    private function optionData(FlightQuotation $q, FlightQuotationOption $o, $logos): array
    {
        $pricing = $this->quotations->pricing($q, $o);
        $cur = $o->currency;
        $legs = [];
        foreach ($this->quotations->itinerary($o) as $direction => $leg) {
            $first = $leg['segments'][0]['model'];
            $legs[] = [
                'title' => $direction === 'outbound' ? 'Outbound' : 'Return',
                'date' => $first->departure_at->format('l, d F Y'),
                'travelTime' => QuotationService::minutesLabel($leg['travel_minutes']),
                'stops' => $leg['stops'] === 0 ? 'Direct' : $leg['stops'].' stop'.($leg['stops'] > 1 ? 's' : ''),
                'segments' => array_map(function ($row) use ($logos, $o, $q) {
                    $s = $row['model'];

                    return [
                        'layover' => $row['layover'] !== null ? QuotationService::minutesLabel(max(0, $row['layover'])) : null,
                        'layoverAt' => Airports::city($s->from_code) ? Airports::city($s->from_code).' ('.$s->from_code.')' : $s->from_code,
                        'depTime' => $s->departure_at->format('H:i'),
                        'depDate' => $s->departure_at->format('d F Y'),
                        'depPlace' => $this->place($s->from_code, $s->from_name),
                        'depTerminal' => $s->departure_terminal,
                        'arrTime' => $s->arrival_at?->format('H:i'),
                        'arrDate' => $s->arrival_at?->format('d F Y'),
                        'arrPlace' => $this->place($s->to_code, $s->to_name),
                        'arrTerminal' => $s->arrival_terminal,
                        'nextDay' => $s->arrival_at && $s->arrival_at->format('Y-m-d') !== $s->departure_at->format('Y-m-d'),
                        'duration' => QuotationService::minutesLabel($row['duration']),
                        'airline' => $s->airline_name,
                        'logo' => $s->airline_id ? ($logos[$s->airline_id] ?? null) : null,
                        'flight' => $s->flight_number,
                        'cabin' => ($s->cabin_class ?? $o->cabin_class ?? $q->cabin_class)?->label(),
                        'aircraft' => $s->aircraft,
                        'operatedBy' => $s->operating_airline_name && strcasecmp($s->operating_airline_name, $s->airline_name) !== 0 ? $s->operating_airline_name : null,
                        'baggage' => $s->baggage_note,
                        'notes' => $s->notes,
                    ];
                }, $leg['segments']),
            ];
        }

        $bag = fn (BaggageStatus $status, ?string $allowance, string $label) => [
            'label' => $label,
            'status' => $status->value,
            'text' => match ($status) {
                BaggageStatus::Included => $allowance ?: 'Included',
                BaggageStatus::NotIncluded => 'Not included',
                BaggageStatus::Unknown => 'Not confirmed',
            },
        ];

        $money = fn (?int $c) => self::money($c, $cur);

        return [
            'label' => $o->label,
            'fareFamily' => $o->fare_family,
            'currency' => $cur,
            'legs' => $legs,
            'baggage' => [
                $bag($o->checked_bag_status, $o->checked_bag_allowance, 'Checked baggage'),
                $bag($o->cabin_bag_status, $o->cabin_bag_allowance, 'Cabin baggage'),
                $bag($o->personal_item_status, $o->personal_item_allowance, 'Personal item'),
            ],
            'baggageNotes' => $o->baggage_notes,
            'price' => [
                'party_total' => $money($pricing['party_total']),
                'party_total_cents' => $pricing['party_total'],
            ],
            'conditions' => [
                'refund' => ['state' => $o->refundability->value, 'label' => $o->refundability === Refundability::Unknown ? 'Refund conditions' : $o->refundability->label()],
                'change' => [
                    'state' => $o->change_permitted->value,
                    'fee' => $money(Money::cents($o->change_fee ?? 0) ?: null),
                    'feeIsFree' => $o->change_fee !== null && Money::cents($o->change_fee) === 0,
                    'fareDifference' => $o->fare_difference === YesNoUnknown::Yes ? 'yes' : ($o->fare_difference === YesNoUnknown::No ? 'no' : null),
                ],
                'cancellation' => $o->cancellation_conditions,
                'noShow' => $o->no_show_conditions,
                'other' => $o->other_conditions,
            ],
            'extras' => collect($o->extras ?? [])->filter(fn ($e) => ! empty($e['title']))->map(fn ($e) => [
                'title' => $e['title'],
                'amount' => isset($e['amount']) && $e['amount'] !== '' && $e['amount'] !== null ? self::money(Money::cents($e['amount']), $cur) : null,
                'details' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($e['details'] ?? ''))))),
            ])->values()->all(),
            'priceValidUntil' => $o->price_valid_until?->format('d F Y, H:i'),
            'notes' => $o->notes,
        ];
    }

    private function place(string $code, ?string $name): array
    {
        $city = Airports::city($code);

        return ['code' => $code, 'city' => $city ?? $name ?? $code, 'name' => $name && $name !== $city ? $name : null];
    }
}
