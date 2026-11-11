<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private function pay(int $bookingId, string $direction, string $amount, array $extra = [])
    {
        return $this->postJson("/api/v1/bookings/{$bookingId}/payments", [
            'direction' => $direction, 'amount' => $amount, 'method' => 'Cash', 'transaction_date' => '2026-10-05', ...$extra,
        ]);
    }

    public function test_partial_payment_partial_refund_and_supplier_balance(): void
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking(); // due 750000, cost 690000

        $this->pay($booking->id, 'customer_receipt', '500000')->assertCreated();
        $this->getJson("/api/v1/bookings/{$booking->id}/payments")
            ->assertJsonPath('summary.customer_balance', '250000.00')
            ->assertJsonPath('summary.payment_status', 'partial');

        $this->pay($booking->id, 'customer_receipt', '250000.00')->assertCreated();
        $this->pay($booking->id, 'customer_refund', '100000.50')->assertCreated();
        $this->pay($booking->id, 'supplier_payment', '690000')->assertCreated();
        $this->pay($booking->id, 'supplier_refund', '80000')->assertCreated();
        $this->pay($booking->id, 'customer_receipt', '999', ['status' => 'pending'])->assertCreated();

        $this->getJson("/api/v1/bookings/{$booking->id}/payments")
            ->assertJsonPath('summary.customer_paid', '649999.50')
            ->assertJsonPath('summary.customer_balance', '100000.50')
            ->assertJsonPath('summary.supplier_balance', '80000.00')
            ->assertJsonPath('summary.gross_margin', '60000.00')
            ->assertJsonCount(6, 'payments');
    }

    public function test_voided_payment_does_not_count_and_is_kept(): void
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking();
        $id = $this->pay($booking->id, 'customer_receipt', '750000')->json('id');
        $this->getJson("/api/v1/bookings/{$booking->id}/payments")->assertJsonPath('summary.payment_status', 'paid');

        $this->patchJson("/api/v1/payments/{$id}", ['status' => 'voided', 'notes' => 'Entered twice'])->assertOk();
        $this->getJson("/api/v1/bookings/{$booking->id}/payments")
            ->assertJsonPath('summary.payment_status', 'unpaid')
            ->assertJsonCount(1, 'payments');
    }

    public function test_payment_must_use_booking_currency_and_positive_amount(): void
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking();
        $this->pay($booking->id, 'customer_receipt', '100', ['currency' => 'USD'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->pay($booking->id, 'customer_receipt', '-5')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->pay($booking->id, 'customer_receipt', '1.234')->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_staff_may_record_receipts_only_and_cannot_edit_payments(): void
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking();

        $this->actingAsRole('staff');
        $id = $this->pay($booking->id, 'customer_receipt', '1000')->assertCreated()->json('id');
        $this->pay($booking->id, 'supplier_payment', '1000')->assertForbidden();
        $this->pay($booking->id, 'customer_refund', '1000')->assertForbidden();
        $this->patchJson("/api/v1/payments/{$id}", ['status' => 'voided'])->assertForbidden();
    }

    public function test_readonly_user_cannot_see_costs_margin_or_supplier_payments(): void
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking();
        $this->pay($booking->id, 'supplier_payment', '690000');
        $this->pay($booking->id, 'customer_receipt', '1000');

        $this->actingAsRole('readonly');
        $detail = $this->getJson("/api/v1/bookings/{$booking->id}")->assertOk();
        $detail->assertJsonMissingPath('supplier_total')->assertJsonMissingPath('finance.gross_margin')->assertJsonCount(1, 'payments');
        $this->getJson('/api/v1/payments')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/reports/sales')->assertForbidden();
        $this->pay($booking->id, 'customer_receipt', '1')->assertForbidden();
    }

    public function test_currency_cannot_change_after_payments(): void
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking();
        $this->pay($booking->id, 'customer_receipt', '10');
        $this->putJson("/api/v1/bookings/{$booking->id}", ['currency' => 'USD'])->assertStatus(422)->assertJsonValidationErrors('currency');
    }
}
