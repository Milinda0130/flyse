<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A date whose year is 1900-2100. PHP accepts dates like "12003-02-08" (a mistyped extra digit in
 * the year), which MySQL then rejects with a database error; this turns it into a field message.
 */
class ReasonableDate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }
        if (! preg_match('/^(\d{4})-\d{2}-\d{2}/', $value, $m) || (int) $m[1] < 1900 || (int) $m[1] > 2100) {
            $fail('Enter a valid date (year between 1900 and 2100).');
        }
    }
}
