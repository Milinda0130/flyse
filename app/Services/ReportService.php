<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingIssue;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Operational reports. Amounts are never summed across currencies; every total
 * is grouped by currency because exchange-rate handling is not configured.
 */
class ReportService
{
    public function __construct(private BookingFinanceService $finance) {}

    /**
     * @param  array{from?: string, to?: string, date_basis?: string, group_by?: string, currency?: string, supplier_id?: int, airline?: string}  $filters
     */
    public function sales(array $filters): array
    {
        $groupBy = $filters['group_by'] ?? 'month';
        $bookings = $this->salesQuery($filters)->with(['flights', 'supplier:id,name'])->get();

        $rows = [];
        foreach ($bookings as $booking) {
            $key = match ($groupBy) {
                'supplier' => $booking->supplier?->name ?? 'No supplier',
                'airline' => $booking->flights->first()?->airline ?? 'No flights',
                'route' => $booking->routeString() ?: 'No flights',
                'status' => $booking->status->label(),
                default => $this->basisDate($booking, $filters['date_basis'] ?? 'booking_date')?->format('Y-m') ?? 'No date',
            };
            $rowKey = $key.'|'.$booking->currency;
            $rows[$rowKey] ??= ['group' => $key, 'currency' => $booking->currency, 'bookings' => 0, 'sales' => 0, 'cost' => 0, 'incomplete' => 0];
            // Price or supplier cost not entered yet: counted as 0, so the margin is incomplete.
            if ($booking->selling_total === null || $booking->supplier_total === null) {
                $rows[$rowKey]['incomplete']++;
            }
            $rows[$rowKey]['bookings']++;
            $rows[$rowKey]['sales'] += Money::cents($booking->selling_total) + Money::cents($booking->service_fee);
            $rows[$rowKey]['cost'] += Money::cents($booking->supplier_total);
        }
        ksort($rows);

        $rows = array_values(array_map(fn ($r) => $this->withMargin($r), $rows));

        return [
            'rows' => $rows,
            'totals' => $this->totalsByCurrency($rows, $filters),
        ];
    }

