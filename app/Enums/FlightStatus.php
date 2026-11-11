<?php

namespace App\Enums;

enum FlightStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case Delayed = 'delayed';
    case Changed = 'changed';
    case Cancelled = 'cancelled';
    case Flown = 'flown';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Delayed => 'Delayed',
            self::Changed => 'Schedule changed',
            self::Cancelled => 'Cancelled',
            self::Flown => 'Flown',
        };
    }
}
