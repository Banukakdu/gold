<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementCheck;
use App\Services\BankReconciliation;
use App\Services\ReconciliationChecks;
use Illuminate\Http\Request;

/**
 * AG-033d "Checked by" (docs/specs/reconciliation-checked-by.md section 5). {account} is bound through BranchScoped;
 * {statement} and {check} are scoped bindings (another branch's statement, a statement of another account, or a check of
 * another statement is 404). Guarded by `perm:report.view,account.reconcile_check`. There is no DELETE and no edit route.
 * Posts nothing.
 */
class ReconciliationCheckController extends Controller
{
    public function __construct(private ReconciliationChecks $checks, private BankReconciliation $recon) {}

    /** R3: 201 Check (+ `superseded` on a re-check). The rule order (and so each message) is in ReconciliationChecks::record. */
    public function store(Request $request, Account $account, BankStatement $statement)
    {
        $this->recon->assertBank($account);
        [$check, $superseded] = $this->checks->record($account, $statement, $request->only(['compared_with', 'confirmed', 'note']), $request->user());

        return response()->json($this->checks->present($check) + ($superseded ? ['superseded' => $this->checks->present($superseded)] : []), 201);
    }

    /** R7: 200 Check. */
    public function withdraw(Request $request, Account $account, BankStatement $statement, BankStatementCheck $check)
    {
        $this->recon->assertBank($account);
        // R7 who first (QA D5): a user who may not withdraw gets the 403, whatever the reason. Re-checked in the transaction.
        if ((int) $check->checked_by !== (int) $request->user()->id && ! $request->user()->is_super_admin) {
            BankReconciliation::fail(ReconciliationChecks::WITHDRAW_WHO, 403);
        }
        $request->merge(['reason' => is_string($request->input('reason')) ? trim($request->input('reason')) : $request->input('reason')]);
        $reason = $request->validate(['reason' => 'required|string|min:10|max:500'], [
            'reason.required' => ReconciliationChecks::REASON_MESSAGE,
            'reason.string' => ReconciliationChecks::REASON_MESSAGE,
            'reason.min' => ReconciliationChecks::REASON_MESSAGE,
            'reason.max' => ReconciliationChecks::REASON_LONG,
        ])['reason'];

        return $this->checks->present($this->checks->withdraw($account, $statement, $check, $reason, $request->user()));
    }
}
