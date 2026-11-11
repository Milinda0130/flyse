<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\QuotationStatus;
use App\Enums\TripType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A manual flight quotation for a customer. Never a reservation or a ticket. */
class FlightQuotation extends Model
{
    use Auditable, SoftDeletes;

    protected $attributes = ['status' => 'draft', 'trip_type' => 'round_trip', 'adults' => 1, 'children' => 0, 'infants' => 0, 'cabin_class' => 'economy'];

    protected $fillable = [
        'reference', 'customer_id', 'created_by', 'status', 'trip_type', 'origin_country', 'origin_city', 'origin_code',
        'destination_country', 'destination_city', 'destination_code', 'outbound_date', 'return_date', 'adults', 'children',
        'infants', 'cabin_class', 'currency', 'valid_until', 'requested_airline', 'preferred_departure_time', 'max_stops',
        'requested_baggage', 'customer_requirements', 'message_to_customer', 'internal_notes', 'accepted_option_id',
        'booking_id', 'status_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'trip_type' => TripType::class,
            'cabin_class' => CabinClass::class,
            'outbound_date' => 'date:Y-m-d',
            'return_date' => 'date:Y-m-d',
            'valid_until' => 'datetime',
            'status_changed_at' => 'datetime',
            'adults' => 'integer',
            'children' => 'integer',
            'infants' => 'integer',
            'max_stops' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(FlightQuotationOption::class)->orderBy('display_order')->orderBy('id');
    }

    public function pdfs(): HasMany
    {
        // chaperone(): each version knows its quotation without an extra query (its file name uses the reference).
        return $this->hasMany(FlightQuotationPdf::class)->orderByDesc('version')->chaperone('quotation');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(QuotationEmail::class)->orderByDesc('id');
    }

    public function isReturn(): bool
    {
        return $this->trip_type === TripType::RoundTrip;
    }

    public function passengerCount(): int
    {
        return $this->adults + $this->children + $this->infants;
    }

    /** "Stockholm (ARN)" */
    public function placeLabel(string $side): string
    {
        $city = $this->{$side.'_city'};
        $code = $this->{$side.'_code'};

        return $code && strcasecmp($city, $code) !== 0 ? "$city ($code)" : (string) $city;
    }

    /** Quotation activity is not part of a booking's history. */
    public function auditBookingId(): ?int
    {
        return null;
    }

    public function auditIgnored(): array
    {
        return ['status_changed_at'];
    }
}
