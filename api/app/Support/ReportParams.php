<?php

namespace App\Support;

use App\Models\Branch;
use Illuminate\Support\Collection;

/** Validated, branch-resolved filters for one report run (spec R1). */
final class ReportParams
{
    /**
     * @param  Collection<int, Branch>  $branches  branches in scope (Main first, then by code)
     * @param  array<string, mixed>  $extra  report-specific filters (type, types, accounts, page)
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly Collection $branches,
        public readonly bool $allBranches,
        public readonly bool $subset,
        public readonly array $extra = [],
    ) {}

    /** @return int[] */
    public function branchIds(): array
    {
        return $this->branches->pluck('id')->all();
    }

    /** "All branches" or "KDY Kandy, GAL Galle" for headers. */
    public function branchLabel(): string
    {
        return $this->allBranches ? 'All branches' : $this->branches->map(fn (Branch $b) => "{$b->code} {$b->name}")->implode(', ');
    }

    /** Filter values as given, for the audit log. */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'branches' => $this->allBranches ? [] : $this->branchIds()] + $this->extra;
    }
}
