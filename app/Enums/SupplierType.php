<?php

namespace App\Enums;

enum SupplierType: string
{
    use HasOptions;

    case Airline = 'airline';
    case Consolidator = 'consolidator';
    case Agency = 'agency';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Airline => 'Airline',
            self::Consolidator => 'Consolidator',
            self::Agency => 'Travel agency',
            self::Other => 'Other',
        };
    }
}
