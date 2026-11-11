<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A generated quotation PDF. Versions are kept, so the exact file that was emailed stays available. */
class FlightQuotationPdf extends Model
{
    protected $fillable = ['flight_quotation_id', 'version', 'storage_key', 'size_bytes', 'content_hash', 'generated_by'];

    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'size_bytes' => 'integer'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(FlightQuotation::class, 'flight_quotation_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function filename(): string
    {
        $ref = $this->relationLoaded('quotation')
            ? $this->quotation?->reference
            : FlightQuotation::withTrashed()->whereKey($this->flight_quotation_id)->value('reference');
        $ref ??= 'quotation';

        return "Flight-Quotation-$ref".($this->version > 1 ? "-v{$this->version}" : '').'.pdf';
    }
}
