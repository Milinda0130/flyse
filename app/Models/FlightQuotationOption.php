<?php

namespace App\Models;

use App\Enums\BaggageStatus;
use App\Enums\CabinClass;
use App\Enums\Refundability;
use App\Enums\YesNoUnknown;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One alternative offered in a quotation (its own flights, price, baggage and conditions). */
class FlightQuotationOption extends Model
{
    protected $attributes = [
        'checked_bag_status' => 'unknown', 'cabin_bag_status' => 'unknown', 'personal_item_status' => 'unknown',
        'refundability' => 'unknown', 'change_permitted' => 'unknown', 'fare_difference' => 'unknown', 'display_order' => 0,
    ];

    protected $fillable = [
        'flight_quotation_id', 'display_order', 'label', 'fare_family', 'cabin_class', 'currency',
        'adult_fare', 'adult_tax', 'child_fare', 'child_tax', 'infant_fare', 'infant_tax', 'other_charges', 'supplier_cost',
        'checked_bag_status', 'checked_bag_allowance', 'cabin_bag_status', 'cabin_bag_allowance', 'personal_item_status',
        'personal_item_allowance', 'baggage_notes', 'refundability', 'change_permitted', 'change_fee', 'fare_difference',
        'cancellation_conditions', 'no_show_conditions', 'other_conditions', 'extras', 'price_valid_until', 'notes', 'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'cabin_class' => CabinClass::class,
            'checked_bag_status' => BaggageStatus::class,
            'cabin_bag_status' => BaggageStatus::class,
            'personal_item_status' => BaggageStatus::class,
            'refundability' => Refundability::class,
            'change_permitted' => YesNoUnknown::class,
            'fare_difference' => YesNoUnknown::class,
            'price_valid_until' => 'datetime',
        ];
    }

    /** Optional extras as a list; tolerates a value that was JSON-encoded twice by an earlier duplicate. */
    protected function extras(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $data = is_string($value) ? json_decode($value, true) : $value;
                if (is_string($data)) {
                    $data = json_decode($data, true);
                }

                return is_array($data) ? array_values($data) : [];
            },
            set: fn ($value) => $value === null ? null : json_encode(is_string($value) ? (json_decode($value, true) ?? []) : array_values($value)),
        );
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(FlightQuotation::class, 'flight_quotation_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(FlightQuotationSegment::class)->orderByRaw("CASE WHEN direction = 'outbound' THEN 0 ELSE 1 END")->orderBy('segment_order');
    }
}
