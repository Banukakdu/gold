<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_cents_round_trip(): void
    {
        $this->assertSame(12345, Money::toCents('123.45'));
        $this->assertSame(12340, Money::toCents('123.4'));
        $this->assertSame(-50, Money::toCents('-0.50'));
        $this->assertSame('0.10', Money::fromCents(10));
        $this->assertSame('-1.05', Money::fromCents(-105));
        $this->assertSame('0.30', Money::add('0.10', '0.20')); // no float drift
        $this->assertSame(1, Money::cmp('10.01', '10'));
    }

    public function test_format_for_display(): void
    {
        $this->assertSame('1,234,567.50', Money::format('1234567.5'));
        $this->assertSame('-1,234.56', Money::format('-1234.56'));
        $this->assertSame('-0.05', Money::format('-0.05'));
        $this->assertSame('0.00', Money::format(null));
    }
}
