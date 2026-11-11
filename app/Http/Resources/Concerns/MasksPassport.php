<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Request;

trait MasksPassport
{
    /** Full number for staff/owner, masked for read-only users, null when not stored. */
    protected function passport(Request $request): ?string
    {
        $number = $this->resource->passport_number;
        if ($number === null || $number === '') {
            return null;
        }
        if ($request->user()?->can('view-passport')) {
            return $number;
        }

        return str_repeat('•', max(strlen($number) - 2, 3)).substr($number, -2);
    }
}
