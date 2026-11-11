<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'passenger_id' => $this->passenger_id,
            'booking_flight_id' => $this->booking_flight_id,
            'ticket_number' => $this->ticket_number,
            'airline_pnr' => $this->airline_pnr,
            'e_ticket_document_id' => $this->e_ticket_document_id,
            'e_ticket' => $this->whenLoaded('eTicket', fn () => $this->eTicket ? [
                'id' => $this->eTicket->id,
                'original_name' => $this->eTicket->original_name,
            ] : null),
            'issued_at' => $this->issued_at?->format('Y-m-d'),
            'status' => $this->status->value,
            'fare_conditions' => $this->fare_conditions,
        ];
    }
}
