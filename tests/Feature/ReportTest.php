<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_report_groups_by_currency_and_excludes_inquiries_and_cancellations(): void
    {
        $this->actingAsRole('owner');
        $this->createBooking(); // 750000 sales, 690000 cost
        $this->createBooking(['status' => 'inquiry']);
        $this->createBooking(['status' => 'cancelled']);
        $this->createBooking(['currency' => 'USD', 'selling_total' => '1000', 'service_fee' => '0', 'supplier_total' => '900']);
        $this->postJson('/api/v1/expenses', ['expense_date' => '2026-10-02', 'category' => 'Rent', 'description' => 'Office', 'amount' => '50000', 'currency' => 'LKR'])->assertCreated();

        $report = $this->getJson('/api/v1/reports/sales?from=2026-10-01&to=2026-10-31&group_by=airline')->assertOk();
        $totals = collect($report->json('totals'))->keyBy('currency');
        $this->assertSame('750000.00', $totals['LKR']['sales']);
        $this->assertSame('60000.00', $totals['LKR']['gross_margin']);
        $this->assertSame('10000.00', $totals['LKR']['net_estimate']);
        $this->assertSame('100.00', $totals['USD']['gross_margin']);
        $this->assertSame('Qatar Airways', $report->json('rows.0.group'));

        $this->getJson('/api/v1/reports/sales?date_basis=departure_date&from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(2, 'totals');
    }

    public function test_outstanding_report_lists_customer_balances_and_supplier_payables(): void
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking();
        $this->postJson("/api/v1/bookings/{$booking->id}/payments", ['direction' => 'customer_receipt', 'amount' => '700000', 'method' => 'Cash', 'transaction_date' => '2026-10-02']);

        $this->getJson('/api/v1/reports/outstanding')->assertOk()
            ->assertJsonPath('customer_balances.0.balance', '50000.00')
            ->assertJsonPath('supplier_payables.0.balance', '690000.00')
            ->assertJsonPath('customer_totals.0.total', '50000.00');
    }

    public function test_reports_are_owner_only_and_csv_export_neutralises_formulas(): void
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking();
        Customer::find($booking->customer_id)->update(['full_name' => '=HYPERLINK("http://x")']);

        $csv = $this->get('/api/v1/reports/export?report=customer_balances')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('750000.00', $csv);

        $this->actingAsRole('staff');
        $this->getJson('/api/v1/reports/outstanding')->assertForbidden();
        $this->getJson('/api/v1/reports/export?report=sales')->assertForbidden();
    }

    public function test_dashboard_summary_respects_role(): void
    {
        $this->actingAsRole('owner');
        $this->createBooking();
        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonStructure(['bookings', 'issues', 'upcoming_departures', 'monthly', 'customer_balances']);

        $this->actingAsRole('staff');
        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonMissingPath('monthly');
    }

    public function test_overview_dashboard_numbers_for_one_currency(): void
    {
        $this->actingAsRole('owner');
        $a = $this->createBooking(); // LKR: sales 750000, cost 690000, 2 passengers, Qatar Airways
        $this->createBooking(['status' => 'cancelled']);
        $this->createBooking(['status' => 'inquiry']);
        $this->createBooking(['currency' => 'USD', 'selling_total' => '1000', 'service_fee' => '0', 'supplier_total' => '900']);
        $this->postJson("/api/v1/bookings/{$a->id}/payments", ['direction' => 'customer_receipt', 'amount' => '500000', 'method' => 'Card', 'transaction_date' => '2026-10-02'])->assertCreated();

        $r = $this->getJson('/api/v1/reports/overview?from=2026-09-01&to=2026-10-31&currency=LKR')->assertOk();
        $r->assertJsonPath('currency', 'LKR')
            ->assertJsonPath('kpis.bookings', 1)
            ->assertJsonPath('kpis.passengers', 2)
            ->assertJsonPath('kpis.sales', '750000.00')
            ->assertJsonPath('kpis.margin', '60000.00')
            ->assertJsonPath('kpis.margin_pct', 8)
            ->assertJsonPath('kpis.cancelled', 1)
            ->assertJsonPath('kpis.cancellation_rate', 50)
            ->assertJsonPath('kpis.inquiries', 1)
            // Balances include the cancelled booking: its totals were not reduced to retained amounts.
            ->assertJsonPath('kpis.customer_outstanding', '1000000.00')
            ->assertJsonPath('kpis.supplier_payable', '1380000.00')
            ->assertJsonPath('by_airline.0.name', 'Qatar Airways')
            ->assertJsonPath('by_route.0.name', 'CMB-DOH-ARN')
            ->assertJsonPath('by_supplier.0.share', 100)
            ->assertJsonPath('payment_methods.0.name', 'Card')
            ->assertJsonPath('payment_methods.0.amount', '500000.00')
            ->assertJsonPath('period.previous_to', '2026-08-31');

        // Every month in the range is present, even with no sales.
        $this->assertSame(['2026-09', '2026-10'], array_column($r->json('monthly'), 'month'));
        $this->assertSame('0.00', $r->json('monthly.0.sales'));
        $this->assertSame(3, array_sum(array_column($r->json('by_status'), 'count')));

        $this->getJson('/api/v1/reports/overview?currency=USD&from=2026-10-01&to=2026-10-31')->assertJsonPath('kpis.sales', '1000.00');

        $this->actingAsRole('staff');
        $this->getJson('/api/v1/reports/overview')->assertForbidden();
    }
}
