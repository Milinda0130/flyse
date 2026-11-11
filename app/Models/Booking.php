<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\TripType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    /** Mirrors the database column defaults so new instances are complete. */
    protected $attributes = ['status' => 'inquiry', 'trip_type' => 'one_way', 'passenger_count' => 1];

    protected $fillable = [
        'booking_code', 'customer_id', 'supplier_id', 'pnr', 'booking_date', 'currency', 'status',
        'trip_type', 'origin', 'destination', 'departure_date', 'return_date', 'cabin_class', 'passenger_count',
        'payment_due_date', 'ticketing_deadline', 'selling_total', 'service_fee', 'supplier_total',
        'next_action', 'notes', 'cancellation_reason', 'cancelled_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date:Y-m-d',
            'payment_due_date' => 'date:Y-m-d',
            'ticketing_deadline' => 'datetime:Y-m-d\TH:i',
            'status' => BookingStatus::class,
            'trip_type' => TripType::class,
            'departure_date' => 'date:Y-m-d',
            'return_date' => 'date:Y-m-d',
            'passenger_count' => 'integer',
            'cancelled_at' => 'datetime',
            'selling_total' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'supplier_total' => 'decimal:2',
        ];
    }

    public function auditIgnored(): array
    {
        return ['updated_by'];
    }

    public function auditBookingId(): ?int
    {
        return $this->id;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(Passenger::class)->orderBy('id');
    }

    public function flights(): HasMany
    {
        return $this->hasMany(BookingFlight::class)->orderBy('segment_order')->orderBy('departure_at');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->latest()->orderByDesc('id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(BookingIssue::class)->latest('reported_at')->orderByDesc('id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(BookingEmail::class)->latest('requested_at')->orderByDesc('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('transaction_date')->orderByDesc('id');
    }

    /** The trip the customer asked for, e.g. "CMB → ARN" (before or without a confirmed itinerary). */
    public function requestedRoute(): ?string
    {
        if (! $this->origin && ! $this->destination) {
            return null;
        }

        return ($this->origin ?: '?').' → '.($this->destination ?: '?');
    }

    /** Routing such as "CMB-DOH-ARN-DOH-CMB" from ordered segments; gaps shown with "/". */
    public function routeString(): string
    {
        $route = '';
        $last = null;
        foreach ($this->flights as $flight) {
            if ($last === null) {
                $route = $flight->origin_code;
            } elseif ($last !== $flight->origin_code) {
                $route .= '/'.$flight->origin_code;
            }
            $route .= '-'.$flight->destination_code;
            $last = $flight->destination_code;
        }

        return $route;
    }
}
