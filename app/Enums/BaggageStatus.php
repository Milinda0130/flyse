<?php

namespace App\Enums;

/** Whether a baggage item is part of the fare. "Unknown" is never shown as "Not included". */
enum BaggageStatus: string
{
    use HasOptions;

    case Included = 'included';
    case NotIncluded = 'not_included';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Included => 'Included',
            self::NotIncluded => 'Not included',
            self::Unknown => 'Not confirmed',
        };
    }
}
