<?php

namespace App\Services;

use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Chart of accounts per branch (docs/specs/double-entry.md R1). Codes are 5 digits; the first digit is the class:
 *  11001-11999 money accounts (per branch, by creation), 15 + branch id (3 digits) inter-branch, 31000 Owner's capital
 *  (MAIN only), 4PPSS income and 5PPSS expense categories (same code in every branch). 32000 is reserved (AG-092).
 *
 * Accounts are never deleted and codes never reused. This class creates and renames chart accounts; it never posts
 * and never writes current_balance (that is LedgerService only).
 */
class ChartOfAccounts
{
    public const OWNER_CAPITAL_CODE = '31000';

    public const RESERVED_RETAINED_EARNINGS = '32000';

    public const MAX_BRANCH_ID = 999;

    public const NAME_HEAD_OFFICE = 'Head office current account';

    public const NAME_OWNER_CAPITAL = "Owner's capital";

    public const BRANCH_LIMIT_MESSAGE = 'No more branches can be created (inter-branch account codes are limited to branch id 999).';

    /** Accounts created since the last takeCreated() (for one summary audit row per operation). */
    private array $created = [];

    // ------------------------------------------------------------------ codes

    /**
     * Review R3: codes are max + 1, so two requests at once could pick the same code (duplicate key, HTTP 500). Each
     * assignment first locks a row FOR UPDATE inside the caller's DB transaction: the account's branch for money codes,
     * the Main branch (one chart-wide lock) for category codes and system accounts. The second request waits and then
     * reads the first one's code. Callers run in a DB transaction (controllers, migration, LedgerService).
     */
    private function lockRow(?int $branchId = null): void
    {
        $q = DB::table('branches')->lockForUpdate();
        $branchId === null ? $q->where('is_main', true)->value('id') : $q->where('id', $branchId)->value('id');
    }

    public function nextMoneyCode(int $branchId): string
    {
        $this->lockRow($branchId);
        $max = DB::table('accounts')->where('branch_id', $branchId)->whereBetween('code', ['11001', '11999'])->max('code');
        $next = $max ? (int) $max + 1 : 11001;
        if ($next > 11999) {
            throw new LedgerException('No more cash, bank or wallet account codes are available in this branch. Ask the accountant.');
        }

        return (string) $next;
    }

    public static function interbranchCode(int $branchId): string
    {
        return '15'.str_pad((string) $branchId, 3, '0', STR_PAD_LEFT);
    }

    /** 4PP00 / 5PP00 for a parent, 4PPSS / 5PPSS for a sub-category, in creation order (R1). */
    public function nextCategoryCode(Category $c): string
    {
        $this->lockRow();
        $digit = $c->type === 'income' ? '4' : '5';
        if (! $c->parent_id) {
            $max = Category::whereNull('parent_id')->where('type', $c->type)->whereNotNull('ledger_code')->max('ledger_code');
            $pp = $max ? (int) substr($max, 1, 2) + 1 : 1;
            if ($pp > 99) {
                throw new LedgerException('No more category codes are available under '.($c->type === 'income' ? 'Income' : 'Expenses').'. Ask the accountant.');
            }

            return $digit.sprintf('%02d', $pp).'00';
        }
        $parent = Category::findOrFail($c->parent_id);
        if ($parent->ledger_code === null) {
            $parent->ledger_code = $this->nextCategoryCode($parent);
            $parent->saveQuietly();
        }
        $max = Category::where('parent_id', $parent->id)->whereNotNull('ledger_code')->max('ledger_code');
        $ss = $max ? (int) substr($max, 3, 2) + 1 : 1;
        if ($ss > 99) {
            throw new LedgerException("No more category codes are available under {$parent->name}. Ask the accountant.");
        }

        return substr($parent->ledger_code, 0, 3).sprintf('%02d', $ss);
    }

    public static function categoryAccountName(Category $c): string
    {
        $parent = $c->parent_id ? Category::find($c->parent_id) : null;

        return $parent ? "{$parent->name} > {$c->name}" : $c->name;
    }

    // ------------------------------------------------------------------ creation

    /** Branch creation (R1): the inter-branch pair with MAIN (or Owner's capital for MAIN) and the global category accounts. */
    public function setupBranch(Branch $branch): void
    {
        if ($branch->id > self::MAX_BRANCH_ID) {
            throw new LedgerException(self::BRANCH_LIMIT_MESSAGE);
        }
        $this->takeCreated();
        if ($branch->is_main) {
            $this->ownerCapital();
        } elseif ($main = Branch::where('is_main', true)->first()) {
            $this->headOffice($branch, $main);
            $this->branchCurrent($branch, $main);
        }
        Category::whereNull('branch_id')->orderBy('id')->each(fn (Category $c) => $this->categoryAccount($c, $branch->id));
        $this->recordChartCreated('branch', $branch->id);
    }

    /** Category creation (R1): its account in every branch where it can be used. */
    public function createCategoryAccounts(Category $c): void
    {
        $this->takeCreated();
        foreach ($this->categoryBranchIds($c) as $branchId) {
            $this->categoryAccount($c, $branchId);
        }
        $this->recordChartCreated('category', $c->branch_id);
    }

    /** A global category: every branch (active and inactive). A branch-only sub-category: its own branch. */
    public function categoryBranchIds(Category $c): array
    {
        return $c->branch_id ? [(int) $c->branch_id] : Branch::orderBy('id')->pluck('id')->all();
    }

