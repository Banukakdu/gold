<?php

namespace App\Services;

use App\Exceptions\DuplicateTemplateUseException;
use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\RecurringTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * AG-066 recurring expense templates (docs/specs/recurring-expenses.md). A template is a saved pre-fill of the Record
 * expense form and posts nothing (R1): no method here writes `transactions`, `ledger_entries` or a balance. An expense
 * from a template is an ordinary POST /expenses through LedgerService::incomeOrExpense, which calls lockForUse(),
 * assertNotDuplicate() (R6) and useAudit() (R11) inside its own transaction, before the AG-064 decision.
 *
 * Maintenance (store, update, archive, restore) needs a single active branch (R2) and writes one `expense_template.*`
 * audit row on the template's branch (R11). The branch row is locked first, so two name checks (R3) never race.
 */
class ExpenseTemplates
{
    public const NOT_FOUND = 'Template not found in this branch.';

    public const ARCHIVED = 'This template is archived.';

    public const ARCHIVED_EDIT = 'This template is archived. Restore it first.';

    public const ALREADY_ARCHIVED = 'This template is already archived.';

    public const NOT_ARCHIVED = 'This template is not archived.';

    public const CHOOSE_BRANCH = 'Choose a branch first.';

    public const ACCOUNT = 'Choose an active cash, bank or wallet account of this branch.';

    public const CATEGORY = 'Choose an active expense sub-category of this branch.';

    public const AMOUNT = 'Enter an amount greater than 0 with up to 2 decimals (at most 9,999,999,999,999.99), or leave it empty.';

    public const NAME_LENGTH = 'The name must be 3 to 100 characters.';

    public const PAYMENT_METHODS = ['cash', 'bank_transfer', 'cheque', 'card', 'online'];

    /** Review MUST 2 (a): a same-name template archived this many days ago or less is named to the approver. */
    public const SAME_NAME_DAYS = 90;

    public const STATUS_LABELS = ['posted' => 'Posted', 'pending' => 'Pending approval'];

    /** The audit rows written when a template is used (R11): posted at once, or held for approval (AG-064). */
    public const USE_EVENTS = ['transaction.expense', 'expense.approval_requested'];

    // ------------------------------------------------------------------ maintenance (S1)

    /** R2: maintenance needs a single active branch; a Super Admin in All mode only reads. */
    public function activeBranchId(): int
    {
        $ctx = app(BranchContext::class);
        abort_unless($ctx->isScoped(), 422, self::CHOOSE_BRANCH);

        return (int) $ctx->branchId;
    }

    public function store(array $input, User $user): RecurringTemplate
    {
        $branchId = $this->activeBranchId();

        return DB::transaction(function () use ($input, $user, $branchId) {
            $this->lockBranch($branchId);
            $data = $this->validated($input, $branchId);
            $this->assertUniqueName($branchId, $data['name']);
            $tpl = RecurringTemplate::create([
                'branch_id' => $branchId, 'type' => RecurringTemplate::EXPENSE, 'version' => 1, 'created_by' => $user->id,
            ] + $this->columns($data));
            AuditLog::record('expense_template.created', $tpl, null, $this->auditValues($tpl) + ['version' => 1]);

            return $tpl;
        }, LedgerService::ATTEMPTS);
    }

