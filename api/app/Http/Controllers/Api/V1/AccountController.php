<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Support\BranchContext;
use App\Support\Money;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $q = Account::with('branch:id,code,name')->orderBy('branch_id')->orderBy('name');
        if ($request->filled('branch_id')) {
            $q->where('branch_id', $request->integer('branch_id'));
        }

        return $q->get();
    }

    /** Accounts of every branch, for picking a transfer destination (no balances exposed). */
    public function destinations()
    {
        return Account::withoutGlobalScopes()->with('branch:id,code,name')
            ->where('status', 'active')->orderBy('branch_id')->get(['id', 'branch_id', 'name', 'type']);
    }

    public function store(Request $request)
    {
        $ctx = app(BranchContext::class);
        $data = $request->validate([
            'branch_id' => $ctx->isScoped() ? 'prohibited' : 'required|exists:branches,id',
            'name' => 'required|string|max:255',
            'type' => 'required|in:cash,bank,wallet',
            'bank_name' => 'nullable|string|max:255',
            'account_no' => 'nullable|string|max:50',
            'is_main_account' => 'boolean',
        ]);
        $data['branch_id'] ??= $ctx->branchId;
        $acc = Account::create($data);
        AuditLog::record('account.created', $acc, null, $data);

        return response()->json($acc, 201);
    }

    public function update(Request $request, Account $account)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'account_no' => 'nullable|string|max:50',
            'status' => 'sometimes|in:active,closed',
        ]);
        if (($data['status'] ?? null) === 'closed' && Money::cmp($account->current_balance, 0) !== 0) {
            return response()->json(['message' => 'Only accounts with a zero balance can be closed.'], 422);
        }
        $old = $account->only(array_keys($data));
        $account->update($data);
        AuditLog::record('account.updated', $account, $old, $data);

        return $account;
    }

    /** AG-032: statement with opening balance, running balance and closing balance. */
    public function statement(Request $request, Account $account)
    {
        $data = $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);

        $opening = (string) LedgerEntry::where('account_id', $account->id)->whereDate('entry_date', '<', $data['from'])
            ->selectRaw('COALESCE(SUM(debit - credit),0) b')->value('b');

        $entries = LedgerEntry::with('transaction:id,ref_no,type,description,payee_payer,category_id')
            ->where('account_id', $account->id)->whereDate('entry_date', '>=', $data['from'])->whereDate('entry_date', '<=', $data['to'])
            ->orderBy('entry_date')->orderBy('id')->get();

        $running = Money::toCents($opening);
        $rows = $entries->map(function ($e) use (&$running) {
            $running += Money::toCents($e->debit) - Money::toCents($e->credit);

            return [
                'date' => $e->entry_date->format('Y-m-d'),
                'ref_no' => $e->transaction->ref_no,
                'type' => $e->transaction->type,
                'description' => $e->transaction->description ?? $e->transaction->payee_payer,
                'in' => $e->debit,
                'out' => $e->credit,
                'balance' => Money::fromCents($running),
            ];
        });

        return [
            'account' => $account->load('branch:id,code,name'),
            'from' => $data['from'], 'to' => $data['to'],
            'opening_balance' => Money::add($opening),
            'entries' => $rows,
            'closing_balance' => Money::fromCents($running),
        ];
    }
}
