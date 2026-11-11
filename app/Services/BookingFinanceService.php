<?php

namespace App\Services;

use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finance rules (plan section 6), all in the booking's own currency:
 *   customer due      = selling_total + service_fee
 *   customer paid     = completed receipts - completed refunds to customer
 *   customer balance  = customer due - customer paid            (positive = customer owes us)
 *   supplier paid     = completed supplier payments - completed refunds from supplier
 *   supplier balance  = supplier_total - supplier paid           (positive = we owe supplier)
 *   gross margin      = customer due - supplier_total            (not company net profit)
 * Pending and voided transactions never affect balances.
 */
class BookingFinanceService
{
    /** Adds per-direction completed payment sums to a booking query (aliases sum_<direction>). */
    public function withPaymentSums(Builder $query): Builder
    {
        foreach (PaymentDirection::cases() as $direction) {
            $query->withSum(
                ['payments as sum_'.$direction->value => fn ($q) => $q
                    ->where('direction', $direction->value)
                    ->where('status', PaymentStatus::Completed->value)],
                'amount'
            );
        }
        // Refunds promised to the customer but not yet paid.
        $query->withSum(
            ['payments as sum_pending_customer_refund' => fn ($q) => $q
                ->where('direction', PaymentDirection::CustomerRefund->value)
                ->where('status', PaymentStatus::Pending->value)],
            'amount'
        );

        return $query;
    }

    /** @return array<string, string|null> */
    /**
     * Values are strings in the booking currency, or NULL when they cannot be known yet:
     * no selling price entered -> due/balance/margin are NULL (shown as "Not entered", never 0);
     * no supplier cost entered -> supplier balance and margin are NULL.
     *
     * @return array<string, string|null>
     */
    public function summary(Booking $booking): array
    {
        $sums = $this->sums($booking);

        $priced = $booking->selling_total !== null;
        $costed = $booking->supplier_total !== null;
        $due = $priced ? Money::cents($booking->selling_total) + Money::cents($booking->service_fee) : null;
        $supplierTotal = $costed ? Money::cents($booking->supplier_total) : null;
        $customerPaid = $sums['customer_receipt'] - $sums['customer_refund'];
        $supplierPaid = $sums['supplier_payment'] - $sums['supplier_refund'];

        return [
            'currency' => $booking->currency,
            'customer_due' => Money::formatOrNull($due),
            'customer_received' => Money::format($sums['customer_receipt']),
            'customer_refunded' => Money::format($sums['customer_refund']),
            'customer_paid' => Money::format($customerPaid),
            'customer_balance' => Money::formatOrNull($due === null ? null : $due - $customerPaid),
            'payment_status' => $due === null ? 'not_priced' : $this->paymentStatus($due, $customerPaid),
            'refund_pending' => Money::format($sums['pending_customer_refund']),
            'supplier_total' => Money::formatOrNull($supplierTotal),
            'supplier_paid' => Money::format($sums['supplier_payment']),
            'supplier_refunded' => Money::format($sums['supplier_refund']),
            'supplier_balance' => Money::formatOrNull($supplierTotal === null ? null : $supplierTotal - $supplierPaid),
            'gross_margin' => Money::formatOrNull($due === null || $supplierTotal === null ? null : $due - $supplierTotal),
        ];
    }

    public function paymentStatus(int $dueCents, int $paidCents): string
    {
        return match (true) {
            $dueCents <= 0 && $paidCents === 0 => 'not_due',
            $paidCents > $dueCents => 'overpaid',
            $paidCents === $dueCents => 'paid',
            $paidCents > 0 => 'partial',
            default => 'unpaid',
        };
    }

    /** @return array<string, int> completed totals in cents keyed by direction value */
    private function sums(Booking $booking): array
    {
        $attributes = $booking->getAttributes();
        $payments = null;
        $sums = [];
        $keys = [...array_map(fn ($d) => $d->value, PaymentDirection::cases()), 'pending_customer_refund'];
        foreach ($keys as $key) {
            if (array_key_exists('sum_'.$key, $attributes)) {
                $sums[$key] = Money::cents($attributes['sum_'.$key]);

                continue;
            }
            $payments ??= $booking->relationLoaded('payments') ? $booking->payments : $booking->payments()->get();
            [$direction, $status] = $key === 'pending_customer_refund'
                ? [PaymentDirection::CustomerRefund, PaymentStatus::Pending]
                : [PaymentDirection::from($key), PaymentStatus::Completed];
            $sums[$key] = $payments
                ->filter(fn ($p) => $p->direction === $direction && $p->status === $status)
                ->sum(fn ($p) => Money::cents($p->amount));
        }

        return $sums;
    }
}
