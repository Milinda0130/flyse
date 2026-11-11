<?php

namespace Tests\Unit;

use App\Services\BookingFinanceService;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_cents_conversion_avoids_float_drift(): void
    {
        $this->assertSame(30, Money::cents('0.1') + Money::cents('0.2'));
        $this->assertSame('0.30', Money::format(Money::cents('0.1') + Money::cents('0.2')));
        $this->assertSame('-12.05', Money::format(-1205));
        $this->assertSame(99999999999999, Money::cents('999999999999.99'));
    }

    public function test_payment_status_rules(): void
    {
        $s = new BookingFinanceService;
        $this->assertSame('unpaid', $s->paymentStatus(1000, 0));
        $this->assertSame('partial', $s->paymentStatus(1000, 1));
        $this->assertSame('paid', $s->paymentStatus(1000, 1000));
        $this->assertSame('overpaid', $s->paymentStatus(1000, 1001));
        $this->assertSame('not_due', $s->paymentStatus(0, 0));
    }
}
