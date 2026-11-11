<?php

namespace App\Models;

use App\Enums\DocumentSource;
use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'booking_id', 'passenger_id', 'issue_id', 'document_type', 'original_name', 'storage_key',
        'mime_type', 'size_bytes', 'notes', 'booking_flight_id', 'uploaded_by',
        'source_type', 'source_description', 'verification_status', 'verified_by', 'verified_at', 'verification_note',
    ];

    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'size_bytes' => 'integer',
            'source_type' => DocumentSource::class,
            'verification_status' => VerificationStatus::class,
            'verified_at' => 'datetime',
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

    public function issue(): BelongsTo
    {
        return $this->belongsTo(BookingIssue::class, 'issue_id');
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(BookingFlight::class, 'booking_flight_id');
    }

    /** Tickets that use this document as their e-ticket. */
    public function linkedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'e_ticket_document_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isAlternativeTicket(): bool
    {
        return $this->document_type === DocumentType::AlternativeTicket;
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
