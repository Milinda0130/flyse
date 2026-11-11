<?php

namespace App\Enums;

enum TicketStatus: string
{
    use HasOptions;

    case Issued = 'issued';
    case Changed = 'changed';
    case Voided = 'voided';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
    case Used = 'used';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Changed => 'Changed / Reissued',
            self::Voided => 'Voided',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
            self::Used => 'Used',
        };
    }
}
