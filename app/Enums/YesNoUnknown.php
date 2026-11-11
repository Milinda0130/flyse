<?php

namespace App\Enums;

/** A fare condition that is known to be allowed, not allowed, or not yet confirmed. */
enum YesNoUnknown: string
{
    use HasOptions;

    case Yes = 'yes';
    case No = 'no';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Yes',
            self::No => 'No',
            self::Unknown => 'Not confirmed',
        };
    }
}
