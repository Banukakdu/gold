<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * One fiscal year (docs/specs/settings.md R4): it starts on day 1 of the start month M of its start year.
 * Label "FY 2026-27" when M > 1, "FY 2026" when M = 1. Dates are Y-m-d strings (Asia/Colombo days).
 */
final class FiscalYear
{
    public function __construct(public readonly int $startYear, public readonly int $startMonth) {}

    /** Fiscal year of a Y-m-d date: start year = year(d) if month(d) >= M, otherwise year(d) - 1. */
    public static function of(string $date, int $startMonth): self
    {
        $y = (int) substr($date, 0, 4);
        $m = (int) substr($date, 5, 2);

        return new self($m >= $startMonth ? $y : $y - 1, $startMonth);
    }

    public function start(): string
    {
        return sprintf('%04d-%02d-01', $this->startYear, $this->startMonth);
    }

    public function end(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->start())->addYear()->subDay()->format('Y-m-d');
    }

    /** "2027-03": the last month of the year. */
    public function endPeriod(): string
    {
        return substr($this->end(), 0, 7);
    }

    /** "2026-27" (or "2026"): what the close confirmation must type. */
    public function shortLabel(): string
    {
        return $this->startMonth === 1 ? (string) $this->startYear : sprintf('%d-%02d', $this->startYear, ($this->startYear + 1) % 100);
    }

    /** "FY 2026-27" */
    public function label(): string
    {
        return 'FY '.$this->shortLabel();
    }

    public function previous(): self
    {
        return new self($this->startYear - 1, $this->startMonth);
    }

    public function next(): self
    {
        return new self($this->startYear + 1, $this->startMonth);
    }

    public function contains(string $date): bool
    {
        return $date >= $this->start() && $date <= $this->end();
    }

    public function toArray(): array
    {
        return ['start_year' => $this->startYear, 'label' => $this->label(), 'start' => $this->start(), 'end' => $this->end()];
    }
}
