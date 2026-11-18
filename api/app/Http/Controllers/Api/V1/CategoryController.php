<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\YearEndClose;
use App\Services\ChartOfAccounts;
use App\Services\LedgerService;
use App\Support\BranchContext;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Income / expense categories (docs/specs/categories.md, AG-070/071/072). Categories change the chart, never the
 * ledger: no action here posts a line. Nothing is deleted (deactivate instead); every change writes a `category.*`
 * audit row in the same DB transaction (R8).
 */
class CategoryController extends Controller
{
    public const GLOBAL_ONLY = 'Only Super Admin can change global categories.';

    public const DUPLICATE = 'A category with this name already exists here.';

    public const TYPE_FIXED = 'The type of a category cannot be changed.';

    public const NO_MOVE = 'A sub-category cannot be moved to another category. Create it under the new category and change the category on each entry.';

    public const PARENT_INACTIVE = 'This category is inactive. Reactivate it before adding sub-categories.';

    public const TWO_LEVELS = 'Only two levels are allowed (Category > Sub-category).';

    public const PROMOTE_NOT_BRANCH = 'Only a branch-only sub-category can be made global.';

    public const PROMOTE_INACTIVE = 'Reactivate it before making it global.';

    public const PROMOTE_PARENT_INACTIVE = 'Reactivate it and its category before making it global.';

    public function __construct(private ChartOfAccounts $chart) {}

    /**
     * Without `manage`: the tree for entry forms (active only, global + the active branch's own; unchanged).
     * With `manage=1` (S1, `category.manage`): inactive rows too, every branch's branch-only subs in All mode, and per
     * node ledger_code, branch, usage_count, year_balance, can_edit and can_promote.
     */
    public function index(Request $request)
    {
        if ($request->boolean('manage')) {
            abort_unless($request->user()->canDo('category.manage'), 403, 'Missing permission: category.manage');

            return $this->manageTree($request);
        }

        $branchId = app(BranchContext::class)->branchId;
        $visible = fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->when($branchId, fn ($w) => $w->orWhere('branch_id', $branchId)))
            ->when(! $request->boolean('include_inactive'), fn ($w) => $w->where('is_active', true))
            ->orderBy('name');

