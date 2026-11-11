<?php

namespace App\Enums;

/** Which leg of the journey a quoted flight segment belongs to. */
enum SegmentDirection: string
{
    use HasOptions;

    case Outbound = 'outbound';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Outbound => 'Outbound',
            self::Return => 'Return',
        };
    }
}
