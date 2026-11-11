<?php

namespace App\Enums;

enum IssueType: string
{
    use HasOptions;

    case CustomerCancellation = 'customer_cancellation';
    case AirlineCancellation = 'airline_cancellation';
    case Delay = 'delay';
    case ScheduleChange = 'schedule_change';
    case RouteChange = 'route_change';
    case Reschedule = 'reschedule';
    case Refund = 'refund';
    case TicketingDeadline = 'ticketing_deadline';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomerCancellation => 'Booking cancellation (customer)',
            self::AirlineCancellation => 'Flight cancellation (airline)',
            self::Delay => 'Delay',
            self::ScheduleChange => 'Schedule change',
            self::RouteChange => 'Route change',
            self::Reschedule => 'Customer reschedule request',
            self::Refund => 'Refund pending',
            self::TicketingDeadline => 'Ticketing deadline',
            self::Other => 'Other',
        };
    }
}
