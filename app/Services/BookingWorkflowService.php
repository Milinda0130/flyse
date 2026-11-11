<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\IssueStatus;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking life cycle: Inquiry -> Reservation Confirmed -> Ticketed -> (Changed) -> Completed,
 * with Cancelled reachable from any active state. Pending Payment and On Hold are side states.
 *
 * Every status change goes through transition(), so the rules hold for every caller:
 * - Ticketed needs every passenger ticketed with a ticket number, a stored e-ticket file,
 *   an airline PNR, and at least one flight segment.
 * - Changed needs a note describing the change.
 * - Cancelled needs a reason; the booking and its money history are kept.
 */
class BookingWorkflowService
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'inquiry' => ['confirmed', 'pending_payment', 'on_hold', 'cancelled'],
        'confirmed' => ['ticketed', 'pending_payment', 'on_hold', 'inquiry', 'cancelled'],
        'pending_payment' => ['confirmed', 'ticketed', 'on_hold', 'cancelled'],
        'on_hold' => ['inquiry', 'confirmed', 'pending_payment', 'cancelled'],
        'ticketed' => ['changed', 'completed', 'cancelled'],
        'changed' => ['ticketed', 'completed', 'cancelled'],
        'completed' => ['changed'],
        'cancelled' => [],
    ];

    /** Statuses a new booking may start in (Ticketed and later need records that cannot exist yet). */
    public const CREATABLE = ['inquiry', 'confirmed', 'pending_payment', 'on_hold'];

    public function __construct(
        private BookingFinanceService $finance,
        private DocumentStorageService $documents,
        private AuditLogService $audit,
    ) {}

    /** @return list<string> */
    public function allowedFrom(BookingStatus $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }

    /**
     * Applies the details supplied for the transition and changes the status.
     *
     * @param  array<string, mixed>  $data  validated StatusTransitionRequest data
     */
    public function transition(Booking $booking, BookingStatus $to, array $data, int $userId): Booking
    {
        $from = $booking->status;
        if ($to === $from) {
            throw ValidationException::withMessages(['status' => "The booking is already {$from->label()}."]);
        }
        if (! in_array($to->value, $this->allowedFrom($from), true)) {
            throw ValidationException::withMessages(['status' => "A {$from->label()} booking cannot be changed to {$to->label()}."]);
        }

        return DB::transaction(function () use ($booking, $from, $to, $data, $userId) {
            // 1. Save the details that came with this step (audited like any other edit).
            $details = array_intersect_key($data, array_flip([
                'supplier_id', 'pnr', 'ticketing_deadline', 'payment_due_date', 'selling_total', 'service_fee',
                'supplier_total', 'next_action',
            ]));
            if ($to === BookingStatus::Cancelled) {
                $details['cancellation_reason'] = $data['reason'];
                $details['cancelled_at'] = now();
            }
            if ($details) {
                $booking->fill([...$details, 'updated_by' => $userId])->save();
            }

            // 2. Check what the new status requires, against the saved data.
            if ($to === BookingStatus::Ticketed) {
                $problems = $this->ticketingProblems($booking->fresh());
                if ($problems) {
                    throw ValidationException::withMessages(['status' => $problems]);
                }
            }

            // 3. Optional pending refund recorded with a cancellation.
            if ($to === BookingStatus::Cancelled && ! empty($data['customer_refund_amount'])) {
                Payment::create([
                    'booking_id' => $booking->id,
                    'direction' => PaymentDirection::CustomerRefund,
                    'amount' => $data['customer_refund_amount'],
                    'currency' => $booking->currency,
                    'method' => $data['refund_method'] ?? 'To be confirmed',
                    'transaction_date' => now()->toDateString(),
                    'status' => PaymentStatus::Pending,
                    'notes' => 'Refund owed after cancellation',
                    'created_by' => $userId,
                ]);
            }

            // 4. Change the status and write one clear history entry with the note.
            $booking->status = $to;
            $booking->updated_by = $userId;
            $booking->saveQuietly();
            $this->audit->record('status_changed', $booking,
                ['status' => $from->value],
                array_filter([
                    'status' => $to->value,
                    'note' => $data['note'] ?? null,
                    'reason' => $to === BookingStatus::Cancelled ? $data['reason'] : null,
                ], fn ($v) => $v !== null && $v !== ''),
            );

            return $booking->refresh();
        });
    }

    /**
     * What is still missing before the booking can be marked Ticketed.
     *
     * @return list<string>
     */
    public function ticketingProblems(Booking $booking): array
    {
        $booking->loadMissing(['passengers.tickets.eTicket', 'flights']);
        $problems = [];

        if ($booking->passengers->isEmpty()) {
            $problems[] = 'Add the passengers before issuing tickets.';
        } elseif ($booking->passengers->count() < $booking->passenger_count) {
            $problems[] = "Only {$booking->passengers->count()} of {$booking->passenger_count} passengers have been added.";
        }
        if ($booking->flights->isEmpty()) {
            $problems[] = 'Add the confirmed flight segments.';
        }

        $missingPnr = false;
        foreach ($booking->passengers as $passenger) {
            $active = $passenger->tickets->filter->isActive();
            if ($active->isEmpty()) {
                $problems[] = "{$passenger->full_name_as_document}: no ticket number recorded.";

                continue;
            }
            foreach ($active as $ticket) {
                if (! $ticket->eTicket) {
                    $problems[] = "{$passenger->full_name_as_document}: ticket {$ticket->ticket_number} has no e-ticket document.";
                } elseif (! $this->documents->exists($ticket->eTicket)) {
                    $problems[] = "{$passenger->full_name_as_document}: the e-ticket file for {$ticket->ticket_number} is missing from storage.";
                }
                $missingPnr = $missingPnr || (! $booking->pnr && ! $ticket->airline_pnr);
            }
        }
        if ($missingPnr) {
            $problems[] = 'Enter the airline PNR on the booking or on each ticket.';
        }

        return $problems;
    }

    /**
     * Outstanding tasks shown on the booking overview.
     *
     * @return list<array{key: string, label: string, section: string}>
     */
    public function checklist(Booking $booking, bool $withCosts = true): array
    {
        $booking->loadMissing(['passengers.tickets.eTicket', 'flights', 'issues']);
        $status = $booking->status;
        $items = [];
        $add = function (string $key, string $label, string $section) use (&$items): void {
            $items[] = compact('key', 'label', 'section');
        };

        if (in_array($status, [BookingStatus::Cancelled, BookingStatus::Completed], true)) {
            $finance = $this->finance->summary($booking);
            if (Money::cents($finance['refund_pending']) > 0) {
                $add('refund', "Complete the pending refund of {$booking->currency} ".number_format((float) $finance['refund_pending'], 2).'.', 'payments');
            }
        } else {
            $named = $booking->passengers->count();
            if ($named < $booking->passenger_count) {
                $add('passengers', "Add passenger names ({$named} of {$booking->passenger_count} added).", 'passengers');
            }
            if ($booking->flights->isEmpty()) {
                $add('flights', 'Flight details have not been added yet.', 'passengers');
            }
            if ($booking->selling_total === null) {
                $add('price', 'Selling price has not been entered.', 'payments');
            }
            if ($status !== BookingStatus::Inquiry) {
                if (! $booking->pnr && ! $booking->tickets->contains(fn ($t) => $t->airline_pnr)) {
                    $add('pnr', 'Airline PNR has not been entered.', 'overview');
                }
                if ($withCosts && $booking->supplier_total === null) {
                    $add('cost', 'Supplier cost has not been entered.', 'payments');
                }
            }
            if (in_array($status, [BookingStatus::Confirmed, BookingStatus::PendingPayment, BookingStatus::OnHold], true)) {
                if ($booking->tickets->filter->isActive()->isEmpty()) {
                    $add('tickets', 'Tickets have not been issued yet.', 'passengers');
                }
            }
            if (in_array($status, [BookingStatus::Ticketed, BookingStatus::Changed], true)) {
                foreach ($booking->tickets->filter->isActive() as $ticket) {
                    if (! $ticket->eTicket) {
                        $add("eticket-{$ticket->id}", "Ticket {$ticket->ticket_number} has no e-ticket document.", 'documents');
                    }
                }
            }
            $finance = $this->finance->summary($booking);
            if ($finance['customer_balance'] !== null && Money::cents($finance['customer_balance']) > 0 && $status !== BookingStatus::Inquiry) {
                $add('balance', "Customer still owes {$booking->currency} ".number_format((float) $finance['customer_balance'], 2).'.', 'payments');
            }
            if ($withCosts && in_array($status, [BookingStatus::Ticketed, BookingStatus::Changed], true)
                && $finance['supplier_balance'] !== null && Money::cents($finance['supplier_balance']) > 0) {
                $add('supplier', "Supplier still to be paid {$booking->currency} ".number_format((float) $finance['supplier_balance'], 2).'.', 'payments');
            }
        }

        $open = $booking->issues->filter(fn ($i) => ! $i->status->isClosed())->count();
        if ($open > 0) {
            $add('issues', "{$open} open issue(s) need attention.", 'documents');
        }

        return $items;
    }

    /** One sentence telling the user what to do next. */
    public function nextStep(Booking $booking, bool $withCosts = true): string
    {
        $booking->loadMissing(['passengers.tickets.eTicket', 'flights']);

        return match ($booking->status) {
            BookingStatus::Inquiry => 'Find flight options for the customer, then confirm the reservation.',
            BookingStatus::Confirmed, BookingStatus::PendingPayment, BookingStatus::OnHold => $this->ticketingProblems($booking) === []
                ? 'All tickets are recorded. Mark the booking as Ticketed.'
                : 'Issue the tickets and upload the e-ticket'.($booking->ticketing_deadline ? ' before '.$booking->ticketing_deadline->format('d M Y H:i') : '').'.',
            BookingStatus::Ticketed, BookingStatus::Changed => $this->afterTicketing($booking, $withCosts),
            BookingStatus::Cancelled => Money::cents($this->finance->summary($booking)['refund_pending']) > 0
                ? 'Complete the pending refund to the customer.'
                : 'No further action needed. The booking is kept for the record.',
            BookingStatus::Completed => 'Booking closed.',
        };
    }

    private function afterTicketing(Booking $booking, bool $withCosts): string
    {
        $finance = $this->finance->summary($booking);
        if ($finance['customer_balance'] !== null && Money::cents($finance['customer_balance']) > 0) {
            return "Collect the remaining balance of {$booking->currency} ".number_format((float) $finance['customer_balance'], 2).'.';
        }
        if ($withCosts && $finance['supplier_balance'] !== null && Money::cents($finance['supplier_balance']) > 0) {
            return "Pay the supplier {$booking->currency} ".number_format((float) $finance['supplier_balance'], 2).'.';
        }
        if ($booking->issues()->whereIn('status', array_map(fn ($s) => $s->value, IssueStatus::openStatuses()))->exists()) {
            return 'Resolve the open issues.';
        }

        return 'Nothing pending. Mark the booking as Completed after travel.';
    }
}
