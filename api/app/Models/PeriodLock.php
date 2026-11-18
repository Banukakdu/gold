<?php

namespace App\Models;

use App\Support\BusinessDate;
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

    /**
     * @param  bool  $share  take a shared (locking) read, used inside posting transactions (reviewer R5): on MySQL a
     *                       concurrent lock insert for that branch and month (or later) waits until the posting commits.
     */
    public static function isLocked(int $branchId, string $date, bool $share = false): bool
    {
        return static::lockedQuery($branchId, $date, $share)->exists();
    }

    public static function lockedQuery(int $branchId, string $date, bool $share = false): Builder
    {
        // Strict YYYY-MM-DD only: a text compare on another format ("2026/10/05") would miss the lock.
        $period = substr(BusinessDate::normalize($date), 0, 7);

        return static::active()
            ->where('period', '>=', $period)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->when($share, fn ($q) => $q->sharedLock());
    }

    /**
     * Latest actively locked period per scope: ['global' => 'YYYY-MM'|null, 'branches' => [branch_id => 'YYYY-MM']].
     * A month M is closed for branch B when M <= max(global, branches[B]).
     */
    public static function closedThrough(?int $exceptId = null): array
    {
        $rows = static::active()->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))
            ->selectRaw('branch_id, MAX(period) as period')->groupBy('branch_id')->get();

        return [
            'global' => $rows->whereNull('branch_id')->first()?->period,
            'branches' => $rows->whereNotNull('branch_id')->mapWithKeys(fn ($r) => [(int) $r->branch_id => $r->period])->all(),
        ];
    }

    /** Latest closed month for a branch: max(global, own branch locks), '' when nothing is closed. */
    public static function throughFor(array $closedThrough, int $branchId): string
    {
        return max((string) $closedThrough['global'], (string) ($closedThrough['branches'][$branchId] ?? ''));
    }

    /**
     * Reviewer R7: months that become open again if this (active) lock is unlocked, per branch.
     * Locks are cumulative, so unlocking October reopens every month back to the previous lock of the same scope.
     *
     * @return array<int, array{branch_id: int, code: string, from: string|null, to: string}> from null = no earlier lock
     */
    public function reopens(): array
    {
        $before = static::closedThrough();
        $after = static::closedThrough($this->id);
        $branches = $this->branch_id ? Branch::whereKey($this->branch_id)->get() : Branch::orderByDesc('is_main')->orderBy('code')->get();
        $out = [];
        foreach ($branches as $b) {
            $was = static::throughFor($before, $b->id);
            $now = static::throughFor($after, $b->id);
            if ($now < $was) {
                $out[] = ['branch_id' => $b->id, 'code' => $b->code,
                    'from' => $now === '' ? null : CarbonImmutable::createFromFormat('!Y-m', $now)->addMonth()->format('Y-m'), 'to' => $was];
            }
        }

        return $out;
    }

    /** One line for the unlock dialog / response. */
    public static function reopensText(array $reopens): string
    {
        if (! $reopens) {
            return 'No month reopens: every branch stays closed by its own lock.';
        }
        $parts = array_map(fn ($r) => $r['code'].': '.match (true) {
            $r['from'] === null => 'every month up to '.static::label($r['to']).' (no earlier lock)',
            $r['from'] === $r['to'] => static::label($r['to']),
            default => static::label($r['from']).' to '.static::label($r['to']),
        }, $reopens);

        return 'Reopens '.implode('; ', $parts).'.';
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