    /** PUT: all fields, R3 / R4 again; a change bumps the version (R11, review MUST 2); no change writes nothing. */
    public function update(int $id, array $input, User $user): RecurringTemplate
    {
        $branchId = $this->activeBranchId();

        return DB::transaction(function () use ($id, $input, $user, $branchId) {
            $this->lockBranch($branchId);
            $tpl = $this->locked($id);
            abort_if($tpl->isArchived(), 422, self::ARCHIVED_EDIT);
            $data = $this->validated($input, $branchId);
            $this->assertUniqueName($branchId, $data['name'], $tpl->id);

            $before = $this->auditValues($tpl);
            $tpl->fill($this->columns($data));
            $after = $this->auditValues($tpl);
            $changed = array_keys(array_filter($after, fn ($v, $k) => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
            if (! $changed) {
                return $tpl->refresh();
            }
            $old = array_intersect_key($before, array_flip($changed)) + ['version' => $tpl->version];
            $tpl->version = $tpl->version + 1;
            $tpl->updated_by = $user->id;
            $tpl->save();
            AuditLog::record('expense_template.updated', $tpl, $old, array_intersect_key($after, array_flip($changed)) + ['version' => $tpl->version]);

            return $tpl;
        }, LedgerService::ATTEMPTS);
    }

    public function archive(int $id, array $input, User $user): RecurringTemplate
    {
        $this->activeBranchId();
        $reason = validator($input, ['reason' => 'nullable|string|max:500'])->validate()['reason'] ?? null;
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        return DB::transaction(function () use ($id, $reason, $user) {
            $tpl = $this->locked($id);
            abort_if($tpl->isArchived(), 422, self::ALREADY_ARCHIVED);
            $tpl->forceFill(['archived_at' => now(), 'archived_by' => $user->id, 'archive_reason' => $reason])->save();
            AuditLog::record('expense_template.archived', $tpl, ['archived_at' => null],
                ['archived_at' => $tpl->archived_at->toIso8601String(), 'reason' => $reason, 'name' => $tpl->name]);

            return $tpl;
        }, LedgerService::ATTEMPTS);
    }

    /** R3 / R4 / R10: the name must be free among active templates and the account and category still valid. */
    public function restore(int $id, User $user): RecurringTemplate
    {
        $branchId = $this->activeBranchId();

        return DB::transaction(function () use ($id, $branchId) {
            $this->lockBranch($branchId);
            $tpl = $this->locked($id);
            abort_unless($tpl->isArchived(), 422, self::NOT_ARCHIVED);
            abort_if($this->nameTaken($branchId, $tpl->name, $tpl->id), 422, "An active template is already named {$tpl->name}.");
            $this->assertAccountAndCategory($branchId, (int) $tpl->account_id, (int) $tpl->category_id);
            $old = ['archived_at' => $tpl->archived_at->toIso8601String(), 'archived_by' => $tpl->archived_by, 'reason' => $tpl->archive_reason];
            $tpl->forceFill(['archived_at' => null, 'archived_by' => null, 'archive_reason' => null])->save();
            AuditLog::record('expense_template.restored', $tpl, $old, ['archived_at' => null, 'name' => $tpl->name]);

            return $tpl;
        }, LedgerService::ATTEMPTS);
    }

    /** The template of the active branch, locked (BranchScoped: another branch's template is a 404, R2). */
    private function locked(int $id): RecurringTemplate
    {
        $tpl = RecurringTemplate::whereKey($id)->lockForUpdate()->first();
        abort_unless($tpl && $tpl->type === RecurringTemplate::EXPENSE, 404);

        return $tpl;
    }

    private function lockBranch(int $branchId): void
    {
        Branch::whereKey($branchId)->lockForUpdate()->value('id');
    }

    /** Field validation (section 4 dialog, section 5 API), then R3 (name form) and R4 (account and category). */
    private function validated(array $input, int $branchId): array
    {
        $data = validator($input, [
            'name' => 'required|string|max:255',
            'account_id' => 'required|integer',
            'category_id' => 'required|integer',
            'amount' => 'nullable',
            'payee_payer' => 'nullable|string|max:255',
            'payment_method' => ['nullable', Rule::in(self::PAYMENT_METHODS)],
            'description' => 'nullable|string|max:2000',
            'frequency' => ['required', Rule::in(RecurringTemplate::FREQUENCIES)],
        ])->validate();

        // R3: trimmed, inner spaces collapsed, 3-100 characters.
        $data['name'] = trim((string) preg_replace('/\s+/u', ' ', $data['name']));
        $length = mb_strlen($data['name']);
        if ($length < 3 || $length > 100) {
            throw ValidationException::withMessages(['name' => self::NAME_LENGTH]);
        }
        $data['amount_cents'] = $this->amountCents($data['amount'] ?? null);
        foreach (['payee_payer', 'description'] as $k) {
            $v = isset($data[$k]) ? trim((string) $data[$k]) : null;
            $data[$k] = $v === '' ? null : $v;
        }
        $acc = $this->assertAccountAndCategory($branchId, (int) $data['account_id'], (int) $data['category_id']);
        // SHOULD 7 / QA D1: "empty" means the account's default method, stored as such, so the form's pre-fill and the
        // R11 comparison at use agree.
        $data['payment_method'] = ($data['payment_method'] ?? null) ?: RecurringTemplate::defaultPaymentMethod($acc->type);

        return $data;
    }

    /** R12: a decimal string (or JSON number) with up to 2 decimals and > 0, as integer cents; empty = null. No floats. */
    private function amountCents(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = is_string($v) || is_int($v) || is_float($v) ? trim((string) $v) : '';
        if (! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $s) || Money::toCents($s) <= 0) {
            throw ValidationException::withMessages(['amount' => self::AMOUNT]);
        }

        return Money::toCents($s);
    }

    /** R4: an active money account of the branch, and an effectively active expense sub-category, global or of the branch. */
    private function assertAccountAndCategory(int $branchId, int $accountId, int $categoryId): Account
    {
        $acc = Account::withoutGlobalScopes()->whereKey($accountId)->where('branch_id', $branchId)->first();
        $errors = [];
        if (! $acc || ! $acc->isMoney() || $acc->status !== 'active') {
            $errors['account_id'] = self::ACCOUNT;
        }
        $cat = Category::find($categoryId);
        if (! $cat || $cat->type !== RecurringTemplate::EXPENSE || $cat->parent_id === null || ! $cat->isEffectivelyActive()
            || ($cat->branch_id !== null && (int) $cat->branch_id !== $branchId)) {
            $errors['category_id'] = self::CATEGORY;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $acc;
    }

    private function assertUniqueName(int $branchId, string $name, ?int $exceptId = null): void
    {
        if ($this->nameTaken($branchId, $name, $exceptId)) {
            throw ValidationException::withMessages(['name' => "A template named {$name} already exists in this branch."]);
        }
    }

    /** R3: LOWER(name) among the branch's non-archived templates (portable between SQLite and MySQL). */
    private function nameTaken(int $branchId, string $name, ?int $exceptId = null): bool
    {
        return RecurringTemplate::withoutGlobalScopes()->where('branch_id', $branchId)->whereNull('archived_at')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))->exists();
    }

