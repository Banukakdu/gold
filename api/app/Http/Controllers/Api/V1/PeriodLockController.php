<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\LedgerChecks;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AG-091 period locks (controls spec S2, R7 + accounting review findings 1, 7, 13). Super Admin only (`super` middleware).
 * Locks are cumulative per scope; unlocks keep the row as history and go from the latest month down.
 */
class PeriodLockController extends Controller
{
    /** Deadlock retries for lock changes (reviewer R6). */
    public const ATTEMPTS = 3;

    /**
     * Reviewer R11 / spec section 2: read-only lock state of the active branch for everyone (banner on entry screens).
     * Null in All branches mode.
     */
    public function status()
    {
        $ctx = app(BranchContext::class);
        if (! $ctx->isScoped()) {
            return ['branch_id' => null, 'closed_through' => null, 'label' => null];
        }
        $through = PeriodLock::throughFor(PeriodLock::closedThrough(), $ctx->branchId);

        return ['branch_id' => $ctx->branchId, 'closed_through' => $through ?: null, 'label' => $through ? PeriodLock::label($through) : null];
    }

    public function index(Request $request)
    {
        $year = (int) $request->validate(['year' => 'required|integer|min:2000|max:2100'])['year'];

        $locks = PeriodLock::with(['lockedBy:id,name,status,is_super_admin', 'unlockedBy:id,name,status,is_super_admin'])
            ->where('period', 'like', "{$year}-%")->orderBy('period')->orderBy('id')->get();

        return [
            'year' => $year,
            'current_period' => BusinessDate::currentPeriod(),
            'branches' => Branch::orderByDesc('is_main')->orderBy('code')->get(['id', 'code', 'name', 'status']),
            'closed_through' => PeriodLock::closedThrough(),
            'locks' => $locks->map(fn (PeriodLock $l) => [
                'id' => $l->id,
                'branch_id' => $l->branch_id,
                'period' => $l->period,
                'locked_by' => $this->person($l->lockedBy),
                'locked_at' => $l->created_at,
                'unlocked_at' => $l->unlocked_at,
                'unlocked_by' => $this->person($l->unlockedBy),
                'unlock_reason' => $l->unlock_reason,
                // R7: what an unlock would reopen (shown in the unlock dialog).
                'reopens' => $l->unlocked_at ? null : PeriodLock::reopensText($l->reopens()),
            ])->values(),
        ];
    }

