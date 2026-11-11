<?php

namespace App\Enums;

enum PaymentStatus: string
{
    use HasOptions;

    case Completed = 'completed';
    case Pending = 'pending';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Pending => 'Pending',
            self::Voided => 'Voided',
        };
    }
}