    public function outstanding(array $filters): array
    {
        $query = Booking::query()
            ->whereNotIn('status', [BookingStatus::Inquiry->value])
            ->with(['customer:id,full_name,phone', 'supplier:id,name']);
        if (! empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        $this->finance->withPaymentSums($query);

        $customers = [];
        $suppliers = [];
        $query->orderBy('booking_date')->chunk(500, function ($bookings) use (&$customers, &$suppliers) {
            foreach ($bookings as $booking) {
                $s = $this->finance->summary($booking);
                $base = [
                    'booking_id' => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'booking_date' => $booking->booking_date?->format('Y-m-d'),
                    'status' => $booking->status->value,
                    'currency' => $booking->currency,
                ];
                if (Money::cents($s['customer_balance']) !== 0) {
                    $customers[] = $base + [
                        'customer' => $booking->customer?->full_name,
                        'phone' => $booking->customer?->phone,
                        'payment_due_date' => $booking->payment_due_date?->format('Y-m-d'),
                        'customer_due' => $s['customer_due'],
                        'customer_paid' => $s['customer_paid'],
                        'balance' => $s['customer_balance'],
                    ];
                }
                if (Money::cents($s['supplier_balance']) !== 0) {
                    $suppliers[] = $base + [
                        'supplier' => $booking->supplier?->name ?? 'No supplier',
                        'supplier_total' => $s['supplier_total'],
                        'supplier_paid' => Money::format(Money::cents($s['supplier_paid']) - Money::cents($s['supplier_refunded'])),
                        'balance' => $s['supplier_balance'],
                    ];
                }
            }
        });

        return [
            'customer_balances' => $customers,
            'customer_totals' => $this->sumByCurrency($customers, 'balance'),
            'supplier_payables' => $suppliers,
            'supplier_totals' => $this->sumByCurrency($suppliers, 'balance'),
        ];
    }

    public function issues(array $filters): array
    {
        $issues = BookingIssue::query()
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('reported_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('reported_at', '<=', $to))
            ->with('booking:id,booking_code,currency')
            ->get();

        $byType = [];
        foreach (IssueType::cases() as $type) {
            $ofType = $issues->where('issue_type', $type);
            $byType[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'total' => $ofType->count(),
                'open' => $ofType->filter(fn ($i) => ! $i->status->isClosed())->count(),
                'resolved' => $ofType->filter(fn ($i) => $i->status->isClosed())->count(),
            ];
        }

        $byStatus = array_map(fn (IssueStatus $s) => [
            'status' => $s->value, 'label' => $s->label(), 'total' => $issues->where('status', $s)->count(),
        ], IssueStatus::cases());

        $cancellations = Booking::query()
            ->where('status', BookingStatus::Cancelled->value)
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('updated_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('updated_at', '<=', $to))
            ->with('customer:id,full_name')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Booking $b) => [
                'booking_id' => $b->id,
                'booking_code' => $b->booking_code,
                'customer' => $b->customer?->full_name,
                'pnr' => $b->pnr,
                'cancelled_on' => $b->updated_at?->format('Y-m-d'),
                'currency' => $b->currency,
            ])->values();

        $refunds = Payment::query()
            ->whereIn('direction', [PaymentDirection::CustomerRefund->value, PaymentDirection::SupplierRefund->value])
            ->where('status', '!=', PaymentStatus::Voided->value)
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('transaction_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('transaction_date', '<=', $to))
            ->with('booking:id,booking_code')
            ->orderByDesc('transaction_date')
            ->get()
            ->map(fn (Payment $p) => [
                'payment_id' => $p->id,
                'booking_id' => $p->booking_id,
                'booking_code' => $p->booking?->booking_code,
                'direction' => $p->direction->value,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'status' => $p->status->value,
                'transaction_date' => $p->transaction_date?->format('Y-m-d'),
                'reference' => $p->transaction_reference,
            ])->values();

        $openRefundIssues = $issues
            ->filter(fn ($i) => $i->issue_type === IssueType::Refund && ! $i->status->isClosed())
            ->count();

        return [
            'total' => $issues->count(),
            'open' => $issues->filter(fn ($i) => ! $i->status->isClosed())->count(),
            'by_type' => $byType,
            'by_status' => $byStatus,
            'cancellations' => $cancellations,
            'refunds' => $refunds,
            'open_refund_issues' => $openRefundIssues,
        ];
    }

    /** Monthly sales/margin for the dashboard chart, last $months months by booking date. */
    public function monthly(int $months = 12): array
    {
        $from = now()->startOfMonth()->subMonths($months - 1);
        $data = $this->sales(['from' => $from->toDateString(), 'group_by' => 'month']);

        return $data['rows'];
    }

    private function salesQuery(array $filters): Builder
    {
        $basis = $filters['date_basis'] ?? 'booking_date';
        $query = Booking::query()->whereIn('status', array_map(fn ($s) => $s->value, BookingStatus::salesStatuses()));

        if ($basis === 'departure_date') {
            // Date of the booking's first departure.
            $first = 'date((select min(f.departure_at) from booking_flights f where f.booking_id = bookings.id))';
            $query->when($filters['from'] ?? null, fn ($q, $from) => $q->whereRaw("$first >= ?", [$from]))
                ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereRaw("$first <= ?", [$to]));
        } else {
            $query->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('booking_date', '>=', $from))
                ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('booking_date', '<=', $to));
        }

