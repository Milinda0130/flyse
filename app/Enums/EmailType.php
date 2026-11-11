<?php

namespace App\Enums;

/** Which ticket-email workflow produced an email. */
enum EmailType: string
{
    use HasOptions;

    /** E-ticket PDFs the agency received through its normal process. */
    case Official = 'official';
    /** A separately uploaded / customer-provided ticket PDF, sent while the official one is unavailable. */
    case Alternative = 'alternative';

    public function label(): string
    {
        return match ($this) {
            self::Official => 'Official ticket',
            self::Alternative => 'Alternative ticket PDF',
        };
    }
}
