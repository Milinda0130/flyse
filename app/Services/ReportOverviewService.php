<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\IssueType;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingIssue;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Data for the reports dashboard, for ONE currency (amounts are never mixed across
 * currencies). Sales follow the same rules as the sales report: inquiries and
 * cancellations are not sales; sales = selling + service fee; margin = sales - supplier cost.
 */
class ReportOverviewService
{
    private const TOP = 8;

    private const SHARE_SLICES = 5; // part-to-whole charts: top 5 + "Other"

    public function __construct(private BookingFinanceService $finance) {}

    /** @param  array{from: string, to: string, currency: string, date_basis?: string}  $filters */
    public function build(array $filters): array
    {
        $from = CarbonImmutable::parse($filters['from'])->startOfDay();
        $to = CarbonImmutable::parse($filters['to'])->endOfDay();
        $basis = $filters['date_basis'] ?? 'booking_date';
        $currency = $filters['currency'];

        $all = $this->bookingsInRange($from, $to, $basis, $currency)->with(['flights', 'supplier:id,name', 'customer:id,full_name'])->withCount('passengers')->get();
        $sales = $all->filter(fn (Booking $b) => in_array($b->status, BookingStatus::salesStatuses(), true))->values();

        // Previous period of the same length, for trend arrows on the KPI tiles.
        $days = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->subDay()->endOfDay();
        $prevFrom = $prevTo->subDays($days - 1)->startOfDay();
        $prevAll = $this->bookingsInRange($prevFrom, $prevTo, $basis, $currency)->get();
        $prevSales = $prevAll->filter(fn (Booking $b) => in_array($b->status, BookingStatus::salesStatuses(), true));

        return [
            'currency' => $currency,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'previous_from' => $prevFrom->toDateString(), 'previous_to' => $prevTo->toDateString()],
            'kpis' => $this->kpis($all, $sales, $from, $to, $currency),
            'previous' => $this->kpis($prevAll, $prevSales, $prevFrom, $prevTo, $currency, withBalances: false),
            'monthly' => $this->monthly($sales, $from, $to, $basis),
            'by_airline' => $this->ranked($sales, fn (Booking $b) => $b->flights->first()?->airline ?? 'No flights'),
            'by_route' => $this->ranked($sales, fn (Booking $b) => $b->routeString() ?: 'No flights'),
            'by_supplier' => $this->share($this->ranked($sales, fn (Booking $b) => $b->supplier?->name ?? 'No supplier', limit: null)),
            'top_customers' => array_slice($this->ranked($sales, fn (Booking $b) => $b->customer?->full_name ?? 'Unknown'), 0, 5),
            'by_status' => $this->statusMix($all),
            'payment_methods' => $this->paymentMethods($from, $to, $currency),
            'issues_by_type' => $this->issuesByType($from, $to),
        ];
    }

    private function bookingsInRange(CarbonImmutable $from, CarbonImmutable $to, string $basis, string $currency): Builder
    {
        $query = Booking::query()->where('currency', $currency);
        if ($basis === 'departure_date') {
            $first = 'date((select min(f.departure_at) from booking_flights f where f.booking_id = bookings.id))';
            $query->whereRaw("$first >= ?", [$from->toDateString()])->whereRaw("$first <= ?", [$to->toDateString()]);
        } else {
            $query->whereDate('booking_date', '>=', $from->toDateString())->whereDate('booking_date', '<=', $to->toDateString());
        }

        return $query;
    }

    private function kpis(Collection $all, Collection $sales, CarbonImmutable $from, CarbonImmutable $to, string $currency, bool $withBalances = true): array
    {
        $salesCents = $sales->sum(fn (Booking $b) => Money::cents($b->selling_total) + Money::cents($b->service_fee));
        $costCents = $sales->sum(fn (Booking $b) => Money::cents($b->supplier_total));
        $expenseCents = Expense::query()->where('currency', $currency)
            ->whereDate('expense_date', '>=', $from->toDateString())->whereDate('expense_date', '<=', $to->toDateString())
            ->get(['amount'])->sum(fn ($e) => Money::cents($e->amount));
        $marginCents = $salesCents - $costCents;
        $cancelled = $all->where('status', BookingStatus::Cancelled)->count();
        $decided = $all->reject(fn (Booking $b) => $b->status === BookingStatus::Inquiry)->count();

        $kpis = [
            'bookings' => $sales->count(),
            'passengers' => (int) $sales->sum('passengers_count'),
            'sales' => Money::format($salesCents),
            'cost' => Money::format($costCents),
            'margin' => Money::format($marginCents),
            'margin_pct' => $salesCents > 0 ? round($marginCents / $salesCents * 100, 1) : null,
            'expenses' => Money::format($expenseCents),
            'net' => Money::format($marginCents - $expenseCents),
            'avg_booking_value' => $sales->count() ? Money::format(intdiv($salesCents, $sales->count())) : '0.00',
            'cancelled' => $cancelled,
            'cancellation_rate' => $decided > 0 ? round($cancelled / $decided * 100, 1) : null,
            'inquiries' => $all->where('status', BookingStatus::Inquiry)->count(),
            // Sales bookings without a selling price or supplier cost: margin figures are incomplete.
            'incomplete' => $sales->filter(fn (Booking $b) => $b->selling_total === null || $b->supplier_total === null)->count(),
        ];

        if ($withBalances) {
            // Outstanding balances are a point-in-time position (all bookings in this currency), not period-bound.
            $query = Booking::query()->where('currency', $currency)->where('status', '!=', BookingStatus::Inquiry->value);
            $this->finance->withPaymentSums($query);
            $customerOwes = 0;
            $weOweSuppliers = 0;
            foreach ($query->get() as $booking) {
                $s = $this->finance->summary($booking);
                $customerOwes += max(0, Money::cents($s['customer_balance']));
                $weOweSuppliers += max(0, Money::cents($s['supplier_balance']));
            }
            $kpis['customer_outstanding'] = Money::format($customerOwes);
            $kpis['supplier_payable'] = Money::format($weOweSuppliers);
        }

        return $kpis;
    }

    /** One row per calendar month in the range, including empty months, so the chart has no gaps. */
    private function monthly(Collection $sales, CarbonImmutable $from, CarbonImmutable $to, string $basis): array
    {
        $rows = [];
        for ($m = $from->startOfMonth(); $m <= $to; $m = $m->addMonth()) {
            $rows[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => $m->format('M Y'), 'bookings' => 0, 'sales' => 0, 'cost' => 0];
        }
        foreach ($sales as $b) {
            $date = $basis === 'departure_date' ? $b->flights->min('departure_at') : $b->booking_date;
            $key = $date?->format('Y-m');
            if (! isset($rows[$key])) {
                continue;
            }
            $rows[$key]['bookings']++;
            $rows[$key]['sales'] += Money::cents($b->selling_total) + Money::cents($b->service_fee);
            $rows[$key]['cost'] += Money::cents($b->supplier_total);
        }

        return array_values(array_map(fn ($r) => [
            ...$r,
            'sales' => Money::format($r['sales']),
            'cost' => Money::format($r['cost']),
            'margin' => Money::format($r['sales'] - $r['cost']),
        ], $rows));
    }

    /** Groups sales by a key, sorted by sales descending. */
    private function ranked(Collection $sales, callable $key, ?int $limit = self::TOP): array
    {
        $groups = [];
        foreach ($sales as $b) {
            $name = $key($b);
            $groups[$name] ??= ['name' => $name, 'bookings' => 0, 'sales' => 0, 'cost' => 0];
            $groups[$name]['bookings']++;
            $groups[$name]['sales'] += Money::cents($b->selling_total) + Money::cents($b->service_fee);
            $groups[$name]['cost'] += Money::cents($b->supplier_total);
        }
        usort($groups, fn ($a, $b) => $b['sales'] <=> $a['sales'] ?: strcmp($a['name'], $b['name']));
        if ($limit !== null) {
            $groups = array_slice($groups, 0, $limit);
        }

        return array_map(fn ($g) => [
            'name' => $g['name'],
            'bookings' => $g['bookings'],
            'sales' => Money::format($g['sales']),
            'margin' => Money::format($g['sales'] - $g['cost']),
        ], $groups);
    }

    /** Top slices plus "Other", each with its percentage of the total. */
    private function share(array $ranked): array
    {
        $total = array_sum(array_map(fn ($r) => Money::cents($r['sales']), $ranked));
        $head = array_slice($ranked, 0, self::SHARE_SLICES);
        $tail = array_slice($ranked, self::SHARE_SLICES);
        if ($tail) {
            $tailCents = array_sum(array_map(fn ($r) => Money::cents($r['sales']), $tail));
            $head[] = ['name' => 'Other ('.count($tail).')', 'bookings' => array_sum(array_column($tail, 'bookings')), 'sales' => Money::format($tailCents), 'margin' => null];
        }

        return array_map(fn ($r) => [...$r, 'share' => $total > 0 ? round(Money::cents($r['sales']) / $total * 100, 1) : 0], $head);
    }

    /** Booking counts per status (all bookings in range, including inquiries and cancellations). */
    private function statusMix(Collection $all): array
    {
        $total = $all->count();
        $rows = [];
        foreach (BookingStatus::cases() as $status) {
            $count = $all->where('status', $status)->count();
            if ($count > 0) {
                $rows[] = ['status' => $status->value, 'label' => $status->label(), 'count' => $count, 'share' => round($count / $total * 100, 1)];
            }
        }
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);
        if (count($rows) > self::SHARE_SLICES + 1) {
            $tail = array_splice($rows, self::SHARE_SLICES);
            $count = array_sum(array_column($tail, 'count'));
            $rows[] = ['status' => 'other', 'label' => 'Other', 'count' => $count, 'share' => round($count / $total * 100, 1)];
        }

        return $rows;
    }

    /** Completed customer receipts in the period by payment method, net of refunds paid the same way. */
    private function paymentMethods(CarbonImmutable $from, CarbonImmutable $to, string $currency): array
    {
        $payments = Payment::query()
            ->where('currency', $currency)
            ->where('status', PaymentStatus::Completed->value)
            ->where('direction', PaymentDirection::CustomerReceipt->value)
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->whereDate('transaction_date', '<=', $to->toDateString())
            ->get(['method', 'amount']);

        $methods = [];
        foreach ($payments as $p) {
            $methods[$p->method] = ($methods[$p->method] ?? 0) + Money::cents($p->amount);
        }
        arsort($methods);
        $ranked = array_map(fn ($name, $cents) => ['name' => $name, 'bookings' => 0, 'sales' => Money::format($cents), 'margin' => null], array_keys($methods), $methods);

        return array_map(fn ($r) => ['name' => $r['name'], 'amount' => $r['sales'], 'share' => $r['share']], $this->share($ranked));
    }

    private function issuesByType(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $issues = BookingIssue::query()
            ->where('reported_at', '>=', $from)->where('reported_at', '<=', $to)
            ->get(['issue_type', 'status']);

        $rows = [];
        foreach (IssueType::cases() as $type) {
            $ofType = $issues->where('issue_type', $type);
            if ($ofType->isEmpty()) {
                continue;
            }
            $rows[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'open' => $ofType->filter(fn ($i) => ! $i->status->isClosed())->count(),
                'resolved' => $ofType->filter(fn ($i) => $i->status->isClosed())->count(),
            ];
        }

        return $rows;
    }
}
