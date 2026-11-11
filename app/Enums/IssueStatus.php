<?php

namespace App\Enums;

enum IssueStatus: string
{
    use HasOptions;

    case Open = 'open';
    case Investigating = 'investigating';
    case WaitingAirline = 'waiting_airline';
    case WaitingCustomer = 'waiting_customer';
    case ActionRequired = 'action_required';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Investigating => 'In Progress',
            self::WaitingAirline => 'Waiting for Airline/Supplier',
            self::WaitingCustomer => 'Waiting for Customer',
            self::ActionRequired => 'Action Required',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** @return list<self> */
    public static function openStatuses(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => ! $s->isClosed()));
    }

    public function isClosed(): bool
    {
        return $this === self::Resolved || $this === self::Closed;
    }
}
