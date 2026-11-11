<?php

namespace App\Enums;

enum TripType: string
{
    use HasOptions;

    case OneWay = 'one_way';
    case RoundTrip = 'round_trip';

    public function label(): string
    {
        return match ($this) {
            self::OneWay => 'One-way',
            self::RoundTrip => 'Round-trip',
        };
    }
}
