<?php

namespace App\Models;

use App\Enums\PassengerType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Passenger extends Model
{
    use Auditable, HasFactory;

    /** Mirrors the database column defaults so new instances are complete. */
    protected $attributes = ['passenger_type' => 'adult'];

    protected $fillable = [
        'booking_id', 'full_name_as_document', 'passenger_type', 'date_of_birth', 'nationality',
        'passport_number', 'passport_expiry', 'phone', 'notes',
    ];

    protected $hidden = ['passport_number'];

    protected function casts(): array
    {
        return [
            'passenger_type' => PassengerType::class,
            'date_of_birth' => 'date:Y-m-d',
            'passport_expiry' => 'date:Y-m-d',
            'passport_number' => 'encrypted',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
