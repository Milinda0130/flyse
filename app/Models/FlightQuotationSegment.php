<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\SegmentDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One flight of a quoted option. Times are local airport times. */
class FlightQuotationSegment extends Model
{
    protected $attributes = ['direction' => 'outbound', 'segment_order' => 1];

    protected $fillable = [
        'flight_quotation_option_id', 'direction', 'segment_order', 'airline_id', 'airline_name', 'operating_airline_id',
        'operating_airline_name', 'flight_number', 'from_code', 'from_name', 'to_code', 'to_name', 'departure_at', 'arrival_at',
        'departure_tz', 'arrival_tz', 'duration_minutes', 'departure_terminal', 'arrival_terminal', 'aircraft', 'cabin_class',
        'baggage_note', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'direction' => SegmentDirection::class,
            'cabin_class' => CabinClass::class,
            'departure_at' => 'datetime:Y-m-d\TH:i',
            'arrival_at' => 'datetime:Y-m-d\TH:i',
            'duration_minutes' => 'integer',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function operatingAirline(): BelongsTo
    {
        return $this->belongsTo(Airline::class, 'operating_airline_id');
    }
}
