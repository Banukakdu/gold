<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\BankReconciliation;
use Illuminate\Http\Request;

/**
 * AG-033 S1 bank reconciliation (docs/specs/bank-reconciliation.md section 5). {account} is bound through BranchScoped
 * (another branch's account is 404 in branch mode). Posts nothing.
 */
class BankReconciliationController extends Controller
{
    public function __construct(private BankReconciliation $recon) {}

    public function show(Request $request, Account $account)
    {
        $this->recon->assertBank($account);
        $d = $request->validate([
            'statement_date' => 'nullable|date_format:Y-m-d',
            'show_later' => 'nullable|boolean',
            'show_reconciled' => 'nullable|boolean',
        ]);

        return $this->recon->screen($account, $d['statement_date'] ?? null, (bool) ($d['show_later'] ?? false),
            (bool) ($d['show_reconciled'] ?? false), $request->user());
    }

    public function marks(Request $request, Account $account)
    {
        $this->recon->assertBank($account);
        $max = BankReconciliation::MAX_ITEMS;
        $d = $request->validate([
            'mark' => "nullable|array|max:{$max}",
            'mark.*' => 'array:entry_id,cleared_date',
            'mark.*.entry_id' => 'required|integer|distinct',
            'mark.*.cleared_date' => 'required|string',
            'unmark' => "nullable|array|max:{$max}",
            'unmark.*' => 'integer|distinct',
        ]);
        $mark = array_values($d['mark'] ?? []);
        $unmark = array_values($d['unmark'] ?? []);
        if (! $mark && ! $unmark) {
            return response()->json(['message' => 'Tick or untick at least one line.', 'errors' => ['mark' => ['Tick or untick at least one line.']]], 422);
        }

        return $this->recon->saveMarks($account, $mark, $unmark, $request->user());
    }

    public function complete(Request $request, Account $account)
    {
        $this->recon->assertBank($account);
        $d = $request->validate(['statement_date' => 'required|string', 'statement_balance' => 'required'],
            ['statement_balance.required' => BankReconciliation::BALANCE_MESSAGE]);
        // A decimal string (or a whole number); never a float.
        if (! is_string($d['statement_balance']) && ! is_int($d['statement_balance'])) {
            BankReconciliation::fail(BankReconciliation::BALANCE_MESSAGE);
        }
        $s = $this->recon->complete($account, $d['statement_date'], (string) $d['statement_balance'], $request->user());

        return response()->json($this->recon->present($s), 201);
    }

    public function undo(Request $request, Account $account, int $statementId)
    {
        $this->recon->assertBank($account);
        $request->merge(['reason' => is_string($request->input('reason')) ? trim($request->input('reason')) : $request->input('reason')]);
        $d = $request->validate(['reason' => 'required|string|min:10|max:500']);
        $s = $this->recon->undo($account, $statementId, $d['reason'], $request->user());

        return $this->recon->present($s);
    }
}
