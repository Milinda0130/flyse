<?php

namespace App\Models;

use App\Enums\EmailStatus;
use App\Enums\EmailType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ticket-email attempt for a booking (also appears in the booking's activity history). */
class BookingEmail extends Model
{
    use Auditable;

    protected $fillable = [
        'booking_id', 'email_type', 'customer_id', 'recipient', 'recipient_is_override', 'reviewed_confirmed', 'official_pending', 'subject', 'attachments', 'attachment_count', 'total_bytes',
        'status', 'mailer', 'message_id', 'failure_reason', 'attempts', 'idempotency_key', 'requested_by',
        'requested_at', 'sent_at', 'failed_at',
    ];

    protected $attributes = ['email_type' => 'official', 'reviewed_confirmed' => false, 'official_pending' => false];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'recipient_is_override' => 'boolean',
            'reviewed_confirmed' => 'boolean',
            'official_pending' => 'boolean',
            'email_type' => EmailType::class,
            'status' => EmailStatus::class,
            'requested_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** Only status changes are worth an activity entry, not every internal counter. */
    public function auditIgnored(): array
    {
        return ['attempts', 'mailer', 'message_id', 'updated_at'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return list<int> */
    public function documentIds(): array
    {
        return array_map('intval', array_column($this->attachments ?? [], 'document_id'));
    }
}
