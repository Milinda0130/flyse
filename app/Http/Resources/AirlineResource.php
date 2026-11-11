<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AirlineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'iata_code' => $this->iata_code,
            'icao_code' => $this->icao_code,
            'is_active' => $this->is_active,
            'has_logo' => $this->logo_key !== null,
            // Changes whenever the logo changes, so cached logo images refresh.
            'logo_version' => $this->logo_updated_at?->timestamp,
            'segments_count' => $this->whenCounted('segments'),
        ];
    }
}
