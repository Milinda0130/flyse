<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'passenger_id' => $this->passenger_id,
            'issue_id' => $this->issue_id,
            'booking_flight_id' => $this->booking_flight_id,
            'notes' => $this->notes,
            'ticket_numbers' => $this->whenLoaded('linkedTickets', fn () => $this->linkedTickets->pluck('ticket_number')->values()),
            'document_type' => $this->document_type->value,
            // Alternative ticket PDFs only (null for official agency documents).
            'source_type' => $this->source_type?->value,
            'source_label' => $this->source_type?->label(),
            'source_description' => $this->source_description,
            'verification_status' => $this->verification_status?->value,
            'verification_label' => $this->verification_status?->label(),
            'verified_by' => new UserSummaryResource($this->whenLoaded('verifier')),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verification_note' => $this->verification_note,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploaded_by' => new UserSummaryResource($this->whenLoaded('uploader')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
