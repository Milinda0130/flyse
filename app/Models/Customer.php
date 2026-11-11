<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'full_name', 'phone', 'email', 'address', 'date_of_birth', 'nationality',
        'passport_number', 'passport_expiry', 'notes', 'created_by',
    ];

    protected $hidden = ['passport_number'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'passport_expiry' => 'date:Y-m-d',
            'passport_number' => 'encrypted',
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
