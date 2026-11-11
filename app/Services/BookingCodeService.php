<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generates sequential booking codes such as BK-2026-0001.
 * Uses a per-year counter row locked for update, so concurrent requests never
 * receive the same code. Must be called inside a database transaction.
 */
class BookingCodeService
{
    public function next(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        DB::table('booking_code_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);

        $current = DB::table('booking_code_sequences')->where('year', $year)->lockForUpdate()->value('last_number');
        $next = $current + 1;
        DB::table('booking_code_sequences')->where('year', $year)->update(['last_number' => $next]);

        return sprintf('BK-%d-%04d', $year, $next);
    }
}
