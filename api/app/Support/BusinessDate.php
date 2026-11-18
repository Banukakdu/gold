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

    /**
     * The one accepted form of a money date: strict YYYY-MM-DD that is a real calendar day (accountant sign-off A).
     * The checked date (period lock) and the stored date are this same string.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(string $date): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Invalid date '{$date}': use YYYY-MM-DD.");
        }

        return $date;
    }

    /** YYYY-MM */
    public static function currentPeriod(): string
    {
        return self::now()->format('Y-m');
    }
}
