<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An airline with an optional uploaded logo (used on quotation PDFs). */
class Airline extends Model
{
    use Auditable;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'iata_code', 'icao_code', 'logo_key', 'logo_updated_at', 'is_active'];

    protected $hidden = ['logo_key'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'logo_updated_at' => 'datetime'];
    }

    public function segments(): HasMany
    {
        return $this->hasMany(FlightQuotationSegment::class);
    }

    public function auditBookingId(): ?int
    {
        return null;
    }
}
