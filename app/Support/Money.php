<?php

namespace App\Support;

/**
 * Money arithmetic in integer cents to avoid floating-point drift.
 * DECIMAL(14,2) values fit comfortably within PHP's 64-bit integers.
 */
final class Money
{
    public static function cents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }

    public static function formatOrNull(?int $cents): ?string
    {
        return $cents === null ? null : self::format($cents);
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
