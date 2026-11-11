<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\MasksPassport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** List view never includes passport data; detail view includes it according to role. */
class CustomerResource extends JsonResource
{
    use MasksPassport;

    public bool $detail = false;

    public function detailed(): static
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'nationality' => $this->nationality,
            'bookings_count' => $this->whenCounted('bookings'),
            'created_at' => $this->created_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            $this->mergeWhen($this->detail, fn () => [
                'address' => $this->address,
                'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
                'passport_number' => $this->passport($request),
                'passport_expiry' => $this->passport_expiry?->format('Y-m-d'),
                'notes' => $this->notes,
            ]),
        ];
    }
}
