<?php

namespace App\Enums;

/** Lifecycle of a flight quotation (separate from bookings and tickets). */
enum QuotationStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Ready = 'ready';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ready => 'Ready to send',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Converted => 'Converted to booking',
        };
    }
}
