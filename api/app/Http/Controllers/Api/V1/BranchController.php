<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Services\ExpenseApprovals;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $q = Branch::query()
            // Double-entry R8: money accounts only.
            ->withSum(['accounts as balance' => fn ($a) => $a->withoutGlobalScopes()->money()], 'current_balance')
            ->orderByDesc('is_main')->orderBy('name');
        if (! $user->is_super_admin) {
            $q->whereIn('id', $user->branches()->pluck('branches.id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        // AG-064: the expense approval limit, read-only for branch users (S1).
        return $q->get()->each(fn (Branch $b) => $b->setAttribute('expense_approval_threshold', $b->expenseApprovalThreshold()));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|alpha_num|max:10|unique:branches,code',
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
        ]);

        return DB::transaction(function () use ($data) {
            $branch = Branch::create($data + ['status' => 'active']);
            // AG-011: every branch starts with a zero-balance Cash account
            Account::create(['branch_id' => $branch->id, 'name' => 'Cash', 'type' => 'cash']);
            AuditLog::record('branch.created', $branch, null, $data, $branch->id);

            // N7: the new branch's cash, bank and wallet accounts, not its whole chart.
            return response()->json($branch->load(['accounts' => fn ($q) => $q->money()]), 201);
        }, LedgerService::ATTEMPTS);
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'code' => ['sometimes', 'alpha_num', 'max:10', Rule::unique('branches', 'code')->ignore($branch->id)],
            'name' => 'sometimes|string|max:255',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'status' => 'sometimes|in:active,inactive',
            // AG-064 S1: null / "" = never; 0 = every expense; up to 9,999,999,999,999.99, 2 decimals, no floats.
            'expense_approval_threshold' => ['sometimes', 'nullable', function (string $a, mixed $v, \Closure $fail) {
                if (! is_string($v) && ! is_int($v)) {
                    $fail(self::THRESHOLD_INVALID);
                } elseif (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', trim((string) $v))) {
                    $fail(self::THRESHOLD_INVALID);
                }
            }],
        ], ['expense_approval_threshold' => self::THRESHOLD_INVALID]);
        if ($branch->is_main && ($data['status'] ?? 'active') !== 'active') {
            return response()->json(['message' => 'The Main branch cannot be deactivated.'], 422);
        }
        $fields = Arr::except($data, ['expense_approval_threshold']);
        $old = $branch->only(array_keys($fields));
        $new = $fields;
        if (array_key_exists('expense_approval_threshold', $data)) {
            $v = $data['expense_approval_threshold'];
            $cents = $v === null || trim((string) $v) === '' ? null : Money::toCents(trim((string) $v));
            $old['expense_approval_threshold'] = $branch->expenseApprovalThreshold();
            $new['expense_approval_threshold'] = $cents === null ? null : Money::fromCents($cents);
            $branch->forceFill(['expense_approval_threshold_cents' => $cents]);
        }
        $branch->fill($fields)->save();
        AuditLog::record('branch.updated', $branch, $old, $new, $branch->id);

        $branch->setAttribute('expense_approval_threshold', $branch->expenseApprovalThreshold());
        // R18: information only, nothing is refused.
        $branch->setAttribute('expense_approval_warning', $branch->expenseApprovalThresholdCents() !== null
            && ! app(ExpenseApprovals::class)->branchHasApprover($branch) ? ExpenseApprovals::noApproverWarning($branch) : null);

        return $branch;
    }

    public const THRESHOLD_INVALID = 'Enter an amount of 0 or more with up to 2 decimals, or leave it empty.';
}
