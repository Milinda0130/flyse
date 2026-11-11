<?php

namespace App\Http\Resources;

use App\Services\QuotationService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A quotation with its options, segments and server-calculated prices.
 * Supplier cost, margin and internal notes only for users allowed to see costs.
 */
class QuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(QuotationService::class);
        $costs = $request->user()?->can('view-costs') ?? false;
        $m = fn (?int $c) => $c === null ? null : Money::format($c);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : ['id' => $this->customer->id, 'full_name' => $this->customer->full_name, 'email' => $this->customer->email, 'phone' => $this->customer->phone]),
            'trip_type' => $this->trip_type->value,
            'origin_country' => $this->origin_country,
            'origin_city' => $this->origin_city,
            'origin_code' => $this->origin_code,
            'destination_country' => $this->destination_country,
            'destination_city' => $this->destination_city,
            'destination_code' => $this->destination_code,
            'route' => $this->placeLabel('origin').($this->isReturn() ? ' ⇄ ' : ' → ').$this->placeLabel('destination'),
            'outbound_date' => $this->outbound_date?->format('Y-m-d'),
            'return_date' => $this->return_date?->format('Y-m-d'),
            'adults' => $this->adults,
            'children' => $this->children,
            'infants' => $this->infants,
            'cabin_class' => $this->cabin_class->value,
            'currency' => $this->currency,
            'valid_until' => $this->valid_until?->format('Y-m-d\TH:i'),
            'requested_airline' => $this->requested_airline,
            'preferred_departure_time' => $this->preferred_departure_time,
            'max_stops' => $this->max_stops,
            'requested_baggage' => $this->requested_baggage,
            'customer_requirements' => $this->customer_requirements,
            'message_to_customer' => $this->message_to_customer,
            'internal_notes' => $this->when($costs, $this->internal_notes),
            'accepted_option_id' => $this->accepted_option_id,
            'booking' => $this->whenLoaded('booking', fn () => $this->booking ? ['id' => $this->booking->id, 'booking_code' => $this->booking->booking_code] : null),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'options' => $this->whenLoaded('options', fn () => $this->options->map(function ($o) use ($service, $costs, $m) {
                $p = $service->pricing($this->resource, $o);
                $legs = $service->itinerary($o);

                return [
                    ...collect($o->only([
                        'id', 'label', 'fare_family', 'currency', 'adult_fare', 'adult_tax', 'child_fare', 'child_tax', 'infant_fare', 'infant_tax',
                        'other_charges', 'checked_bag_allowance', 'cabin_bag_allowance', 'personal_item_allowance', 'baggage_notes', 'change_fee',
                        'cancellation_conditions', 'no_show_conditions', 'other_conditions', 'notes',
                    ]))->all(),
                    'cabin_class' => $o->cabin_class?->value,
                    'checked_bag_status' => $o->checked_bag_status->value,
                    'cabin_bag_status' => $o->cabin_bag_status->value,
                    'personal_item_status' => $o->personal_item_status->value,
                    'refundability' => $o->refundability->value,
                    'change_permitted' => $o->change_permitted->value,
                    'fare_difference' => $o->fare_difference->value,
                    'extras' => $o->extras ?? [],
                    'price_valid_until' => $o->price_valid_until?->format('Y-m-d\TH:i'),
                    'supplier_cost' => $this->when($costs, $o->supplier_cost),
                    'internal_notes' => $this->when($costs, $o->internal_notes),
                    'pricing' => [
                        'currency' => $p['currency'],
                        'rows' => array_map(fn ($r) => ['type' => $r['type'], 'title' => $r['title'], 'count' => $r['count'], 'per_person' => $m($r['per_person']), 'subtotal' => $m($r['subtotal'])], $p['rows']),
                        'adult_per_person' => $m($p['adult_per_person']),
                        'party_total' => $m($p['party_total']),
                        'margin' => $this->when($costs, $m($p['margin'])),
                    ],
                    'legs' => collect($legs)->map(fn ($leg) => [
                        'travel_time' => QuotationService::minutesLabel($leg['travel_minutes']),
                        'stops' => $leg['stops'],
                    ])->all(),
                    'segments' => $o->segments->map(function ($s) use ($service, $legs) {
                        $row = collect($legs[$s->direction->value]['segments'] ?? [])->first(fn ($r) => $r['model']->id === $s->id);

                        return [
                            ...collect($s->only([
                                'id', 'segment_order', 'airline_id', 'airline_name', 'operating_airline_id', 'operating_airline_name', 'flight_number',
                                'from_code', 'from_name', 'to_code', 'to_name', 'departure_tz', 'arrival_tz', 'duration_minutes',
                                'departure_terminal', 'arrival_terminal', 'aircraft', 'baggage_note', 'notes',
                            ]))->all(),
                            'direction' => $s->direction->value,
                            'cabin_class' => $s->cabin_class?->value,
                            'departure_at' => $s->departure_at?->format('Y-m-d\TH:i'),
                            'arrival_at' => $s->arrival_at?->format('Y-m-d\TH:i'),
                            'duration' => QuotationService::minutesLabel($service->segmentMinutes($s)),
                            'layover' => $row && $row['layover'] !== null ? QuotationService::minutesLabel(max(0, $row['layover'])) : null,
                        ];
                    })->values()->all(),
                ];
            })->values()->all()),
        ];
    }
}
