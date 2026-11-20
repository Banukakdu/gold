<?php

namespace App\Models;

use App\Support\FiscalYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-092 year-end close (docs/specs/settings.md R7-R11). One row per close; a reopen keeps the row (status reopened)
 * and a new close adds a row. At most one active (status closed) row per fiscal year (active_key unique).
 * No branch: the close is one action for all branches.
 */
class YearEndClose extends Model
{
    public const CLOSED = 'closed';

    public const REOPENED = 'reopened';

    protected $fillable = ['fiscal_year', 'period_start', 'period_end', 'status', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at',
        'reopen_reason', 'summary', 'period_lock_id'];

    protected $casts = ['summary' => 'array', 'closed_at' => 'datetime', 'reopened_at' => 'datetime', 'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d', 'fiscal_year' => 'integer'];

    protected $hidden = ['active_key'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::CLOSED);
    }

    public function fiscal(): FiscalYear
    {
        return new FiscalYear((int) $this->fiscal_year, (int) $this->period_start->format('n'));
    }

    /** @return int[] closing transaction ids (summary.by_branch.*.transaction_id) */
    public function transactionIds(): array
    {
        return array_values(array_filter(array_map(fn ($b) => (int) ($b['transaction_id'] ?? 0), $this->summary['by_branch'] ?? [])));
    }

    public function totalNet(): string
    {
        return (string) ($this->summary['total_net'] ?? '0.00');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function periodLock(): BelongsTo
    {
        return $this->belongsTo(PeriodLock::class);
    }
}
