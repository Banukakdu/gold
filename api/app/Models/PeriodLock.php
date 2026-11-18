<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-091 month lock. branch_id null = all branches. An unlock keeps the row (unlocked_at/by/reason); a re-lock adds a row.
 *
 * Locks are cumulative ("books closed through", accounting review finding 1): an active lock for period P closes
 * P and every earlier month for its scope.
 */
class PeriodLock extends Model
{
    protected $fillable = ['branch_id', 'period', 'locked_by', 'unlocked_at', 'unlocked_by', 'unlock_reason'];

    protected $casts = ['unlocked_at' => 'datetime'];

    protected $hidden = ['active_key'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('unlocked_at');
    }

    public static function isLocked(int $branchId, string $date): bool
    {
        return static::active()
            ->where('period', '>=', substr($date, 0, 7))
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->exists();
    }

    /**
     * Latest actively locked period per scope: ['global' => 'YYYY-MM'|null, 'branches' => [branch_id => 'YYYY-MM']].
     * A month M is closed for branch B when M <= max(global, branches[B]).
     */
    public static function closedThrough(): array
    {
        $rows = static::active()->selectRaw('branch_id, MAX(period) as period')->groupBy('branch_id')->get();

        return [
            'global' => $rows->whereNull('branch_id')->first()?->period,
            'branches' => $rows->whereNotNull('branch_id')->mapWithKeys(fn ($r) => [(int) $r->branch_id => $r->period])->all(),
        ];
    }

    /** "2026-10" -> "October 2026" */
    public static function label(string $period): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $period)->format('F Y');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function unlockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }
}
