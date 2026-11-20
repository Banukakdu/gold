<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Services\ReportService;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Double-entry S1 / section 5: the chart of accounts of one branch, grouped by class. Structure only: no balances
 * (income and expense balances are profit information; they are on the trial balance).
 */
class ChartOfAccountsController extends Controller
{
    public function __invoke(Request $request)
    {
        $d = $request->validate(['branch_id' => 'nullable|integer|exists:branches,id', 'include_inactive' => 'nullable|boolean']);
        $ctx = app(BranchContext::class);
        $requested = isset($d['branch_id']) ? (int) $d['branch_id'] : null;
        if ($ctx->isScoped()) {
            if ($requested !== null && $requested !== $ctx->branchId) {
                abort(403, 'You do not have access to branch '.Branch::find($requested)->code.'.');
            }
            $branchId = $ctx->branchId;
        } elseif ($requested === null) {
            throw ValidationException::withMessages(['branch_id' => 'Select a branch.']);
        } else {
            $branchId = $requested;
        }
        $branch = Branch::findOrFail($branchId);
        $withInactive = (bool) ($d['include_inactive'] ?? true);

        $accounts = Account::withoutGlobalScopes()->with('counterpartyBranch:id,code')->where('branch_id', $branch->id)
            ->when(! $withInactive, fn ($q) => $q->where('status', 'active'))
            ->orderBy('code')->orderBy('id')->get();
        $parents = Category::whereIn('id', $accounts->pluck('category_id')->filter())->pluck('parent_id', 'id');

        $groups = [];
        foreach (array_keys(ReportService::TB_GROUPS) as $g) {
            $groups[] = [
                'group' => $g,
                'label' => ReportService::TB_GROUPS[$g],
                'accounts' => $accounts->filter(fn (Account $a) => $a->group() === $g)->map(fn (Account $a) => [
                    'id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name,
                    'class' => $a->class,
                    'type' => $a->type,
                    'origin' => $a->isMoney() ? 'accounts' : ($a->type === 'category' ? 'category' : 'system'),
                    'category_id' => $a->category_id,
                    'parent_category_id' => $a->category_id ? ($parents[$a->category_id] ?? null) : null,
                    'counterparty_branch' => $a->counterpartyBranch ? ['id' => $a->counterpartyBranch->id, 'code' => $a->counterpartyBranch->code] : null,
                    'status' => $a->status,
                ])->values()->all(),
            ];
        }

        return [
            'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name, 'status' => $branch->status, 'is_main' => $branch->is_main],
            'groups' => $groups,
        ];
    }
}
