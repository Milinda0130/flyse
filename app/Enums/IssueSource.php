<?php

namespace App\Enums;

enum IssueSource: string
{
    use HasOptions;

    case Airline = 'airline';
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Airline => 'Airline',
            self::Customer => 'Customer',
            self::Supplier => 'Supplier',
            self::Internal => 'Internal',
        };
    }
}
