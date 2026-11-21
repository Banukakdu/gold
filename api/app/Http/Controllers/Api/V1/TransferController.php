<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Support\BusinessDate;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    /** AG-043: owner capital into a Main account. Super Admin only. */
    public function capital(Request $request)
    {
        $d = $request->validate(['account_id' => 'required|integer', 'amount' => 'required|numeric|gt:0|decimal:0,2', 'txn_date' => 'required|date_format:Y-m-d|before_or_equal:'.BusinessDate::today(), 'note' => 'nullable|string|max:500']);

        return response()->json($this->ledger->capital($d['account_id'], (string) $d['amount'], $d['txn_date'], $d['note'] ?? null), 201);
    }

    /** AG-040 / AG-041: Main -> branch opening balance or top-up. Super Admin only. */
    public function allocate(Request $request)
    {
        $d = $request->validate([
            'from_account_id' => 'required|integer', 'to_account_id' => 'required|integer',
            'amount' => 'required|numeric|gt:0|decimal:0,2', 'txn_date' => 'required|date_format:Y-m-d|before_or_equal:'.BusinessDate::today(),
            'kind' => 'required|in:opening,top_up', 'note' => 'nullable|string|max:500',
        ]);
        $from = Account::withoutGlobalScopes()->with('branch')->findOrFail($d['from_account_id']);
        $to = Account::withoutGlobalScopes()->with('branch')->findOrFail($d['to_account_id']);
        abort_unless($from->branch->is_main, 422, 'Funds must come from a Main branch account.');
        abort_if($to->branch->is_main, 422, 'Destination must be a branch account.');

        $type = $d['kind'] === 'opening' ? Transaction::OPENING_ALLOCATION : Transaction::FUND_ALLOCATION;

        return response()->json($this->ledger->transfer($from->id, $to->id, (string) $d['amount'], $d['txn_date'], $d['note'] ?? null, $type), 201);
    }

    /** AG-050 / AG-053 / AG-042: transfer from an account of the active branch (any branch for Super Admin) to any account. */
    public function transfer(Request $request)
    {
        $d = $request->validate([
            'from_account_id' => 'required|integer', 'to_account_id' => 'required|integer|different:from_account_id',
            'amount' => 'required|numeric|gt:0|decimal:0,2', 'txn_date' => 'required|date_format:Y-m-d|before_or_equal:'.BusinessDate::today(),
            'note' => 'required|string|max:500',
        ]);
        $from = Account::with('branch')->findOrFail($d['from_account_id']); // branch-scoped: must be own branch
        $to = Account::withoutGlobalScopes()->with('branch')->findOrFail($d['to_account_id']);
        // Fund return G4 / R9: Main -> branch is an allocation, Super Admin only. Checked before anything is posted.
        if ($from->branch->is_main && ! $to->branch->is_main && ! $request->user()->is_super_admin) {
            abort(403, self::MAIN_TO_BRANCH);
        }

        $txn = $this->ledger->transfer($d['from_account_id'], $d['to_account_id'], (string) $d['amount'], $d['txn_date'], $d['note']);
        // AG-051 section 5: status pending | posted decides the toast; R17 warning (never a block).
        $txn->setAttribute('warning', $txn->requestWarning);

        return response()->json($txn, 201);
    }

    public const MAIN_TO_BRANCH = 'Only a Super Admin can send funds from Main to a branch. Use "Fund a branch".';

    /**
     * Types only a Super Admin may reverse (controls review finding 8). Capital and allocations are Super Admin only to
     * post; a fund return's reversal credits Main's money account, i.e. sends Main's money back to the branch
     * (fund return G5, accounting review MUST 2).
     */
    public const SUPER_ADMIN_TYPES = [Transaction::CAPITAL, Transaction::OPENING_ALLOCATION, Transaction::FUND_ALLOCATION, Transaction::FUND_RETURN];

    /** AG-054 */
    public function reverse(Request $request, Transaction $transaction)
    {
        abort_if($transaction->type === Transaction::YEAR_END_CLOSE, 422, LedgerService::YEAR_END_LOCKED);
        $user = $request->user();
        if (in_array($transaction->type, self::SUPER_ADMIN_TYPES, true) && ! $user->is_super_admin) {
            abort(403, 'Only a Super Admin can reverse this entry.');
        }
        // Reversing income/expense needs the same right as voiding it.
        if (in_array($transaction->type, [Transaction::INCOME, Transaction::EXPENSE], true) && ! $user->canDo("{$transaction->type}.void")) {
            abort(403, "Missing permission: {$transaction->type}.void");
        }
        // Controls R8 / finding 4: not before the original, not in the future.
        $d = $request->validate([
            'reason' => 'required|string|max:500',
            'txn_date' => 'nullable|date_format:Y-m-d|after_or_equal:'.$transaction->txn_date->format('Y-m-d').'|before_or_equal:'.BusinessDate::today(),
        ], ['txn_date.after_or_equal' => 'The reversal date cannot be before the original entry date ('.$transaction->txn_date->format('Y-m-d').').']);

        return response()->json($this->ledger->reverse($transaction, $d['txn_date'] ?? BusinessDate::today(), $d['reason']), 201);
    }
}
