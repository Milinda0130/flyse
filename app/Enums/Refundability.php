<?php

namespace App\Enums;

/** Refund conditions of a fare, as entered from the fare rules. */
enum Refundability: string
{
    use HasOptions;

    case Refundable = 'refundable';
    case NonRefundable = 'non_refundable';
    case Partial = 'partially_refundable';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Refundable => 'Refundable',
            self::NonRefundable => 'Non-refundable',
            self::Partial => 'Partially refundable',
            self::Unknown => 'Not confirmed',
        };
    }
}
