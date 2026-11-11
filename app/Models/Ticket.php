<?php

namespace App\Models;

use App\Enums\TicketStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    use Auditable, HasFactory;

    /** Mirrors the database column defaults so new instances are complete. */
    protected $attributes = ['status' => 'issued'];

    protected $fillable = ['booking_id', 'passenger_id', 'booking_flight_id', 'ticket_number', 'airline_pnr', 'issued_at', 'status', 'fare_conditions', 'e_ticket_document_id'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date:Y-m-d',
            'status' => TicketStatus::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    /** The stored e-ticket file; one document may cover several passengers' tickets. Archived documents do not count. */
    public function eTicket(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'e_ticket_document_id');
    }

    /** Tickets that still represent a valid issued ticket. */
    public function isActive(): bool
    {
        return in_array($this->status, [TicketStatus::Issued, TicketStatus::Changed, TicketStatus::Used], true);
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(BookingFlight::class, 'booking_flight_id');
    }
}
