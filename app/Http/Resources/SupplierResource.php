<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'supplier_type' => $this->supplier_type->value,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'bookings_count' => $this->whenCounted('bookings'),
        ];
    }
}