    /**
     * Finding 7: count of self-voids / self-reversals of entries dated in the month, for the lock confirm, and
     * (AG-062 finding 5) files removed from those entries, by the uploader or by someone else.
     * Information only; it never blocks the lock.
     */
    public function review(Request $request)
    {
        $d = $request->validate(['period' => 'required|date_format:Y-m', 'branch_id' => 'nullable|integer|exists:branches,id']);
        $period = $d['period'];

        // Locks are cumulative: this lock newly closes, per branch, every month after that branch's current
        // "closed through" up to $period (accountant sign-off). Count self-voids/reversals in all of them.
        $through = PeriodLock::closedThrough();
        $branches = ($d['branch_id'] ?? null) ? Branch::whereKey($d['branch_id'])->get() : Branch::all();
        $after = $branches->mapWithKeys(fn ($b) => [$b->id => PeriodLock::throughFor($through, $b->id)]); // '' = nothing closed yet
        $open = $after->filter(fn ($t) => $t < $period);

        $rows = AuditLog::whereIn('event', ['transaction.voided', 'transaction.reversed', 'attachment.removed'])
            ->where('auditable_type', Transaction::class)
            ->whereIn('branch_id', $open->keys())
            ->get(['id', 'event', 'branch_id', 'auditable_id', 'new_values']);

        // Voids and file removals are dated on the original entry; reversals on the reversal entry.
        $dated = fn ($r) => $r->event === 'transaction.reversed' ? ($r->new_values['reversal_id'] ?? 0) : $r->auditable_id;
        $dates = Transaction::withoutGlobalScopes()->whereIn('id', $rows->map($dated))->pluck('txn_date', 'id');
        $newlyClosed = function ($r) use ($dates, $dated, $open, $period) {
            $month = isset($dates[$dated($r)]) ? $dates[$dated($r)]->format('Y-m') : null;

            return $month !== null && $month <= $period && $month > $open[$r->branch_id];
        };
        $rows = $rows->filter($newlyClosed);
        $self = fn ($r) => ($r->new_values['self'] ?? false) === true;
        $first = $open->isEmpty() ? $period : $open->min();
        $files = $rows->where('event', 'attachment.removed');

        return [
            'period' => $period,
            'branch_id' => $d['branch_id'] ?? null,
            // First month this lock newly closes; null = every earlier month (no earlier lock).
            'closes_from' => $open->isEmpty() ? $period : ($first === '' ? null : CarbonImmutable::createFromFormat('!Y-m', $first)->addMonth()->format('Y-m')),
            'self_voids' => $rows->where('event', 'transaction.voided')->filter($self)->count(),
            'self_reversals' => $rows->where('event', 'transaction.reversed')->filter($self)->count(),
            // AG-062 finding 5: evidence removed from entries in the months being closed (information only).
            'files_removed' => $files->count(),
            'files_removed_self' => $files->filter($self)->count(),
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'period' => 'required|date_format:Y-m',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);
        $period = $d['period'];
        $branchId = $d['branch_id'] ?? null;
        $label = PeriodLock::label($period);
        abort_if($period > BusinessDate::currentPeriod(), 422, 'Future months cannot be locked.');
        $branch = $branchId ? Branch::find($branchId) : null;
        $scope = $branch ? "for {$branch->code}" : 'for all branches';

        try {
            $lock = DB::transaction(function () use ($request, $period, $branchId, $label, $branch, $scope) {
                // Serialise lock changes on the branch row (MySQL); the active-lock unique index is the last word.
                if ($branch) {
                    Branch::whereKey($branch->id)->lockForUpdate()->first();
                }
                $active = PeriodLock::active()->lockForUpdate()
                    ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($w) => $w->orWhere('branch_id', $branchId)))
                    ->where('period', '>=', $period)->get();

                abort_if($active->where('period', $period)->where('branch_id', $branchId)->isNotEmpty(), 422, "{$label} is already locked {$scope}.");
                abort_if($active->where('period', $period)->whereNull('branch_id')->isNotEmpty(), 422, "{$label} is already locked for all branches.");
                $later = $active->where('period', '>', $period)->sortByDesc('period')->first();
                if ($later) {
                    abort(422, "{$label} is already closed {$scope} (locked through ".PeriodLock::label($later->period).').');
                }

                // Double-entry R9: no month closes while an entry dated in it (or earlier, locks are cumulative) is not balanced.
                $through = CarbonImmutable::createFromFormat('!Y-m', $period)->endOfMonth()->format('Y-m-d');
                $unbalanced = LedgerChecks::unbalanced($branchId, $through)->pluck('transaction_id')->unique()->count();
                abort_if($unbalanced > 0, 422, "{$unbalanced} entries up to {$label} are not balanced. Run ledger:verify.");

                $lock = PeriodLock::create(['branch_id' => $branchId, 'period' => $period, 'locked_by' => $request->user()->id]);
                AuditLog::record('period.locked', $lock, null, ['period' => $period, 'branch_id' => $branchId], $branchId);

                return $lock;
            }, self::ATTEMPTS);
        } catch (QueryException $e) {
            // Two simultaneous requests: the unique index on active locks refuses the second insert (finding 13).
            if (str_contains(strtolower($e->getMessage()), 'unique') || $e->getCode() === '23000') {
                abort(422, "{$label} is already locked {$scope}.");
            }
            self::abortIfConcurrency($e);
            throw $e;
        }

        return response()->json($lock->fresh(), 201);
    }

    public function unlock(Request $request, PeriodLock $periodLock)
    {
        $reason = $request->validate(['reason' => 'required|string|min:10|max:500'])['reason'];

        try {
            $reopens = DB::transaction(fn () => $this->doUnlock($request, $periodLock, $reason), self::ATTEMPTS);
        } catch (QueryException $e) {
            self::abortIfConcurrency($e);
            throw $e;
        }

        return $periodLock->fresh()->toArray() + ['reopened' => $reopens, 'message' => PeriodLock::reopensText($reopens)];
    }

    /** @return array reopened months (R7) */
    private function doUnlock(Request $request, PeriodLock $periodLock, string $reason): array
    {
        $lock = PeriodLock::lockForUpdate()->findOrFail($periodLock->id);
        abort_if($lock->unlocked_at !== null, 422, 'This lock is already unlocked.');
        $label = PeriodLock::label($lock->period);

        if ($lock->branch_id !== null) {
            $global = PeriodLock::active()->whereNull('branch_id')->where('period', $lock->period)->exists();
            abort_if($global, 422, "{$label} is also locked for all branches. Unlock all branches first.");
        }
        // Finding 1: unlock from the latest month down. A later lock of the same scope (for a branch lock, a
        // later global lock too) keeps this month closed, so it must be unlocked first.
        $later = PeriodLock::active()->where('period', '>', $lock->period)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($lock->branch_id, fn ($w) => $w->orWhere('branch_id', $lock->branch_id)))
            ->orderByDesc('period')->orderByRaw('branch_id IS NOT NULL')->first();
        if ($later) {
            $scope = $later->branch_id ? 'for '.Branch::find($later->branch_id)->code : 'for all branches';
            abort(422, 'Unlock '.PeriodLock::label($later->period)." {$scope} first.");
        }

        $reopens = $lock->reopens();
        $lock->update(['unlocked_at' => now(), 'unlocked_by' => $request->user()->id, 'unlock_reason' => $reason]);
        AuditLog::record('period.unlocked', $lock,
            ['period' => $lock->period, 'branch_id' => $lock->branch_id, 'locked_by' => $lock->locked_by, 'locked_at' => $lock->created_at?->toIso8601String()],
            ['reason' => $reason, 'unlocked_by' => $request->user()->id, 'reopened' => $reopens], $lock->branch_id);

        return $reopens;
    }

    /**
     * Reviewer R6: two simultaneous lock changes can deadlock on MySQL gap locks (1213 / SQLSTATE 40001).
     * DB::transaction retries them (ATTEMPTS); if they still collide, answer 422 instead of 500.
     */
    private static function abortIfConcurrency(QueryException $e): void
    {
        if ($msg = self::concurrencyMessage($e)) {
            abort(422, $msg);
        }
    }

    public static function concurrencyMessage(QueryException $e): ?string
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $deadlock = $e->getCode() === '40001' || in_array($code, ['1213', '1205'], true) || str_contains(strtolower($e->getMessage()), 'deadlock');

        return $deadlock ? 'Another period lock change was being saved at the same time. Reload the page and try again.' : null;
    }

    private function person(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name, 'tag' => $u->status !== 'active' ? 'inactive' : null] : null;
    }
}
