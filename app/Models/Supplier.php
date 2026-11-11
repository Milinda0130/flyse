<?php

namespace App\Models;

use App\Enums\SupplierType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use Auditable, HasFactory;

    /** Mirrors the database column defaults so new instances are complete. */
    protected $attributes = ['supplier_type' => 'consolidator', 'is_active' => true];

    protected $fillable = ['name', 'supplier_type', 'contact_person', 'phone', 'email', 'notes', 'is_active'];

    protected function casts(): array
    {
        return [
            'supplier_type' => SupplierType::class,
            'is_active' => 'boolean',
        ];
    }

    public function auditBookingId(): ?int
    {
        return null;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
