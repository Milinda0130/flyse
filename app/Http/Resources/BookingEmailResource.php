<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingEmailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'email_type' => $this->email_type->value,
            'email_type_label' => $this->email_type->label(),
            'reviewed_confirmed' => $this->reviewed_confirmed,
            'official_pending' => $this->official_pending,
            'recipient' => $this->recipient,
            'recipient_is_override' => $this->recipient_is_override,
            'subject' => $this->subject,
            'attachments' => array_map(fn ($a) => [
                'document_id' => $a['document_id'], 'name' => $a['name'], 'size_bytes' => $a['size_bytes'],
                'passengers' => $a['passengers'] ?? [], 'tickets' => $a['tickets'] ?? [],
                'source_type' => $a['source_type'] ?? null,
            ], $this->attachments ?? []),
            'attachment_count' => $this->attachment_count,
            'total_bytes' => $this->total_bytes,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'mailer' => $this->mailer,
            // "log"/"array" mailers record the message without delivering it (development).
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
