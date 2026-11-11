<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\MasksPassport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PassengerResource extends JsonResource
{
    use MasksPassport;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'full_name_as_document' => $this->full_name_as_document,
            'passenger_type' => $this->passenger_type->value,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'nationality' => $this->nationality,
            'passport_number' => $this->passport($request),
            'passport_expiry' => $this->passport_expiry?->format('Y-m-d'),
            'phone' => $this->phone,
            'notes' => $this->notes,
        ];
    }
}
