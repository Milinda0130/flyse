<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'segment_order' => $this->segment_order,
            'airline' => $this->airline,
            'flight_number' => $this->flight_number,
            'origin_code' => $this->origin_code,
            'destination_code' => $this->destination_code,
            'departure_at' => $this->departure_at?->format('Y-m-d\TH:i'),
            'arrival_at' => $this->arrival_at?->format('Y-m-d\TH:i'),
            'origin_timezone' => $this->origin_timezone,
            'destination_timezone' => $this->destination_timezone,
            'cabin_class' => $this->cabin_class->value,
            'baggage_allowance' => $this->baggage_allowance,
            'status' => $this->status->value,
            'notes' => $this->notes,
        ];
    }
}
