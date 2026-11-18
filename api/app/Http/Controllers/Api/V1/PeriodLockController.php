<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\User;
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
    public function index(Request $request)
    {
        $year = (int) $request->validate(['year' => 'required|integer|min:2000|max:2100'])['year'];

        $locks = PeriodLock::with(['lockedBy:id,name,status,is_super_admin', 'unlockedBy:id,name,status,is_super_admin'])
            ->where('period', 'like', "{$year}-%")->orderBy('period')->orderBy('id')->get();

        return [
            'year' => $year,
            'current_period' => now()->format('Y-m'),
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
            ])->values(),
        ];
    }

    /**
     * Finding 7: count of self-voids / self-reversals of entries dated in the month, for the lock confirm.
     * Information only; it never blocks the lock.
     */
    public function review(Request $request)
    {
        $d = $request->validate(['period' => 'required|date_format:Y-m', 'branch_id' => 'nullable|integer|exists:branches,id']);
        $start = CarbonImmutable::createFromFormat('!Y-m', $d['period']);

        $rows = AuditLog::whereIn('event', ['transaction.voided', 'transaction.reversed'])
            ->where('auditable_type', Transaction::class)
            ->when($d['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            // An entry dated in the month can only be voided or reversed from that month on.
            ->where('created_at', '>=', $start->subDay()->format('Y-m-d H:i:s'))
            ->get(['id', 'event', 'auditable_id', 'new_values']);
        $rows = $rows->filter(fn ($r) => ($r->new_values['self'] ?? false) === true);

        // Voids are dated on the original entry; reversals on the reversal entry.
        $dates = Transaction::withoutGlobalScopes()
            ->whereIn('id', $rows->map(fn ($r) => $r->event === 'transaction.voided' ? $r->auditable_id : ($r->new_values['reversal_id'] ?? 0)))
            ->pluck('txn_date', 'id');
        $inMonth = fn ($r) => ($date = $dates[$r->event === 'transaction.voided' ? $r->auditable_id : ($r->new_values['reversal_id'] ?? 0)] ?? null)
            && $date->format('Y-m') === $d['period'];

        return [
            'period' => $d['period'],
            'branch_id' => $d['branch_id'] ?? null,
            'self_voids' => $rows->where('event', 'transaction.voided')->filter($inMonth)->count(),
            'self_reversals' => $rows->where('event', 'transaction.reversed')->filter($inMonth)->count(),
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
        abort_if($period > now()->format('Y-m'), 422, 'Future months cannot be locked.');
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

                $lock = PeriodLock::create(['branch_id' => $branchId, 'period' => $period, 'locked_by' => $request->user()->id]);
                AuditLog::record('period.locked', $lock, null, ['period' => $period, 'branch_id' => $branchId], $branchId);

                return $lock;
            });
        } catch (QueryException $e) {
            // Two simultaneous requests: the unique index on active locks refuses the second insert (finding 13).
            if (str_contains(strtolower($e->getMessage()), 'unique') || $e->getCode() === '23000') {
                abort(422, "{$label} is already locked {$scope}.");
            }
            throw $e;
        }

        return response()->json($lock->fresh(), 201);
    }

    public function unlock(Request $request, PeriodLock $periodLock)
    {
        $reason = $request->validate(['reason' => 'required|string|min:10|max:500'])['reason'];

        DB::transaction(function () use ($request, $periodLock, $reason) {
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

            $lock->update(['unlocked_at' => now(), 'unlocked_by' => $request->user()->id, 'unlock_reason' => $reason]);
            AuditLog::record('period.unlocked', $lock,
                ['period' => $lock->period, 'branch_id' => $lock->branch_id, 'locked_by' => $lock->locked_by, 'locked_at' => $lock->created_at?->toIso8601String()],
                ['reason' => $reason, 'unlocked_by' => $request->user()->id], $lock->branch_id);
        });

        return $periodLock->fresh();
    }

    private function person(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name, 'tag' => $u->status !== 'active' ? 'inactive' : null] : null;
    }
}
