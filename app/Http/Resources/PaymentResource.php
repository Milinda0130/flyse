<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'booking' => $this->whenLoaded('booking', fn () => [
                'id' => $this->booking->id,
                'booking_code' => $this->booking->booking_code,
            ]),
            'direction' => $this->direction->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'transaction_reference' => $this->transaction_reference,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
