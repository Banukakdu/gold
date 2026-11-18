<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Services\LedgerService;
use Illuminate\Http\Request;
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

        return $q->get();
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
        ]);
        if ($branch->is_main && ($data['status'] ?? 'active') !== 'active') {
            return response()->json(['message' => 'The Main branch cannot be deactivated.'], 422);
        }
        $old = $branch->only(array_keys($data));
        $branch->update($data);
        AuditLog::record('branch.updated', $branch, $old, $data, $branch->id);

        return $branch;
    }
}
