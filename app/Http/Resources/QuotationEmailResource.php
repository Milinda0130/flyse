<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationEmailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipient' => $this->recipient,
            'recipient_is_override' => $this->recipient_is_override,
            'subject' => $this->subject,
            'attachment_name' => $this->attachment_name,
            'size_bytes' => $this->size_bytes,
            'pdf_id' => $this->flight_quotation_pdf_id,
            'pdf_version' => $this->whenLoaded('pdf', fn () => $this->pdf?->version),
            'status' => $this->status->value,
            'mailer' => $this->mailer,
            'delivered_by_provider' => $this->mailer !== null && ! in_array($this->mailer, ['log', 'array'], true),
            'failure_reason' => $this->failure_reason,
            'attempts' => $this->attempts,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
        ];
    }
}
