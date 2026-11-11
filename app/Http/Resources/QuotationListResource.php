<?php

namespace App\Http\Resources;

use App\Services\QuotationPdfService;
use App\Services\QuotationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of the quotations list. */
class QuotationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(QuotationService::class);
        $prices = $this->options->map(fn ($o) => ['currency' => $o->currency, 'cents' => $service->pricing($this->resource, $o)['adult_per_person']])
            ->filter(fn ($p) => $p['cents'] !== null);
        // A range only makes sense within one currency.
        $range = null;
        if ($prices->isNotEmpty() && $prices->pluck('currency')->unique()->count() === 1) {
            $min = $prices->min('cents');
            $max = $prices->max('cents');
            $cur = $prices->first()['currency'];
            $range = QuotationPdfService::money($min, $cur).($max !== $min ? ' – '.QuotationPdfService::money($max, $cur) : '');
        }
        $lastEmail = $this->emails->first();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'customer' => $this->customer_id ? ['id' => $this->customer_id, 'full_name' => $this->customer?->full_name] : null,
            'origin' => $this->placeLabel('origin'),
            'destination' => $this->placeLabel('destination'),
            'trip_type' => $this->trip_type->value,
            'outbound_date' => $this->outbound_date?->format('Y-m-d'),
            'return_date' => $this->return_date?->format('Y-m-d'),
            'passengers' => $this->passengerCount(),
            'options_count' => $this->options->count(),
            'currency' => $this->currency,
            'price_range' => $range,
            'price_range_mixed' => $prices->pluck('currency')->unique()->count() > 1,
            'valid_until' => $this->valid_until?->toIso8601String(),
            'last_email' => $lastEmail ? ['status' => $lastEmail->status->value, 'at' => ($lastEmail->sent_at ?? $lastEmail->requested_at)?->toIso8601String()] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