    /** Rename / (de)activate (R1): the accounts follow the category; a renamed parent renames its subs' accounts too. */
    public function syncCategoryAccounts(Category $c): void
    {
        $ids = [$c->id];
        if ($c->wasChanged('name') && ! $c->parent_id) {
            $ids = array_merge($ids, Category::where('parent_id', $c->id)->pluck('id')->all());
        }
        foreach (Category::whereIn('id', $ids)->get() as $cat) {
            $name = self::categoryAccountName($cat);
            $status = $cat->is_active ? 'active' : 'inactive';
            foreach (Account::withoutGlobalScopes()->where('category_id', $cat->id)->orderBy('id')->get() as $acc) {
                $old = $acc->only(['name', 'status']);
                $acc->fill(['name' => $name, 'status' => $acc->status === 'closed' ? 'closed' : $status]);
                if ($acc->isDirty()) {
                    $acc->save();
                    AuditLog::record('account.updated', $acc, $old, $acc->only(['name', 'status']) + ['system' => true, 'self' => false]);
                }
            }
        }
    }

    public function ownerCapital(): Account
    {
        $main = Branch::main();

        return $this->ensure(['branch_id' => $main->id, 'code' => self::OWNER_CAPITAL_CODE],
            ['name' => self::NAME_OWNER_CAPITAL, 'type' => 'owner_capital', 'class' => 'equity']);
    }

    /** "Head office current account" in a non-main branch. */
    public function headOffice(Branch $branch, ?Branch $main = null): Account
    {
        $main ??= Branch::main();
        if ($branch->id === $main->id) {
            throw new LedgerException('The Main branch has no head office current account.');
        }

        return $this->ensure(['branch_id' => $branch->id, 'counterparty_branch_id' => $main->id],
            ['code' => self::interbranchCode($main->id), 'name' => self::NAME_HEAD_OFFICE, 'type' => 'head_office', 'class' => 'asset']);
    }

    /** "Branch current account – <code>" in MAIN, for a non-main branch. */
    public function branchCurrent(Branch $branch, ?Branch $main = null): Account
    {
        $main ??= Branch::main();
        if ($branch->id === $main->id) {
            throw new LedgerException('The Main branch has no branch current account for itself.');
        }

        return $this->ensure(['branch_id' => $main->id, 'counterparty_branch_id' => $branch->id],
            ['code' => self::interbranchCode($branch->id), 'name' => "Branch current account – {$branch->code}", 'type' => 'branch_current', 'class' => 'asset']);
    }

    public function categoryAccount(Category $c, int $branchId): Account
    {
        if ($c->ledger_code === null) {
            $c->ledger_code = $this->nextCategoryCode($c);
            $c->saveQuietly();
        }

        return $this->ensure(['branch_id' => $branchId, 'category_id' => $c->id], [
            'code' => $c->ledger_code, 'name' => self::categoryAccountName($c), 'type' => 'category',
            'class' => $c->type, 'status' => $c->is_active ? 'active' : 'inactive',
        ]);
    }

    /**
     * Resolve a posting-rule account reference (see PostingRules) to its account, creating a missing system or category
     * account (safety net, R1). Not for money accounts: those are picked by the user.
     */
    public function resolve(array $ref): Account
    {
        $this->takeCreated();
        $acc = match ($ref[0]) {
            PostingRules::MONEY => Account::withoutGlobalScopes()->findOrFail($ref[1]),
            PostingRules::CATEGORY => $this->categoryAccount(Category::findOrFail($ref[1]), $ref[2]),
            PostingRules::HEAD_OFFICE => $this->headOffice(Branch::findOrFail($ref[1])),
            PostingRules::BRANCH_CURRENT => $this->branchCurrent(Branch::findOrFail($ref[1])),
            PostingRules::OWNER_CAPITAL => $this->ownerCapital(),
        };
        foreach ($this->takeCreated() as $new) {
            AuditLog::record('account.created', $new, null, $new->only(['code', 'name', 'type', 'class', 'category_id', 'counterparty_branch_id']) + ['system' => true]);
        }

        return $acc;
    }

    /** @return Account[] accounts created since the last call */
    public function takeCreated(): array
    {
        $out = $this->created;
        $this->created = [];

        return $out;
    }

    /** One `ledger.chart_created` row for the accounts just created: {source, by_branch:{code:{accounts:n}}, account_ids}. */
    public function recordChartCreated(string $source, ?int $branchId, array $extra = []): ?AuditLog
    {
        $new = $this->takeCreated();
        if (! $new && ! $extra) {
            return null;
        }

        return AuditLog::record('ledger.chart_created', null, null, ['source' => $source, 'by_branch' => self::countByBranch($new),
            'account_ids' => array_map(fn ($a) => $a->id, $new)] + $extra, $branchId);
    }

    /** @param  Account[]  $accounts */
    public static function countByBranch(array $accounts): array
    {
        $codes = Branch::pluck('code', 'id');
        $out = [];
        foreach ($accounts as $a) {
            $code = $codes[$a->branch_id] ?? (string) $a->branch_id;
            $out[$code]['accounts'] = ($out[$code]['accounts'] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    private function ensure(array $keys, array $attrs): Account
    {
        $acc = Account::withoutGlobalScopes()->where($keys)->first();
        if ($acc) {
            return $acc;
        }
        // Missing: take the chart lock and look again, so two requests never both create it (review R3).
        $this->lockRow();
        $acc = Account::withoutGlobalScopes()->where($keys)->lockForUpdate()->first();
        if ($acc) {
            return $acc;
        }
        $acc = Account::withoutGlobalScopes()->create($keys + $attrs);
        $this->created[] = $acc;

        return $acc;
    }
}
