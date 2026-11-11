<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'booking' => $this->whenLoaded('booking', fn () => [
                'id' => $this->booking->id,
                'booking_code' => $this->booking->booking_code,
                'pnr' => $this->booking->pnr,
                'customer' => $this->booking->relationLoaded('customer') ? $this->booking->customer?->full_name : null,
            ]),
            'booking_flight_id' => $this->booking_flight_id,
            'flight' => new FlightResource($this->whenLoaded('flight')),
            'issue_type' => $this->issue_type->value,
            'source' => $this->source->value,
            'severity' => $this->severity->value,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'description' => $this->description,
            'next_action' => $this->next_action,
            'reported_at' => $this->reported_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'is_overdue' => $this->due_at !== null && ! $this->status->isClosed() && $this->due_at->isPast(),
            'customer_notified' => $this->customer_notified,
            'resolution_notes' => $this->resolution_notes,
            'refund_reference' => $this->refund_reference,
            'assigned_to' => $this->assigned_to,
            'assignee' => new UserSummaryResource($this->whenLoaded('assignee')),
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'updates' => IssueUpdateResource::collection($this->whenLoaded('updates')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'flight_history' => AuditLogResource::collection($this->whenLoaded('flightHistory')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
