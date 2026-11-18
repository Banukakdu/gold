<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** "Today" and "this month" in the business timezone (Asia/Colombo), not the server's UTC (reviewer R10). */
final class BusinessDate
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Asia/Colombo');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /** YYYY-MM-DD */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** YYYY-MM */
    public static function currentPeriod(): string
    {
        return self::now()->format('Y-m');
    }
}
