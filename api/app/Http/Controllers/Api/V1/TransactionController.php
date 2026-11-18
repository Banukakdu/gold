<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    /** AG-065: filterable, paginated list. */
    public function index(Request $request)
    {
        $f = $request->validate([
            'from' => 'nullable|date', 'to' => 'nullable|date',
            'type' => 'nullable|string', 'status' => 'nullable|string',
            'account_id' => 'nullable|integer', 'category_id' => 'nullable|integer',
            'created_by' => 'nullable|integer', 'min' => 'nullable|numeric', 'max' => 'nullable|numeric',
            'q' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|max:200',
            'branches' => 'nullable|array', 'branches.*' => 'integer|exists:branches,id', 'category_only' => 'nullable|boolean',
        ]);
        // Reports spec E1: branches[] narrows All mode; in branch mode it may only name the active branch.
        $ctx = app(BranchContext::class);
        $branches = array_map('intval', $f['branches'] ?? []);
        if ($ctx->isScoped()) {
            foreach ($branches as $id) {
                if ($id !== $ctx->branchId) {
                    abort(403, 'You do not have access to branch '.Branch::find($id)->code.'.');
                }
            }
        }
        $categoryOnly = (bool) ($f['category_only'] ?? false);

        $q = Transaction::with(['branch:id,code,name', 'category:id,name,parent_id', 'category.parent:id,name',
            'account:id,name', 'fromAccount:id,name,branch_id', 'toAccount:id,name,branch_id', 'creator:id,name,status,is_super_admin'])
            ->latest('txn_date')->latest('id');

        $q->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('txn_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('txn_date', '<=', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->whereIn('type', explode(',', $v)))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['account_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('account_id', $v)->orWhere('from_account_id', $v)->orWhere('to_account_id', $v)))
            ->when($branches, fn ($q, $v) => $q->whereIn('transactions.branch_id', $v))
            ->when($f['category_id'] ?? null, fn ($q, $v) => $categoryOnly
                ? $q->where('category_id', $v)
                : $q->where(fn ($w) => $w->where('category_id', $v)->orWhereIn('category_id', fn ($s) => $s->select('id')->from('categories')->where('parent_id', $v))))
            ->when($f['created_by'] ?? null, fn ($q, $v) => $q->where('created_by', $v))
            ->when($f['min'] ?? null, fn ($q, $v) => $q->where('amount', '>=', $v))
            ->when($f['max'] ?? null, fn ($q, $v) => $q->where('amount', '<=', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('ref_no', 'like', "%$v%")->orWhere('description', 'like', "%$v%")->orWhere('payee_payer', 'like', "%$v%")->orWhere('reference', 'like', "%$v%")));

        $page = $q->paginate($f['per_page'] ?? 25);
        $this->tagAuthors($page->getCollection());

        return $page;
    }

    public function show(Transaction $transaction)
    {
        $transaction->load(['entries', 'branch', 'category.parent', 'account', 'fromAccount.branch', 'toAccount.branch', 'creator:id,name,status,is_super_admin']);
        $this->tagAuthors(collect([$transaction]));

        return $transaction;
    }

    /** Options for the "Created by" filter: everyone who created an entry here, former members included (finding 3). */
    public function creators()
    {
        $rows = Transaction::query()->select('branch_id', 'created_by')->distinct()->get();
        $ids = $rows->pluck('created_by')->unique()->values();
        $members = $this->memberships($ids->all());
        $users = User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'status', 'is_super_admin']);

        return $users->map(function (User $u) use ($rows, $members) {
            $branches = $rows->where('created_by', $u->id)->pluck('branch_id');
            $tag = $u->status !== 'active' ? 'inactive'
                : (! $u->is_super_admin && $branches->contains(fn ($b) => ! isset($members[$u->id.':'.$b])) ? 'no longer in this branch' : null);

            return ['id' => $u->id, 'name' => $u->name, 'tag' => $tag];
        })->values();
    }

    public function storeIncome(Request $request)
    {
        return response()->json($this->ledger->incomeOrExpense(Transaction::INCOME, $this->validated($request)), 201);
    }

    public function storeExpense(Request $request)
    {
        return response()->json($this->ledger->incomeOrExpense(Transaction::EXPENSE, $this->validated($request)), 201);
    }

    /** AG-063: edit descriptive fields (amount/account changes = void and re-enter, keeps the ledger simple and auditable). */
    public function update(Request $request, Transaction $transaction)
    {
        abort_unless(in_array($transaction->type, [Transaction::INCOME, Transaction::EXPENSE]) && $transaction->status === 'posted', 422, 'This entry cannot be edited.');
        abort_if(PeriodLock::isLocked($transaction->branch_id, $transaction->txn_date->format('Y-m-d')), 422, 'The period is locked.');
        $request->user()->canDo("{$transaction->type}.edit") || abort(403, "Missing permission: {$transaction->type}.edit");

        $data = $request->validate([
            'payee_payer' => 'nullable|string|max:255', 'payment_method' => 'nullable|string|max:30',
            'reference' => 'nullable|string|max:255', 'description' => 'nullable|string|max:2000',
            'category_id' => 'sometimes|exists:categories,id',
        ]);
        $old = $transaction->only(array_keys($data));
        $transaction->update($data);
        AuditLog::record('transaction.updated', $transaction, $old, $data);

        return $transaction;
    }

    public function void(Request $request, Transaction $transaction)
    {
        $request->user()->canDo("{$transaction->type}.void") || abort(403, "Missing permission: {$transaction->type}.void");

        return $this->ledger->void($transaction, $request->validate(['reason' => 'required|string|max:500'])['reason']);
    }

    /** Finding 3 / AC 42: authors stay visible, tagged "(inactive)" or "(no longer in this branch)". */
    private function tagAuthors($txns): void
    {
        $members = $this->memberships($txns->pluck('created_by')->unique()->all());
        foreach ($txns as $t) {
            $u = $t->creator;
            $tag = null;
            if ($u && $u->status !== 'active') {
                $tag = 'inactive';
            } elseif ($u && ! $u->is_super_admin && ! isset($members[$u->id.':'.$t->branch_id])) {
                $tag = 'no longer in this branch';
            }
            $t->setAttribute('creator_tag', $tag);
        }
    }

    /** @return array<string, true> keyed "user_id:branch_id" */
    private function memberships(array $userIds): array
    {
        return DB::table('branch_user')->whereIn('user_id', $userIds)->get(['user_id', 'branch_id'])
            ->mapWithKeys(fn ($r) => [$r->user_id.':'.$r->branch_id => true])->all();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'account_id' => 'required|integer',
            'category_id' => 'required|exists:categories,id',
            'txn_date' => 'required|date|before_or_equal:today',
            'amount' => 'required|numeric|gt:0|decimal:0,2',
            'payee_payer' => 'nullable|string|max:255',
            'payment_method' => 'nullable|in:cash,bank_transfer,cheque,card,online',
            'reference' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);
        // account must belong to the active branch (branch scope -> 404 otherwise)
        Account::findOrFail($data['account_id']);
        $data['amount'] = (string) $data['amount'];

        return $data;
    }
}