        return $query
            ->when($filters['currency'] ?? null, fn ($q, $c) => $q->where('currency', $c))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['airline'] ?? null, fn ($q, $a) => $q->whereHas('flights', fn ($f) => $f->where('airline', 'like', '%'.$a.'%')))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
    }

    private function basisDate(Booking $booking, string $basis): ?\DateTimeInterface
    {
        return $basis === 'departure_date' ? $booking->flights->min('departure_at') : $booking->booking_date;
    }

    private function withMargin(array $row): array
    {
        return [
            ...$row,
            'sales' => Money::format($row['sales']),
            'cost' => Money::format($row['cost']),
            'margin' => Money::format($row['sales'] - $row['cost']),
        ];
    }

    /** Per-currency totals including operating expenses for the same period (by expense date). */
    private function totalsByCurrency(array $rows, array $filters): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $t = &$totals[$row['currency']];
            $t ??= ['currency' => $row['currency'], 'bookings' => 0, 'sales' => 0, 'cost' => 0, 'expenses' => 0, 'incomplete' => 0];
            $t['bookings'] += $row['bookings'];
            $t['incomplete'] += $row['incomplete'];
            $t['sales'] += Money::cents($row['sales']);
            $t['cost'] += Money::cents($row['cost']);
            unset($t);
        }

        $expenses = Expense::query()
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('expense_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('expense_date', '<=', $to))
            ->when($filters['currency'] ?? null, fn ($q, $c) => $q->where('currency', $c))
            ->get(['amount', 'currency']);
        foreach ($expenses as $expense) {
            $totals[$expense->currency] ??= ['currency' => $expense->currency, 'bookings' => 0, 'sales' => 0, 'cost' => 0, 'expenses' => 0, 'incomplete' => 0];
            $totals[$expense->currency]['expenses'] += Money::cents($expense->amount);
        }

        ksort($totals);

        return array_values(array_map(fn ($t) => [
            'currency' => $t['currency'],
            'bookings' => $t['bookings'],
            'sales' => Money::format($t['sales']),
            'cost' => Money::format($t['cost']),
            'gross_margin' => Money::format($t['sales'] - $t['cost']),
            'expenses' => Money::format($t['expenses']),
            'net_estimate' => Money::format($t['sales'] - $t['cost'] - $t['expenses']),
            'incomplete' => $t['incomplete'],
        ], $totals));
    }

    /** @return list<array{currency: string, total: string}> */
    private function sumByCurrency(array $rows, string $field): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row['currency']] = ($totals[$row['currency']] ?? 0) + Money::cents($row[$field]);
        }
        ksort($totals);

        return array_map(fn ($c, $v) => ['currency' => $c, 'total' => Money::format($v)], array_keys($totals), $totals);
    }

    /** Flattens report rows into CSV lines. */
    public function toCsvRows(string $report, array $data): Collection
    {
        return collect(match ($report) {
            'sales' => [['Group', 'Currency', 'Bookings', 'Sales', 'Supplier cost', 'Gross margin'],
                ...array_map(fn ($r) => [$r['group'], $r['currency'], $r['bookings'], $r['sales'], $r['cost'], $r['margin']], $data['rows'])],
            'customer_balances' => [['Booking', 'Booking date', 'Customer', 'Phone', 'Currency', 'Due', 'Paid', 'Balance', 'Payment due'],
                ...array_map(fn ($r) => [$r['booking_code'], $r['booking_date'], $r['customer'], $r['phone'], $r['currency'], $r['customer_due'], $r['customer_paid'], $r['balance'], $r['payment_due_date']], $data['customer_balances'])],
            'supplier_payables' => [['Booking', 'Booking date', 'Supplier', 'Currency', 'Supplier total', 'Paid', 'Balance'],
                ...array_map(fn ($r) => [$r['booking_code'], $r['booking_date'], $r['supplier'], $r['currency'], $r['supplier_total'], $r['supplier_paid'], $r['balance']], $data['supplier_payables'])],
            'issues' => [['Issue type', 'Total', 'Open', 'Resolved/closed'],
                ...array_map(fn ($r) => [$r['label'], $r['total'], $r['open'], $r['resolved']], $data['by_type'])],
            default => [],
        });
    }
}
