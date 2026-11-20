<?php

namespace App\Support;

/** Exact 2-decimal arithmetic using integer cents (no floats, no bcmath dependency). */
final class Money
{
    public static function toCents(string|int|float|null $v): int
    {
        $s = trim((string) ($v ?? '0'));
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '+-');
        [$int, $dec] = array_pad(explode('.', $s, 2), 2, '');
        $cents = ((int) ($int ?: '0')) * 100 + (int) str_pad(substr($dec, 0, 2), 2, '0');

        return $neg ? -$cents : $cents;
    }

    public static function fromCents(int $c): string
    {
        $sign = $c < 0 ? '-' : '';
        $c = abs($c);

        return $sign.intdiv($c, 100).'.'.str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "-1234567.5" -> "-1,234,567.50" (display; exact, from cents). */
    public static function format(string|int|float|null $v): string
    {
        $s = self::fromCents(self::toCents($v));
        $neg = str_starts_with($s, '-');
        [$int, $dec] = explode('.', ltrim($s, '-'));

        return ($neg ? '-' : '').number_format((int) $int).'.'.$dec;
    }

    public static function add(string|int|float|null ...$vals): string
    {
        return self::fromCents(array_sum(array_map([self::class, 'toCents'], $vals)));
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return self::toCents($a) <=> self::toCents($b);
    }
}