    private function columns(array $data): array
    {
        return [
            'name' => $data['name'], 'account_id' => (int) $data['account_id'], 'category_id' => (int) $data['category_id'],
            'amount_cents' => $data['amount_cents'], 'payee_payer' => $data['payee_payer'], 'payment_method' => $data['payment_method'],
            'description' => $data['description'], 'frequency' => $data['frequency'],
        ];
    }

    /** R11: the template's fields in API terms (amount as a decimal string). */
    private function auditValues(RecurringTemplate $t): array
    {
        return [
            'name' => $t->name, 'account_id' => (int) $t->account_id, 'category_id' => (int) $t->category_id,
            'amount' => $t->amount_cents === null ? null : Money::fromCents((int) $t->amount_cents),
            'payee_payer' => $t->payee_payer, 'payment_method' => $t->payment_method, 'description' => $t->description,
            'frequency' => $t->frequency,
        ];
    }

    // ------------------------------------------------------------------ use (LedgerService::incomeOrExpense)

    /**
     * R2 / R6 / R10: the template of the active branch (scope on, so the message never confirms that it exists elsewhere),
     * not archived, locked FOR UPDATE so two uses run one after the other, with its linked expenses (review finding 1).
     *
     * The second use must see the first. On MySQL (REPEATABLE READ) a plain read answers from the snapshot taken at the
     * transaction's first plain read, so:
     *  - incomeOrExpense opens its own transaction and calls this as its FIRST statement. The snapshot is then taken
     *    after this lock is granted, and every use and every template change (both hold this lock until they commit)
     *    committed before it is visible to the plain reads that follow (uses, audit rows, template history).
     *  - Inside an outer transaction (a caller's, or a test's) an earlier plain read may already have fixed the
     *    snapshot, so the uses are read with a shared lock instead, which InnoDB answers from the latest committed
     *    data (as the AG-064 R2b same-day rule does).
     * The shared lock is not used in the outermost case on purpose: its next-key locks on the (expense_template_id,
     * txn_date) index make parallel first uses of two different templates deadlock (seen on MySQL 8.0 with eight
     * parallel uses), while the lock order alone already gives the current data there.
     */
    public function lockForUse(int $id): RecurringTemplate
    {
        $tpl = RecurringTemplate::whereKey($id)->lockForUpdate()->first();
        if (! $tpl || $tpl->type !== RecurringTemplate::EXPENSE) {
            throw new LedgerException(self::NOT_FOUND);
        }
        if ($tpl->isArchived()) {
            throw new LedgerException(self::ARCHIVED);
        }
        $tpl->setRelation('uses', Transaction::where('expense_template_id', $tpl->id)->where('type', Transaction::EXPENSE)
            ->when(DB::transactionLevel() > 1, fn ($q) => $q->sharedLock())->orderBy('id')
            ->get(['id', 'ref_no', 'status', 'approval_status', 'txn_date', 'requested_date', 'requested_on', 'expense_template_id']));

        return $tpl;
    }