        return Category::whereNull('parent_id')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->tap($visible)
            ->with(['children' => $visible])
            ->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'required_without:parent_id|in:income,expense',
            'parent_id' => 'nullable|integer|exists:categories,id',
        ]);
        $user = $request->user();
        $branchId = app(BranchContext::class)->branchId;
        $data['name'] = trim($data['name']);

        if (empty($data['parent_id'])) {
            abort_unless($user->is_super_admin, 403, 'Only Super Admin can create top-level categories.');
            $data['parent_id'] = null;
            $data['branch_id'] = null;
        } else {
            // R7: a Super Admin's sub-category is global; anyone else's is branch-only for the active branch (AG-071).
            abort_if(! $user->is_super_admin && ! $branchId, 422, 'Choose a branch first.');
            $data['branch_id'] = $user->is_super_admin ? null : $branchId;
        }

        // R3 / finding 6: the name check runs under the chart lock in the transaction of the write. R8: audit row.
        $category = DB::transaction(function () use ($data) {
            $this->chart->lockChart();
            if ($data['parent_id']) {
                $parent = Category::findOrFail($data['parent_id']);
                abort_if($parent->parent_id !== null, 422, self::TWO_LEVELS);
                abort_unless($parent->is_active, 422, self::PARENT_INACTIVE);
                $data['type'] = $parent->type; // R2: a sub's type is always its parent's
            }
            $this->assertUniqueName($data['type'], $data['parent_id'], $data['branch_id'], $data['name']);

            // Double-entry R1: the category's ledger accounts are created with it, in the same DB transaction.
            $category = Category::create($data);
            AuditLog::record('category.created', $category, null,
                $category->only(['name', 'type', 'parent_id', 'branch_id', 'ledger_code']));

            return $category;
        }, LedgerService::ATTEMPTS);

        return response()->json($this->presentOne($category->refresh(), $request), 201);
    }

    public function update(Request $request, Category $category)
    {
        // R1 first, so a 422 never reveals that another branch's row exists (review N2).
        $this->authorizeChange($request, $category);
        // R4 / R5: type, parent and branch are fixed from creation.
        abort_if($request->exists('type'), 422, self::TYPE_FIXED);
        abort_if($request->exists('parent_id') || $request->exists('branch_id'), 422, self::NO_MOVE);
        $data = $request->validate(['name' => 'sometimes|required|string|max:100', 'is_active' => 'sometimes|boolean']);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        // Renaming or (de)activating a category renames / (de)activates its ledger accounts (double-entry R1, R6).
        // Retried on a deadlock (review R3b): each attempt starts from the stored row, so the change is seen again.
        DB::transaction(function () use ($request, $category, $data) {
            $this->chart->lockChart();
            $category->refresh();
            // Review S1: re-check access on the stored row under the lock; a promote in between makes the row global
            // (Super Admin only), and the promote takes the same lock.
            $this->authorizeChange($request, $category);
            $changed = array_filter($data, fn ($v, $k) => $category->{$k} !== $v, ARRAY_FILTER_USE_BOTH);
            if (! $changed) {
                return;
            }
            if (isset($changed['name'])) {
                $this->assertUniqueName($category->type, $category->parent_id, $category->branch_id, $changed['name'], $category->id);
            }
            // Finding 1: a parent's (de)activation lists the subs whose effective status changes with it.
            $affected = isset($changed['is_active']) && $category->parent_id === null
                ? Category::where('parent_id', $category->id)->where('is_active', true)->orderBy('id')->pluck('id')->all()
                : null;
            $old = $category->only(array_keys($changed));
            // Review N3: lock the accounts the sync will write, in id order (as postings do), before changing them.
            $ids = $category->parent_id === null ? Category::where('parent_id', $category->id)->pluck('id')->push($category->id) : [$category->id];
            Account::withoutGlobalScopes()->whereIn('category_id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
            $category->update($changed);
            AuditLog::record('category.updated', $category, $old,
                $changed + ($affected !== null ? ['affected_sub_ids' => $affected] : []));
        }, LedgerService::ATTEMPTS);

        return $this->presentOne($category->refresh(), $request);
    }

    /**
     * R9 (AG-071): a branch-only sub becomes global. The code is kept, the other branches get a zero-balance account
     * (effective status), nothing is posted, and the origin account, its lines and every category_id stay unchanged.
     */
    public function promote(Request $request, Category $category)
    {
        $result = DB::transaction(function () use ($category) {
            $this->chart->lockChart();
            $category->refresh();
            abort_if($category->branch_id === null || $category->parent_id === null, 422, self::PROMOTE_NOT_BRANCH);
            abort_unless($category->is_active, 422, self::PROMOTE_INACTIVE);
            abort_unless($category->isEffectivelyActive(), 422, self::PROMOTE_PARENT_INACTIVE);
            // R3 against global siblings and every other branch's branch-only siblings, inactive ones included.
            $this->assertUniqueName($category->type, $category->parent_id, null, $category->name, $category->id);

            $origin = (int) $category->branch_id;
            $before = Account::withoutGlobalScopes()->where('category_id', $category->id)->pluck('id')->all();
            $category->branch_id = null;
            $category->save();
            $this->chart->createCategoryAccounts($category);
            $created = Account::withoutGlobalScopes()->where('category_id', $category->id)->whereNotIn('id', $before)
                ->orderBy('id')->pluck('id')->all();
            // Finding 1: written with the ORIGIN branch so its admins see why the sub is now managed centrally.
            AuditLog::record('category.promoted', $category, ['branch_id' => $origin], [
                'old_branch_id' => $origin, 'new_branch_id' => null, 'ledger_code' => $category->ledger_code,
                'name' => $category->name, 'created_account_ids' => $created,
            ], $origin);

            return $category;
        }, LedgerService::ATTEMPTS);

        return $this->presentOne($result->refresh(), $request);
    }

    // ------------------------------------------------------------------ rules

    /** R1: top-level rows and global subs are Super Admin only (403); another branch's sub is not revealed (404). */
    private function authorizeChange(Request $request, Category $category): void
    {
        if ($request->user()->is_super_admin) {
            return;
        }
        abort_if($category->branch_id === null, 403, self::GLOBAL_ONLY);
        abort_unless((int) $category->branch_id === app(BranchContext::class)->branchId, 404);
    }

    /**
     * R3: unique name within the parent, trimmed and case-insensitive, inactive rows included. $branchId null = a
     * global node: a global sub also clashes with every branch's branch-only siblings (named in the message).
     * Callers hold the chart lock (finding 6).
     */
    private function assertUniqueName(string $type, ?int $parentId, ?int $branchId, string $name, ?int $exceptId = null): void
    {
        $same = fn () => Category::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId));

        if ($parentId === null) {
            abort_if($same()->whereNull('parent_id')->where('type', $type)->exists(), 422, self::DUPLICATE);

            return;
        }
        $siblings = fn () => $same()->where('parent_id', $parentId);
        abort_if($siblings()->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))->exists(),
            422, self::DUPLICATE);
        if ($branchId === null) {
            $codes = Branch::whereIn('id', $siblings()->whereNotNull('branch_id')->select('branch_id'))->orderBy('code')->pluck('code');
            abort_if($codes->isNotEmpty(), 422, 'A sub-category with this name already exists in '.$codes->implode(', ').'. Rename it first.');
        }
    }

    // ------------------------------------------------------------------ S1 presentation

    private function manageTree(Request $request): array
    {
        $ctx = app(BranchContext::class);
        $branchId = $ctx->branchId;
        $type = in_array($request->query('type'), ['income', 'expense'], true) ? $request->query('type') : null;

        $parents = Category::whereNull('parent_id')->when($type, fn ($q) => $q->where('type', $type))->orderBy('name')->get();
        // R12: in branch mode only global subs and this branch's own; in All mode every branch's.
        $subs = Category::whereIn('parent_id', $parents->pluck('id'))
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->orderBy('name')->get()->groupBy('parent_id');
        $all = $parents->concat($subs->flatten());
        $stats = $this->stats($all->pluck('id')->all(), $branchId);
        $codes = Branch::pluck('code', 'id');

        $data = $parents->map(function (Category $p) use ($subs, $stats, $codes, $request) {
            $children = ($subs[$p->id] ?? collect())->map(fn (Category $c) => $this->present($c, $stats, $codes, $request, $p))->values();
            $node = $this->present($p, $stats, $codes, $request);
            // A parent's usage and balance include its subs' (S1 "Used in").
            $node['usage_count'] += $children->sum('usage_count');
            $node['year_balance'] = Money::fromCents(Money::toCents($node['year_balance']) + $children->sum(fn ($c) => Money::toCents($c['year_balance'])));
            $node['first_entry_date'] = $children->pluck('first_entry_date')->push($node['first_entry_date'])->filter()->min();
            $node['children'] = $children->all();

            return $node;
        })->values()->all();

        return ['data' => $data, 'meta' => $this->meta($branchId)];
    }

    private function presentOne(Category $c, Request $request): array
    {
        $branchId = app(BranchContext::class)->branchId;
        $stats = $this->stats([$c->id], $branchId);

        return $this->present($c, $stats, Branch::pluck('code', 'id'), $request, $c->parent);
    }

    private function present(Category $c, array $stats, Collection $codes, Request $request, ?Category $parent = null): array
    {
        $super = (bool) $request->user()->is_super_admin;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'type' => $c->type,
            'parent_id' => $c->parent_id,
            'branch_id' => $c->branch_id,
            'branch' => $c->branch_id ? ['id' => (int) $c->branch_id, 'code' => $codes[$c->branch_id] ?? null] : null,
            'ledger_code' => $c->ledger_code,
            'is_active' => $c->is_active,
            // R6: hidden from entry forms when the node or its parent is inactive.
            'effective_active' => $c->is_active && ($parent === null || $parent->is_active),
            'usage_count' => $stats['usage'][$c->id] ?? 0,
            'year_balance' => Money::fromCents($stats['balance'][$c->id] ?? 0),
            // QA Q3: the rename warning names locked months / a closed year only when the node has entries in them.
            'first_entry_date' => $stats['first'][$c->id] ?? null,
            'can_edit' => $super || ($c->branch_id !== null && (int) $c->branch_id === app(BranchContext::class)->branchId),
            // QA Q1: only an effectively active branch-only sub can be made global (R9).
            'can_promote' => $super && $c->branch_id !== null && $c->parent_id !== null && $c->is_active && $parent?->is_active === true,
        ];
    }

    /**
     * Usage count (Q7): income and expense entries of any status whose current category is the node (reversal rows are
     * another type). Year balance (review finding 9): the node's ledger balance in the current fiscal year, on its
     * natural side. Both in the active branch (branch mode) or all branches (All mode), R12.
     *
     * @return array{usage: array<int,int>, balance: array<int,int>, first: array<int,string>}
     */
    private function stats(array $ids, ?int $branchId): array
    {
        $rows = DB::table('transactions')->whereIn('type', [Transaction::INCOME, Transaction::EXPENSE])->whereIn('category_id', $ids)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('category_id')->selectRaw('category_id, COUNT(*) as n, MIN(txn_date) as first')->get();
        $usage = $rows->mapWithKeys(fn ($r) => [(int) $r->category_id => (int) $r->n])->all();
        $first = $rows->mapWithKeys(fn ($r) => [(int) $r->category_id => substr((string) $r->first, 0, 10)])->all();

        $fy = app(Settings::class)->currentFiscalYear();
        $dr = Money::sqlCents('le.debit');
        $cr = Money::sqlCents('le.credit');
        $balance = DB::table('ledger_entries as le')->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('a.type', 'category')->whereIn('a.category_id', $ids)
            ->when($branchId, fn ($q) => $q->where('le.branch_id', $branchId))
            ->whereDate('le.entry_date', '>=', $fy->start())->whereDate('le.entry_date', '<=', $fy->end())
            ->groupBy('a.category_id', 'a.class')
            ->selectRaw("a.category_id, a.class, SUM($dr - $cr) as cents")->get()
            ->mapWithKeys(fn ($r) => [(int) $r->category_id => ($r->class === 'income' ? -1 : 1) * (int) $r->cents])->all();

        return ['usage' => $usage, 'balance' => $balance, 'first' => $first];
    }

    /** Context for the S1 dialogs: the fiscal year of year_balance and the rename warning (review findings 7, 9). */
    private function meta(?int $branchId): array
    {
        $fy = app(Settings::class)->currentFiscalYear();
        $through = PeriodLock::closedThrough();
        $locked = $branchId
            ? PeriodLock::throughFor($through, $branchId)
            : (string) collect($through['branches'])->push((string) $through['global'])->max();
        $closed = YearEndClose::active()->orderByDesc('fiscal_year')->first();

        return [
            'fiscal_year' => ['label' => $fy->label(), 'from' => $fy->start(), 'to' => $fy->end()],
            'locked_through' => $locked ? ['period' => $locked, 'label' => PeriodLock::label($locked),
                'end' => CarbonImmutable::parse($locked.'-01')->endOfMonth()->format('Y-m-d')] : null,
            'closed_year' => $closed ? ['label' => $closed->fiscal()->label(), 'end' => $closed->fiscal()->end()] : null,
        ];
    }
}
