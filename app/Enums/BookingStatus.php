<?php

namespace App\Enums;

enum BookingStatus: string
{
    use HasOptions;

    case Inquiry = 'inquiry';
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Ticketed = 'ticketed';
    case Changed = 'changed';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Inquiry => 'Inquiry',
            self::PendingPayment => 'Pending Payment',
            self::Confirmed => 'Reservation Confirmed',
            self::Ticketed => 'Ticketed',
            self::Changed => 'Changed',
            self::OnHold => 'On Hold',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    /**
     * Statuses counted as sales in reports. Inquiries are not sales yet and
     * cancelled bookings are reported separately (cancellation policy to be
     * confirmed with the owner).
     *
     * @return list<self>
     */
    public static function salesStatuses(): array
    {
        return [self::PendingPayment, self::Confirmed, self::Ticketed, self::Changed, self::OnHold, self::Completed];
    }
}