    /** R2: the template must be of the money account's branch (both are of the active branch in practice). */
    public function assertSameBranch(RecurringTemplate $tpl, Account $acc): void
    {
        if ((int) $tpl->branch_id !== (int) $acc->branch_id) {
            throw new LedgerException(self::NOT_FOUND);
        }
    }

    /**
     * R6 / R9: does this linked expense count for its period? Posted, or pending and not yet past its AG-064 R8 expiry
     * (requested_on + 30 days): a request the daily expiry job has not closed yet is already dead (review finding 4).
     */
    public static function counts(Transaction $t): bool
    {
        if ($t->status === 'posted') {
            return true;
        }

        return $t->status === 'pending' && ($t->requested_on === null || $t->requested_on->format('Y-m-d') > self::expiryCutoff());
    }

    /** The same rule as a query (S1 "This period", "Last used", S2 "changed since its last use"). */
    private static function whereCounts(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => $w->where('status', 'posted')
            ->orWhere(fn (Builder $p) => $p->where('status', 'pending')
                ->where(fn (Builder $r) => $r->whereNull('requested_on')->orWhereDate('requested_on', '>', self::expiryCutoff()))));
    }

    /** AG-064 R8: a request pending since this date or earlier has expired (expire() uses the same cutoff). */
    private static function expiryCutoff(): string
    {
        return CarbonImmutable::parse(BusinessDate::today())->subDays(ExpenseApprovals::EXPIRY_DAYS)->toDateString();
    }

    /**
     * R6: the template's counted uses (from lockForUse) whose bill date is in the period of $date (review MUST 1).
     * Without $confirm, any of them is a 409; with it, they are returned for the audit row.
     *
     * @return array<int, array{id: int, ref_no: ?string, date: string, status: string, status_label: string}>
     */
    public function assertNotDuplicate(RecurringTemplate $tpl, string $date, bool $confirm): array
    {
        $period = self::period($tpl->frequency, $date);
        if ($period === null) {
            return [];
        }
        $dupes = $tpl->uses->filter(fn (Transaction $t) => self::counts($t) && self::billDate($t) >= $period['from'] && self::billDate($t) <= $period['to'])
            ->map(fn (Transaction $t) => self::txnRow($t))->values()->all();
        if ($dupes && ! $confirm) {
            $list = implode(', ', array_map(fn ($d) => "{$d['ref_no']} ({$d['status_label']}, {$d['date']})", $dupes));
            throw new DuplicateTemplateUseException("This template was already used for {$period['label']}: {$list}. Save anyway only if this is a separate bill.",
                array_map(fn ($d) => array_diff_key($d, ['status_label' => 1]), $dupes));
        }

        return $dupes;
    }

    /**
     * R11 / R7a: the keys added to the use's audit row (`transaction.expense` or `expense.approval_requested`). Called
     * before the expense row is written, so no earlier use is ever this one.
     *
     *  - template_changes / template_values / template_labels: what the requester changed against the template as it is now.
     *  - Review MUST 2 (sign-off finding 2): the template is compared with the version recorded by its latest use that an
     *    approver approved (template_reviewed_*), not with its latest use, which may have posted below the limit with
     *    nobody looking. Without such a use the approver is told so (template_first_review), with when and by whom the
     *    template was created and last changed, and a same-name template archived in the last 90 days is named
     *    (archive and re-create gives a new template with no history).
     */
    public function useAudit(RecurringTemplate $tpl, array $data, array $dupes): array
    {
        $now = $this->compared($this->auditValues($tpl));
        $changes = [];
        $values = [];
        foreach (RecurringTemplate::COMPARED as $field) {
            if ($field === 'amount') {
                if ($tpl->amount_cents !== null && Money::toCents((string) $data['amount']) !== (int) $tpl->amount_cents) {
                    $changes[] = 'amount';
                    $values['amount'] = $now['amount'];
                }

                continue;
            }
            $ids = in_array($field, ['account_id', 'category_id'], true);
            if (self::norm($data[$field] ?? null, $ids) !== self::norm($now[$field], $ids)) {
                $changes[] = $field;
                $values[$field] = $now[$field];
            }
        }
        $out = [
            'template_id' => $tpl->id, 'template_name' => $tpl->name, 'template_version' => (int) $tpl->version,
            'template_changes' => $changes, 'template_values' => $values,
        ] + (($labels = $this->labels($values)) ? ['template_labels' => $labels] : []);

        $out += $this->reviewFacts($tpl, $now);
        if ($dupes) {
            $out += ['duplicate_confirmed' => true, 'duplicate_of' => array_column($dupes, 'ref_no'),
                'duplicate_period' => self::period($tpl->frequency, (string) $data['txn_date'])['label'],
                'duplicate_entries' => array_map(fn ($d) => array_diff_key($d, ['status_label' => 1]), $dupes)];
        }

        return $out;
    }

    /** Review MUST 2: the template against its last approved use, or the "first reviewed use" facts. */
    private function reviewFacts(RecurringTemplate $tpl, array $now): array
    {
        $version = (int) $tpl->version;
        $out = [];
        $reviewed = $tpl->uses->filter(fn (Transaction $t) => $t->approval_status === ExpenseApprovals::APPROVED)->sortByDesc('id')->first();
        $reviewedVersion = $reviewed ? (self::useAudits([$reviewed->id])[$reviewed->id]['template_version'] ?? null) : null;
        if ($reviewedVersion !== null) {
            $reviewedVersion = (int) $reviewedVersion;
            $out += ['template_first_review' => false, 'template_reviewed_version' => $reviewedVersion,
                'template_reviewed_ref' => $reviewed->ref_no, 'template_changed_since_review' => $version > $reviewedVersion];
            if ($version > $reviewedVersion) {
                $then = $this->compared($this->valuesAt($tpl, $reviewedVersion));
                $fields = array_values(array_filter(RecurringTemplate::COMPARED, fn ($f) => self::norm($then[$f] ?? null, false) !== self::norm($now[$f], false)));
                $thenValues = array_intersect_key($then, array_flip($fields));
                $out += ['template_review_changes' => $fields, 'template_review_values' => (object) $thenValues]
                    + (($labels = $this->labels($thenValues)) ? ['template_review_labels' => $labels] : []);
            }
        } else {
            $out += ['template_first_review' => true, 'template_changed_since_review' => false,
                'template_unreviewed_uses' => $tpl->uses->filter(fn (Transaction $t) => self::counts($t))->count()];
        }
        $out += ['template_created_on' => $tpl->created_at?->copy()->setTimezone(BusinessDate::timezone())->format('Y-m-d'),
            'template_created_by' => $tpl->creator?->name];
        if ($version > 1 && ($last = $this->lastChanges([$tpl->id])[$tpl->id] ?? null)) {
            $out += ['template_changed_on' => $last['on'], 'template_changed_by' => $last['by']['name'] ?? null];
        }
        if ($archived = $this->sameNameArchived($tpl)) {
            $out['template_same_name_archived'] = $archived;
        }

        return $out;
    }

    /**
     * The template's fields as they were at $version, rebuilt from its `expense_template.created` row and the
     * `expense_template.updated` rows up to that version (R11 keeps the changed fields and the version of each change).
     */
    private function valuesAt(RecurringTemplate $tpl, int $version): array
    {
        $state = [];
        $rows = AuditLog::whereIn('event', ['expense_template.created', 'expense_template.updated'])->where('auditable_type', RecurringTemplate::class)
            ->where('auditable_id', $tpl->id)->orderBy('id')->get(['event', 'new_values']);
        foreach ($rows as $r) {
            $new = (array) $r->new_values;
            if ((int) ($new['version'] ?? 1) > $version) {
                break;
            }
            $state = array_merge($state, $new);
        }

        return $state;
    }

    /** The compared fields of auditValues(), an empty payment method read as the account's default (QA D1). */
    private function compared(array $values): array
    {
        $out = array_intersect_key($values, array_flip(RecurringTemplate::COMPARED));
        if (($out['payment_method'] ?? null) === null && isset($out['account_id'])) {
            $type = Account::withoutGlobalScopes()->whereKey((int) $out['account_id'])->value('type');
            $out['payment_method'] = RecurringTemplate::defaultPaymentMethod($type);
        }

        return $out;
    }

    /** Names for the id fields among $values, so the approver and S3 can show an account or a category (R7a). */
    private function labels(array $values): array
    {
        $labels = [];
        if (array_key_exists('account_id', $values)) {
            $labels['account_id'] = $values['account_id'] === null ? null
                : Account::withoutGlobalScopes()->whereKey((int) $values['account_id'])->value('name');
        }
        if (array_key_exists('category_id', $values)) {
            $labels['category_id'] = $values['category_id'] === null ? null
                : ExpenseApprovals::categoryPath(Category::find((int) $values['category_id']));
        }

        return $labels;
    }

    /** Review MUST 2 (a): a template of the same name in this branch archived in the last 90 days, the latest one. */
    private function sameNameArchived(RecurringTemplate $tpl): ?array
    {
        $other = RecurringTemplate::withoutGlobalScopes()->with('archiver:id,name')->where('branch_id', $tpl->branch_id)->whereKeyNot($tpl->id)
            ->whereNotNull('archived_at')->where('archived_at', '>=', now()->subDays(self::SAME_NAME_DAYS))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($tpl->name)])->orderByDesc('archived_at')->orderByDesc('id')->first();

        return $other ? ['id' => $other->id, 'name' => $other->name,
            'archived_on' => $other->archived_at->copy()->setTimezone(BusinessDate::timezone())->format('Y-m-d'),
            'archived_by' => $other->archiver?->name, 'reason' => $other->archive_reason] : null;
    }

    private static function norm(mixed $v, bool $id): int|string|null
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }

        return $id || is_int($v) ? (int) $v : trim((string) $v);
    }

    /**
     * Q11 / review MUST 1: the calendar month, quarter or year that contains $date; null for "No fixed period".
     *
     * @return array{from: string, to: string, label: string}|null
     */
    public static function period(string $frequency, string $date): ?array
    {
        $d = CarbonImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));

        return match ($frequency) {
            'monthly' => ['from' => $d->startOfMonth()->toDateString(), 'to' => $d->endOfMonth()->toDateString(), 'label' => $d->format('F Y')],
            'quarterly' => (function () use ($d) {
                $q = intdiv($d->month - 1, 3);
                $from = $d->setDate($d->year, $q * 3 + 1, 1);

                return ['from' => $from->toDateString(), 'to' => $from->addMonths(3)->subDay()->toDateString(), 'label' => 'Q'.($q + 1).' '.$d->year];
            })(),
            'yearly' => ['from' => $d->year.'-01-01', 'to' => $d->year.'-12-31', 'label' => (string) $d->year],
            default => null,
        };
    }

    /**
     * Counted linked expenses of $templateIds whose bill date (requested_date when set, else txn_date) is in
     * [$from, $to], with whereDate bounds (portable). Branch scope on: a template's expenses are in its branch. Display
     * only (S1); a use reads its own through lockForUse().
     *
     * @return Collection<int, Transaction>
     */
    private function linked(array $templateIds, string $from, string $to): Collection
    {
        return self::whereCounts(Transaction::whereIn('expense_template_id', $templateIds ?: [0])->where('type', Transaction::EXPENSE))
            ->where(fn (Builder $w) => $w
                ->where(fn (Builder $x) => $x->whereNotNull('requested_date')->whereDate('requested_date', '>=', $from)->whereDate('requested_date', '<=', $to))
                ->orWhere(fn (Builder $x) => $x->whereNull('requested_date')->whereDate('txn_date', '>=', $from)->whereDate('txn_date', '<=', $to)))
            ->orderBy('id')->get(['id', 'ref_no', 'status', 'txn_date', 'requested_date', 'expense_template_id']);
    }

    /** @return array{id: int, ref_no: ?string, date: string, status: string, status_label: string} */
    private static function txnRow(Transaction $t): array
    {
        return ['id' => $t->id, 'ref_no' => $t->ref_no, 'date' => self::billDate($t), 'status' => $t->status,
            'status_label' => self::STATUS_LABELS[$t->status] ?? $t->status];
    }

    private static function billDate(Transaction $t): string
    {
        return ($t->requested_date ?? $t->txn_date)->format('Y-m-d');
    }

    /**
     * S2 (display only): the template_version recorded by the latest counted linked expense of each template.
     *
     * @return array<int, int> by template id
     */
    private function previousVersions(array $templateIds): array
    {
        $latest = self::whereCounts(Transaction::whereIn('expense_template_id', $templateIds ?: [0])->where('type', Transaction::EXPENSE))
            ->groupBy('expense_template_id')
            ->selectRaw('expense_template_id, MAX(id) as last_id')->toBase()->get()
            ->mapWithKeys(fn ($r) => [(int) $r->last_id => (int) $r->expense_template_id]);
        if ($latest->isEmpty()) {
            return [];
        }
        $out = [];
        foreach (self::useAudits($latest->keys()->all()) as $txnId => $audit) {
            if (isset($audit['template_version'])) {
                $out[$latest[$txnId]] = (int) $audit['template_version'];
            }
        }

        return $out;
    }

    /**
     * The latest field change of each template (an `expense_template.updated` row): its Colombo date and its author.
     *
     * @return array<int, array{on: string, by: ?array{id: int, name: string}}>
     */
    private function lastChanges(array $templateIds): array
    {
        return AuditLog::with('user:id,name')->where('event', 'expense_template.updated')->where('auditable_type', RecurringTemplate::class)
            ->whereIn('auditable_id', $templateIds ?: [0])->orderBy('id')->get(['id', 'auditable_id', 'user_id', 'created_at'])
            ->keyBy('auditable_id')
            ->map(fn (AuditLog $a) => ['on' => $a->created_at->copy()->setTimezone(BusinessDate::timezone())->format('Y-m-d'),
                'by' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null])->all();
    }

    // ------------------------------------------------------------------ read (S1, S2, S3, S5)

    /**
     * Section 5 rows, by name A-Z, with R9 "This period" / "Last used" and the S2 "changed since its last use" facts,
     * in a few grouped queries (none per row).
     *
     * @param  EloquentCollection<int, RecurringTemplate>  $templates
     */
    public function rows(EloquentCollection $templates, User $user): array
    {
        if ($templates->isEmpty()) {
            return [];
        }
        $templates->load(['branch:id,code', 'account:id,name,status,branch_id,type', 'category:id,name,parent_id,is_active,branch_id',
            'category.parent:id,name,is_active', 'creator:id,name,status', 'archiver:id,name']);
        $ids = $templates->pluck('id')->all();
        $today = BusinessDate::today();
        $year = substr($today, 0, 4);
        // Every "this period" contains today and lies inside today's calendar year.
        $linked = $this->linked($ids, "{$year}-01-01", "{$year}-12-31")->groupBy('expense_template_id');
        $last = self::whereCounts(Transaction::whereIn('expense_template_id', $ids)->where('type', Transaction::EXPENSE))
            ->groupBy('expense_template_id')->selectRaw('expense_template_id, MAX(COALESCE(requested_date, txn_date)) as last_used')
            ->toBase()->get()->mapWithKeys(fn ($r) => [(int) $r->expense_template_id => substr((string) $r->last_used, 0, 10)]);
        $versions = $this->previousVersions($ids);
        $changes = $this->lastChanges(array_values(array_filter($ids, fn ($id) => $templates->firstWhere('id', $id)->version > 1)));
        $ctx = app(BranchContext::class);
        $canUse = $user->canDo('expense.create');
        $canEdit = $ctx->isScoped() && $user->canDo('expense.edit');

        return $templates->sortBy(fn (RecurringTemplate $t) => [mb_strtolower($t->name), $t->id])->values()
            ->map(function (RecurringTemplate $t) use ($linked, $last, $versions, $changes, $today, $canUse, $canEdit) {
                $period = self::period($t->frequency, $today);
                $thisPeriod = $period === null ? null : ['label' => $period['label'], 'from' => $period['from'], 'to' => $period['to'],
                    'transactions' => ($linked[$t->id] ?? collect())->filter(fn (Transaction $x) => self::billDate($x) >= $period['from'] && self::billDate($x) <= $period['to'])
                        ->map(fn (Transaction $x) => self::txnRow($x))->values()->all()];
                $c = $t->category;

                return [
                    'id' => $t->id,
                    'branch' => ['id' => $t->branch_id, 'code' => $t->branch?->code],
                    'name' => $t->name,
                    'version' => (int) $t->version,
                    'account' => ['id' => (int) $t->account_id, 'name' => $t->account?->name, 'status' => $t->account?->status, 'type' => $t->account?->type],
                    'category' => ['id' => (int) $t->category_id, 'parent_id' => $c?->parent_id, 'path' => ExpenseApprovals::categoryPath($c),
                        'active' => $c !== null && $c->is_active && ($c->parent_id === null || (bool) $c->parent?->is_active)],
                    'amount' => $t->amount_cents === null ? null : Money::fromCents((int) $t->amount_cents),
                    'payee_payer' => $t->payee_payer,
                    'payment_method' => $t->payment_method,
                    'description' => $t->description,
                    'frequency' => $t->frequency,
                    'archived_at' => $t->archived_at?->copy()->setTimezone(BusinessDate::timezone())->toIso8601String(),
                    'archived_by' => $t->archiver ? ['id' => $t->archiver->id, 'name' => $t->archiver->name] : null,
                    'archive_reason' => $t->archive_reason,
                    'created_by' => $t->creator ? ['id' => $t->creator->id, 'name' => $t->creator->name, 'inactive' => $t->creator->status !== 'active'] : null,
                    'this_period' => $thisPeriod,
                    'last_used_on' => $last[$t->id] ?? null,
                    // S2 (review MUST 2): changed since the version its previous linked expense recorded.
                    'changed_since_last_use' => isset($versions[$t->id]) && (int) $t->version > $versions[$t->id],
                    'last_changed' => $changes[$t->id] ?? null,
                    'can_use' => $canUse && ! $t->isArchived(),
                    'can_edit' => $canEdit,
                ];
            })->all();
    }

    public function row(RecurringTemplate $t, User $user): array
    {
        return $this->rows(new EloquentCollection([$t]), $user)[0];
    }

    /**
     * R7a / S3: what the use's audit row recorded (template name, changes with the template's values, the comparison
     * with the last approved use or the first-review facts, a same-name template archived lately, confirmed duplicate).
     * Empty keys when the row has no template.
     */
    public static function facts(?array $audit): array
    {
        $a = $audit ?? [];
        $has = isset($a['template_id']);

        return [
            'template' => $has ? ['id' => (int) $a['template_id'], 'name' => $a['template_name'] ?? null, 'version' => $a['template_version'] ?? null] : null,
            'template_changes' => $has ? array_values((array) ($a['template_changes'] ?? [])) : [],
            'template_values' => $has ? (object) ($a['template_values'] ?? []) : (object) [],
            'template_labels' => (object) ($a['template_labels'] ?? []),
            'template_first_review' => (bool) ($a['template_first_review'] ?? false),
            'template_unreviewed_uses' => (int) ($a['template_unreviewed_uses'] ?? 0),
            'template_reviewed_version' => $a['template_reviewed_version'] ?? null,
            'template_reviewed_ref' => $a['template_reviewed_ref'] ?? null,
            'template_changed_since_review' => (bool) ($a['template_changed_since_review'] ?? false),
            'template_review_changes' => array_values((array) ($a['template_review_changes'] ?? [])),
            'template_review_values' => (object) ($a['template_review_values'] ?? []),
            'template_review_labels' => (object) ($a['template_review_labels'] ?? []),
            'template_created_on' => $a['template_created_on'] ?? null,
            'template_created_by' => $a['template_created_by'] ?? null,
            'template_changed_on' => $a['template_changed_on'] ?? null,
            'template_changed_by' => $a['template_changed_by'] ?? null,
            'template_same_name_archived' => $a['template_same_name_archived'] ?? null,
            'duplicate_confirmed' => (bool) ($a['duplicate_confirmed'] ?? false),
            'duplicate_of' => array_values((array) ($a['duplicate_of'] ?? [])),
            'duplicate_period' => $a['duplicate_period'] ?? null,
            'duplicate_entries' => array_values((array) ($a['duplicate_entries'] ?? [])),
        ];
    }

    /** The use's audit values of each transaction (the first row that carries template_id). @return array<int, array> */
    public static function useAudits(array $transactionIds): array
    {
        $out = [];
        $rows = AuditLog::whereIn('event', self::USE_EVENTS)->where('auditable_type', Transaction::class)
            ->whereIn('auditable_id', $transactionIds ?: [0])->orderBy('id')->get(['auditable_id', 'new_values']);
        foreach ($rows as $r) {
            $id = (int) $r->auditable_id;
            if (! isset($out[$id]) && isset($r->new_values['template_id'])) {
                $out[$id] = (array) $r->new_values;
            }
        }

        return $out;
    }
}
