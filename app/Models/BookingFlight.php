<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingFlight extends Model
{
    use Auditable, HasFactory;

    /** Mirrors the database column defaults so new instances are complete. */
    protected $attributes = ['status' => 'scheduled', 'cabin_class' => 'economy'];

    protected $fillable = [
        'booking_id', 'segment_order', 'airline', 'flight_number', 'origin_code', 'destination_code',
        'departure_at', 'arrival_at', 'origin_timezone', 'destination_timezone', 'cabin_class',
        'baggage_allowance', 'status', 'notes',
    ];

    /** Departure/arrival are local airport times, serialised without timezone conversion. */
    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime:Y-m-d\TH:i',
            'arrival_at' => 'datetime:Y-m-d\TH:i',
            'cabin_class' => CabinClass::class,
            'status' => FlightStatus::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
