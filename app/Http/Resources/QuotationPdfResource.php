<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationPdfResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'filename' => $this->filename(),
            'size_bytes' => $this->size_bytes,
            'generated_by' => $this->whenLoaded('generator', fn () => $this->generator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
