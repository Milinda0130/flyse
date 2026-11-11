<?php

namespace App\Models;

use App\Enums\IssueSeverity;
use App\Enums\IssueSource;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingIssue extends Model
{
    use Auditable, HasFactory;

    /** Mirrors the database column defaults so new instances are complete. */
    protected $attributes = ['severity' => 'medium', 'status' => 'open', 'customer_notified' => false];

    protected $fillable = [
        'booking_id', 'booking_flight_id', 'issue_type', 'source', 'severity', 'status', 'summary',
        'description', 'next_action', 'reported_at', 'due_at', 'resolved_at', 'customer_notified',
        'resolution_notes', 'refund_reference', 'assigned_to', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_type' => IssueType::class,
            'source' => IssueSource::class,
            'severity' => IssueSeverity::class,
            'status' => IssueStatus::class,
            'reported_at' => 'datetime',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'customer_notified' => 'boolean',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(BookingFlight::class, 'booking_flight_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(IssueUpdate::class, 'issue_id')->latest('created_at')->orderByDesc('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'issue_id')->latest()->orderByDesc('id');
    }
}
