<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Models\Branch;
use App\Models\Transaction;
use App\Support\BranchContext;
use App\Support\Money;
use App\Support\TransactionFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * AG-065 (docs/specs/transaction-search.md): the one query builder of the transaction list, its filter options and
 * its Excel export, so the export always holds exactly the rows of the list with the same filters and sort.
 */
class TransactionSearch
{
    public const TYPES = [Transaction::CAPITAL, Transaction::OPENING_ALLOCATION, Transaction::FUND_ALLOCATION, Transaction::FUND_RETURN,
        Transaction::BRANCH_TRANSFER, Transaction::INTERNAL_TRANSFER, Transaction::INCOME, Transaction::EXPENSE, Transaction::REVERSAL,
        Transaction::YEAR_END_CLOSE];

    /** R4: pending / rejected are reserved for AG-064 / AG-051. */
    public const STATUSES = ['posted', 'void', 'reversed'];

    public const PER_PAGE = ['25', '50', '100', '200'];

    public const AMOUNT = '/^\d{1,13}(\.\d{1,2})?$/';

    public const AMOUNT_ORDER = 'Amount to must be at least Amount from.';

    /** Validate (422) and resolve the scope set from BranchContext only (403 for another branch, finding 3). */
    public function params(array $input, bool $export = false): TransactionFilters
    {
        $rules = [
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d'],
            'type' => ['bail', 'nullable', 'string', $this->listRule('type', self::TYPES)],
            'status' => ['bail', 'nullable', 'string', $this->listRule('status', self::STATUSES)],
            'account_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'category_only' => 'nullable|boolean',
            // R7: integer only, no exists rule (the answer must not reveal whether a user id exists).
            'created_by' => 'nullable|integer',
            'min' => ['bail', 'nullable', 'regex:'.self::AMOUNT, $this->positive('Amount from')],
            'max' => ['bail', 'nullable', 'regex:'.self::AMOUNT, $this->positive('Amount to')],
            'q' => 'nullable|string|max:100',
            'files' => 'nullable|in:with,none,removed',
            'branches' => 'nullable|array',
            'branches.*' => 'integer|distinct|exists:branches,id',
            'sort' => 'nullable|in:date,amount',
            'dir' => 'nullable|in:asc,desc',
        ];
        $rules += $export
            ? ['format' => 'nullable|in:xlsx']
            : ['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|in:'.implode(',', self::PER_PAGE)];
        // R2: compared only with a well-formed From (a missing From is not a date to compare with).
        if (is_string($input['from'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['from'])) {
            $rules['to'][] = 'after_or_equal:from';
        }
        $d = validator($input, $rules, [
            'to.after_or_equal' => 'To must be on or after From.',
            'min.regex' => 'Amount from must be a number with up to 2 decimals (no commas).',
            'max.regex' => 'Amount to must be a number with up to 2 decimals (no commas).',
            'per_page.in' => 'Rows per page must be 25, 50, 100 or 200.',
        ])->validate();
        if (isset($d['min'], $d['max']) && Money::toCents($d['max']) < Money::toCents($d['min'])) {
            throw ValidationException::withMessages(['max' => self::AMOUNT_ORDER]);
        }

        $ctx = app(BranchContext::class);
        $requested = array_values(array_unique(array_map('intval', $d['branches'] ?? [])));
        if ($ctx->isScoped()) {
            // branches[] in branch mode may only name the active branch (unchanged, reports E1).
            foreach ($requested as $id) {
                if ($id !== $ctx->branchId) {
                    abort(403, 'You do not have access to branch '.Branch::find($id)->code.'.');
                }
            }
            $scope = [$ctx->branchId];
        } elseif ($ctx->allBranches) {
            // All mode (Super Admin only, SetBranchContext): branches[] narrows it.
            $scope = $requested ?: null;
        } else {
            abort(403, 'Select a branch (X-Branch-Id header).');
        }

        $filters = [];
        foreach (['from', 'to', 'type', 'status', 'account_id', 'category_id', 'category_only', 'created_by', 'min', 'max', 'q', 'files'] as $k) {
            $v = $d[$k] ?? null;
            if ($v !== null && $v !== '' && ! ($k === 'category_only' && ! filter_var($v, FILTER_VALIDATE_BOOLEAN))) {
                $filters[$k] = $v;
            }
        }
        if ($requested) {
            $filters['branches'] = $requested;
        }

        return new TransactionFilters($filters, $d['sort'] ?? 'date', $d['dir'] ?? 'desc', $scope, ! $ctx->isScoped(), $ctx->branchId,
            (int) ($d['per_page'] ?? 25), (int) ($d['page'] ?? 1));
    }

    /** Rows in scope (R10), filtered (R1-R9) and sorted (R12). */
    public function query(TransactionFilters $p): Builder
    {
        // R10: the only place the list drops the branch scope. The scope set comes from BranchContext (params()):
        // rows sent by a branch in the set, or received by one (top-ups, allocations, transfers and their reversals).
        $q = Transaction::withoutGlobalScope('branch');
        if ($p->scope !== null) {
            $q->where(fn ($w) => $w->whereIn('transactions.branch_id', $p->scope)->orWhereIn('transactions.related_branch_id', $p->scope));
        }

        $f = $p->filters;
        $q->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('transactions.txn_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('transactions.txn_date', '<=', $v))
            ->when($this->list($f['type'] ?? null), fn ($q, $v) => $q->whereIn('transactions.type', $v))
            ->when($this->list($f['status'] ?? null), fn ($q, $v) => $q->whereIn('transactions.status', $v))
            ->when($f['account_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('transactions.account_id', $v)
                ->orWhere('transactions.from_account_id', $v)->orWhere('transactions.to_account_id', $v)))
            ->when($f['category_id'] ?? null, fn ($q, $v) => isset($f['category_only'])
                ? $q->where('transactions.category_id', $v)
                : $q->where(fn ($w) => $w->where('transactions.category_id', $v)
                    ->orWhereIn('transactions.category_id', fn ($s) => $s->select('id')->from('categories')->where('parent_id', $v))))
            ->when($f['created_by'] ?? null, fn ($q, $v) => $q->where('transactions.created_by', $v));

        // R8: exact cents compare; a closing entry's amount is not money moved, so an amount bound never matches it.
        if (isset($f['min']) || isset($f['max'])) {
            $cents = Money::sqlCents('transactions.amount');
            $q->where('transactions.type', '!=', Transaction::YEAR_END_CLOSE)
                ->when($f['min'] ?? null, fn ($q, $v) => $q->whereRaw("$cents >= ?", [Money::toCents($v)]))
                ->when($f['max'] ?? null, fn ($q, $v) => $q->whereRaw("$cents <= ?", [Money::toCents($v)]));
        }

        // R9: one phrase, LIKE wildcards escaped with '!' (works on MySQL and SQLite).
        if (($text = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
            $q->where(fn ($w) => $w->whereRaw("transactions.ref_no like ? escape '!'", [$like])
                ->orWhereRaw("transactions.payee_payer like ? escape '!'", [$like])
                ->orWhereRaw("transactions.reference like ? escape '!'", [$like])
                ->orWhereRaw("transactions.description like ? escape '!'", [$like]));
        }

        // AG-062 finding 6: find entries without evidence, or with removed files. Only income/expense carry files.
        if ($files = $f['files'] ?? null) {
            $active = fn ($a) => $a->whereNull('removed_at');
            $q->whereIn('transactions.type', AttachmentController::ENTRY_TYPES);
            match ($files) {
                'with' => $q->whereHas('attachments', $active),
                'none' => $q->whereDoesntHave('attachments', $active),
                'removed' => $q->whereHas('attachments', fn ($a) => $a->whereNotNull('removed_at')),
            };
        }

        // R12: id as the last key makes the order total (stable pages); closing entries always last by amount.
        $dir = $p->dir;
        if ($p->sort === 'amount') {
            $q->orderByRaw("CASE WHEN transactions.type = '".Transaction::YEAR_END_CLOSE."' THEN 1 ELSE 0 END")
                ->orderBy('transactions.amount', $dir);
        } else {
            $q->orderBy('transactions.txn_date', $dir);
        }

        return $q->orderBy('transactions.id', $dir);
    }

    /** R7: users who created at least one row in the scope set (R10, branches[] included). */
    public function creatorRows(TransactionFilters $p)
    {
        $q = Transaction::withoutGlobalScope('branch');
        if ($p->scope !== null) {
            $q->where(fn ($w) => $w->whereIn('branch_id', $p->scope)->orWhereIn('related_branch_id', $p->scope));
        }

        return $q->select('branch_id', 'created_by')->distinct()->get();
    }

    /** @return string[]|null */
    private function list(?string $v): ?array
    {
        $items = array_values(array_filter(array_map('trim', explode(',', (string) $v)), fn ($x) => $x !== ''));

        return $items ?: null;
    }

    private function listRule(string $name, array $allowed): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($name, $allowed) {
            if (! is_string($value)) {
                return; // the string rule reports it (reviewer M2: never cast an array)
            }
            foreach ($this->list((string) $value) ?? [] as $v) {
                if (! in_array($v, $allowed, true)) {
                    $fail("Unknown {$name}: {$v}.");

                    return;
                }
            }
        };
    }

    private function positive(string $label): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($label) {
            if (Money::toCents((string) $value) < 1) {
                $fail("{$label} must be at least 0.01.");
            }
        };
    }
}
