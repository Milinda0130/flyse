<?php

namespace App\Models;

use App\Enums\EmailStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to email a quotation PDF to the customer. */
class QuotationEmail extends Model
{
    use Auditable;

    protected $fillable = [
        'flight_quotation_id', 'customer_id', 'flight_quotation_pdf_id', 'recipient', 'recipient_is_override', 'subject',
        'attachment_name', 'size_bytes', 'status', 'mailer', 'message_id', 'failure_reason', 'attempts', 'idempotency_key',
        'requested_by', 'requested_at', 'sent_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'recipient_is_override' => 'boolean',
            'requested_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(FlightQuotation::class, 'flight_quotation_id')->withTrashed();
    }

    public function pdf(): BelongsTo
    {
        return $this->belongsTo(FlightQuotationPdf::class, 'flight_quotation_pdf_id')->with('quotation:id,reference');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function auditIgnored(): array
    {
        return ['attempts', 'mailer', 'message_id', 'updated_at'];
    }

    public function auditBookingId(): ?int
    {
        return null;
    }
}
