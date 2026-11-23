<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankReconciliationMark;
use App\Models\BankStatement;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\ReportParams;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * AG-033 bank reconciliation (docs/specs/bank-reconciliation.md). It posts nothing: it never writes ledger_entries,
 * accounts.current_balance or transactions. The reconciled state lives in bank_reconciliation_marks (ticks with a
 * cleared date) and bank_statements (completed reconciliations). Every amount is integer cents (App\Support\Money).
 *
 * LedgerEntry has no branch scope, so every query here filters the account id of the bound (branch-scoped) account
 * (R9); the report resolves its branch set first (reports R1) and filters accounts.branch_id itself.
 *
 * Definitions (R2, R3), for account A and date D:
 *  - reconciled(A, D)       = SUM(debit - credit) of lines with an active mark cleared on or before D;
 *  - book(A, D)             = SUM(debit - credit) of lines dated on or before D (a void nets to zero on its date);
 *  - uncleared receipts/payments = debits / credits of lines dated on or before D, transaction not void, with no
 *                             active mark cleared on or before D;
 *  - cleared booked later   = SUM(debit - credit) of lines dated after D with an active mark cleared on or before D;
 *  - expected bank balance  = book - uncleared receipts + uncleared payments + cleared booked later = reconciled(A, D).
 */
class BankReconciliation
{
    public const NOT_BANK = 'Only bank accounts can be reconciled.';

    public const BALANCE_PATTERN = '/^-?\d{1,13}(\.\d{1,2})?$/';

    public const BALANCE_MESSAGE = 'Enter the statement balance (for example 125000.00 or -2500.00).';

    public const LOCKED_UNDO = 'The statement date is in a locked period. Only a Super Admin can undo it.';

    public const MAX_ITEMS = 500;

    /** Review finding 9: at most this many lines on the S1 screen, and uncleared rows on the S3 screen (exports have their own caps). */
    public const SCREEN_LINES = 2000;

    public const SCREEN_ROWS = 2000;

    /** S3 basis line. */
    public const BASIS = 'Bank lines dated up to the As at date that have no cleared date on or before it; voided entries excluded. '
        .'Entries the bank cleared before they were booked are shown as a separate reconciling item.';

    /** Review finding 8: an uncleared payment older than this (days) is a stale cheque. */
    public const STALE_DAYS = 180;

    public const STALE_TAG = 'Stale cheque (over 6 months)';

    public function __construct(private YearEnd $yearEnd) {}

    /** "2026-10-31" -> "31 Oct 2026" (messages, banners). */
    public static function label(string $date): string
    {
        return CarbonImmutable::parse($date)->format('j M Y');
    }

    public static function fail(string $message, int $status = 422): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }

    public function assertBank(Account $account): void
    {
        if ($account->type !== 'bank') {
            self::fail(self::NOT_BANK);
        }
    }

    public function lastCompleted(int $accountId, ?string $through = null): ?BankStatement
    {
        return BankStatement::withoutGlobalScopes()->where('account_id', $accountId)->completed()
            ->when($through, fn ($q, $d) => $q->whereDate('statement_date', '<=', $d))
            ->orderByDesc('statement_date')->orderByDesc('id')->first();
    }

    // ------------------------------------------------------------------ figures (R2, R3), cents

    private function dr(): string
    {
        return Money::sqlCents('le.debit');
    }

    private function cr(): string
    {
        return Money::sqlCents('le.credit');
    }

    /** Active marks of the account joined to their line (`m`, `le`). */
    private function activeMarks(int $accountId): Builder
    {
        return DB::table('bank_reconciliation_marks as m')->join('ledger_entries as le', 'le.id', '=', 'm.ledger_entry_id')
            ->where('m.account_id', $accountId)->where('le.account_id', $accountId)->whereNull('m.unmarked_at');
    }

    /** R2 reconciled(A, D). */
    public function reconciledCents(int $accountId, string $date): int
    {
        return (int) $this->activeMarks($accountId)->whereDate('m.cleared_date', '<=', $date)
            ->selectRaw("COALESCE(SUM({$this->dr()} - {$this->cr()}), 0) as c")->value('c');
    }

    /** Ledger balance at D (every line dated on or before D; a void and its contra net to zero on the same date). */
    public function bookCents(int $accountId, string $date): int
    {
        return (int) DB::table('ledger_entries as le')->where('le.account_id', $accountId)->whereDate('le.entry_date', '<=', $date)
            ->selectRaw("COALESCE(SUM({$this->dr()} - {$this->cr()}), 0) as c")->value('c');
    }

    /** R3: lines of A dated on or before D, transaction not void, with no active mark cleared on or before D. */
    private function unclearedQuery(int $accountId, string $date): Builder
    {
        return DB::table('ledger_entries as le')->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->where('le.account_id', $accountId)->whereDate('le.entry_date', '<=', $date)->where('t.status', '!=', 'void')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bank_reconciliation_marks as um')->whereColumn('um.ledger_entry_id', 'le.id')
                ->whereNull('um.unmarked_at')->whereDate('um.cleared_date', '<=', $date));
    }

    /** R3 (review finding 1): lines of A dated after D with an active mark cleared on or before D. */
    private function clearedLaterQuery(int $accountId, string $date): Builder
    {
        return $this->activeMarks($accountId)->whereDate('le.entry_date', '>', $date)->whereDate('m.cleared_date', '<=', $date);
    }

    /**
     * R3 at D: book, uncleared receipts, uncleared payments, cleared booked later, expected bank balance, and
     * reconciled(A, D) from its own query (the identity expected = reconciled is checked by V10 and the tests).
     *
     * @return array{book: int, uncleared_receipts: int, uncleared_payments: int, cleared_booked_later: int, expected: int, reconciled: int}
     */
    public function figures(int $accountId, string $date): array
    {
        $u = $this->unclearedQuery($accountId, $date)
            ->selectRaw("COALESCE(SUM({$this->dr()}), 0) as dr, COALESCE(SUM({$this->cr()}), 0) as cr")->first();
        $later = (int) $this->clearedLaterQuery($accountId, $date)->selectRaw("COALESCE(SUM({$this->dr()} - {$this->cr()}), 0) as c")->value('c');
        $book = $this->bookCents($accountId, $date);
        $f = ['book' => $book, 'uncleared_receipts' => (int) $u->dr, 'uncleared_payments' => (int) $u->cr, 'cleared_booked_later' => $later];
        $f['expected'] = $book - $f['uncleared_receipts'] + $f['uncleared_payments'] + $later;
        $f['reconciled'] = $this->reconciledCents($accountId, $date);

        return $f;
    }

    /** R8: R3 recomputed at S differs from the figures stored at completion. */
    public function changed(BankStatement $s, ?array $now = null): bool
    {
        $now ??= $this->figures((int) $s->account_id, $s->statement_date->format('Y-m-d'));

        return $now['book'] !== $s->book_balance_cents || $now['uncleared_receipts'] !== $s->uncleared_receipts_cents
            || $now['uncleared_payments'] !== $s->uncleared_payments_cents || $now['cleared_booked_later'] !== $s->cleared_booked_later_cents;
    }

    /**
     * R8 late entries of a statement: lines dated on or before S written after its completion (new postings and void
     * contras). "After" is by line id against the account's highest line id stored at completion (review finding 7:
     * no one-second timestamp boundary; Complete holds the account lock, so no line of the account is in flight).
     */
    private function lateQuery(BankStatement $s): Builder
    {
        return DB::table('ledger_entries as le')->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->where('le.account_id', $s->account_id)->whereDate('le.entry_date', '<=', $s->statement_date->format('Y-m-d'))
            ->where('le.id', '>', (int) $s->max_entry_id);
    }

    /** The S1 banner count (review finding 6): late entries that are listed and can be ticked, so voided ones are left out. */
    public function lateCount(BankStatement $s): int
    {
        return (int) $this->lateQuery($s)->whereIn('t.status', ['posted', 'reversed'])->distinct()->count('le.transaction_id');
    }

    /**
     * Statements (ids) with at least one late line, in one query. A completed statement's figures at S can only change
     * through a late line (every mark cleared on or before S is frozen, a marked line cannot be voided and lines are
     * immutable), so `changed` is computed only for these (review finding 9).
     *
     * @param  Collection<int, BankStatement>  $statements
     * @return array<int, true>
     */
    private function withLateLines(Collection $statements): array
    {
        if ($statements->isEmpty()) {
            return [];
        }

        return DB::table('bank_statements as s')->whereIn('s.id', $statements->pluck('id'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ledger_entries as le')->whereColumn('le.account_id', 's.account_id')
                ->whereRaw('DATE(le.entry_date) <= DATE(s.statement_date)')->whereColumn('le.id', '>', 's.max_entry_id'))
            ->pluck('s.id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** R8 for a completed statement; an undone statement is superseded and never flagged. */
    private function changedFast(BankStatement $s, array $late): bool
    {
        return $s->status === BankStatement::COMPLETED && isset($late[$s->id]) && $this->changed($s);
    }

    /** The late entries of a statement for the reprint: ref, date, amount, recorded by/at, or voided by/at. */
    public function lateEntries(BankStatement $s): array
    {
        $rows = $this->lateQuery($s)->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->orderBy('le.entry_date')->orderBy('le.id')
            ->get(['le.id', 'le.transaction_id', 'le.entry_date', 'le.contra_of_id', 'le.created_at', 't.ref_no', 't.created_at as t_created_at', 'u.name as created_by_name',
                DB::raw("{$this->dr()} as dr"), DB::raw("{$this->cr()} as cr")]);
        $voids = AuditLog::with('user:id,name')->where('event', 'transaction.voided')->where('auditable_type', Transaction::class)
            ->whereIn('auditable_id', $rows->whereNotNull('contra_of_id')->pluck('transaction_id')->unique())->get()->keyBy('auditable_id');

        return $rows->map(function ($r) use ($voids) {
            $void = $r->contra_of_id !== null;

            return [
                'entry_id' => (int) $r->id,
                'transaction_id' => (int) $r->transaction_id,
                'ref_no' => $r->ref_no,
                'date' => substr((string) $r->entry_date, 0, 10),
                'amount' => Money::fromCents((int) $r->dr - (int) $r->cr),
                'kind' => $void ? 'voided' : 'recorded',
                'by' => $void ? ($voids[$r->transaction_id]->user->name ?? null) : $r->created_by_name,
                'at' => CarbonImmutable::parse($void ? $r->created_at : $r->t_created_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    // ------------------------------------------------------------------ S1 screen (GET)

    public function screen(Account $account, ?string $statementDate, bool $showLater, bool $showReconciled, User $user): array
    {
        $this->assertBank($account);
        $account->loadMissing('branch:id,code,name');
        $last = $this->lastCompleted($account->id);
        [$lines, $limited] = $this->lines($account, $statementDate, $showLater, $showReconciled ? $last : null, $user);
        $statements = BankStatement::withoutGlobalScopes()->where('account_id', $account->id)
            ->orderByDesc('statement_date')->orderByDesc('id')->get();
        $reconciledAt = $statementDate ? $this->reconciledCents($account->id, $statementDate) : null;
        // Review finding 10: saved ticks cleared in (last S, S] on lines not listed (dated after S while "Show later
        // entries" is off, or past the line cap), so the tiles add up: last + receipts - payments + this = reconciled.
        $notListed = null;
        if ($statementDate !== null) {
            $lastDate = $last?->statement_date->format('Y-m-d');
            $listed = collect($lines)->filter(fn ($l) => ! $l['frozen'] && $l['mark'] && $l['mark']['cleared_date'] <= $statementDate
                && ($lastDate === null || $l['mark']['cleared_date'] > $lastDate))
                ->sum(fn ($l) => Money::toCents($l['in']) - Money::toCents($l['out']));
            $notListed = $reconciledAt - ($last ? $this->reconciledCents($account->id, $lastDate) : 0) - $listed;
        }

        return [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'status' => $account->status,
                'branch' => ['id' => $account->branch->id, 'code' => $account->branch->code, 'name' => $account->branch->name]],
            'last_statement' => $last ? ['id' => $last->id, 'statement_date' => $last->statement_date->format('Y-m-d'),
                'statement_balance' => Money::fromCents($last->statement_balance_cents)] : null,
            'book_balance_at' => $statementDate ? Money::fromCents($this->bookCents($account->id, $statementDate)) : null,
            'reconciled_at' => $reconciledAt === null ? null : Money::fromCents($reconciledAt),
            'cleared_not_listed' => $notListed === null ? null : Money::fromCents($notListed),
            'lines' => $lines,
            'lines_limited' => $limited,
            'late_entries_count' => $last ? $this->lateCount($last) : 0,
            'statements' => $this->presentAll($statements, $account, $user, $last),
        ];
    }

    /**
     * S1 table: lines of the account whose transaction is posted or reversed (a void and its contra are never listed),
     * not frozen in a completed statement, dated on or before S (all dates with show_later). $frozenOf: also list the
     * lines frozen in that (latest completed) statement, read-only (F3 tooltip).
     */
    /** @return array{0: array, 1: bool} the lines, and whether the line cap cut the list */
    private function lines(Account $account, ?string $statementDate, bool $showLater, ?BankStatement $frozenOf, User $user): array
    {
        $rows = DB::table('ledger_entries as le')->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->leftJoin('transactions as o', 'o.id', '=', 't.reversal_of_id')
            ->leftJoin('bank_reconciliation_marks as m', fn ($j) => $j->on('m.ledger_entry_id', '=', 'le.id')->whereNull('m.unmarked_at'))
            ->leftJoin('bank_statements as bs', 'bs.id', '=', 'm.statement_id')
            ->where('le.account_id', $account->id)
            ->whereIn('t.status', ['posted', 'reversed'])
            ->where(fn ($w) => $w->whereNull('bs.id')->orWhere('bs.status', '!=', BankStatement::COMPLETED)
                ->when($frozenOf, fn ($x, $s) => $x->orWhere('bs.id', $s->id)))
            ->when($statementDate && ! $showLater, fn ($q) => $q->whereDate('le.entry_date', '<=', $statementDate))
            ->orderBy('le.entry_date')->orderBy('le.id')->limit(self::SCREEN_LINES + 1)
            ->get(['le.id', 'le.transaction_id', 'le.entry_date', 't.ref_no', 't.type', 't.status', 't.payee_payer', 't.description', 't.reference',
                't.created_by', 'o.ref_no as reversal_of_ref', 'm.id as mark_id', 'm.cleared_date', 'm.marked_by', 'm.marked_at', 'm.statement_id',
                'bs.status as statement_status', 'bs.statement_date as frozen_date', DB::raw("{$this->dr()} as dr"), DB::raw("{$this->cr()} as cr")]);
        // Review finding 9: the oldest SCREEN_LINES lines (ticking them, or an earlier statement date, brings in the rest).
        $limited = $rows->count() > self::SCREEN_LINES;
        $rows = $rows->take(self::SCREEN_LINES);

        $people = $this->people($rows->pluck('marked_by')->filter()->unique()->all(), (int) $account->branch_id);

        $lines = $rows->map(function ($r) use ($people, $user) {
            $date = substr((string) $r->entry_date, 0, 10);
            $cleared = $r->mark_id ? substr((string) $r->cleared_date, 0, 10) : null;
            $frozen = $r->mark_id && $r->statement_status === BankStatement::COMPLETED;

            return [
                'entry_id' => (int) $r->id,
                'transaction_id' => (int) $r->transaction_id,
                'date' => $date,
                'ref_no' => $r->ref_no,
                'type' => $r->type,
                'status' => $r->status,
                'reversal_of_ref' => $r->reversal_of_ref,
                'description' => (string) ($r->payee_payer ?: $r->description),
                'reference' => $r->reference,
                'in' => Money::fromCents((int) $r->dr),
                'out' => Money::fromCents((int) $r->cr),
                'mark' => $r->mark_id ? [
                    'cleared_date' => $cleared,
                    'marked_by' => $people[(int) $r->marked_by] ?? null,
                    'marked_at' => CarbonImmutable::parse($r->marked_at)->toIso8601String(),
                ] : null,
                'frozen' => $frozen ? ['statement_id' => (int) $r->statement_id, 'statement_date' => substr((string) $r->frozen_date, 0, 10)] : null,
                'self' => (int) $r->created_by === (int) $user->id,
                // Review finding 9.
                'cleared_before_booked' => $cleared !== null && $cleared < $date,
            ];
        })->values()->all();

        return [$lines, $limited];
    }

    /**
     * Names with the controls R10 tag ("inactive" / "no longer in this branch"), keyed by user id.
     *
     * @return array<int, array{id: int, name: string, tag: ?string}>
     */
    public function people(array $ids, int $branchId): array
    {
        if (! $ids) {
            return [];
        }
        $members = DB::table('branch_user')->whereIn('user_id', $ids)->where('branch_id', $branchId)->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        return User::whereIn('id', $ids)->get(['id', 'name', 'status', 'is_super_admin'])->mapWithKeys(fn (User $u) => [$u->id => [
            'id' => $u->id,
            'name' => $u->name,
            'tag' => $u->status !== 'active' ? 'inactive' : (! $u->is_super_admin && ! in_array($u->id, $members, true) ? 'no longer in this branch' : null),
        ]])->all();
    }

    /** History rows (S1), latest first. */
    private function presentAll(Collection $statements, Account $account, User $user, ?BankStatement $last): array
    {
        $people = $this->people($statements->pluck('completed_by')->merge($statements->pluck('undone_by'))->filter()->unique()->all(), (int) $account->branch_id);
        $mayUndoLast = $last && $this->mayUndo($last, $user);
        $late = $this->withLateLines($statements->where('status', BankStatement::COMPLETED));

        return $statements->map(fn (BankStatement $s) => $this->present($s, $people, $last?->id === $s->id && $mayUndoLast, $late))->values()->all();
    }

    /** @param  array<int, true>|null  $late  statements with late lines (withLateLines); null: look up this one */
    public function present(BankStatement $s, ?array $people = null, bool $canUndo = false, ?array $late = null): array
    {
        $people ??= $this->people(array_filter([$s->completed_by, $s->undone_by]), (int) $s->branch_id);
        $late ??= $this->withLateLines(collect([$s]));

        return [
            'id' => $s->id,
            'account_id' => (int) $s->account_id,
            'statement_date' => $s->statement_date->format('Y-m-d'),
            'statement_balance' => Money::fromCents($s->statement_balance_cents),
            'reconciled' => Money::fromCents($s->reconciled_cents),
            'book_balance' => Money::fromCents($s->book_balance_cents),
            'uncleared_receipts' => Money::fromCents($s->uncleared_receipts_cents),
            'uncleared_payments' => Money::fromCents($s->uncleared_payments_cents),
            'cleared_booked_later' => Money::fromCents($s->cleared_booked_later_cents),
            'lines_count' => $s->lines_count,
            'self_count' => $s->self_count,
            'status' => $s->status,
            'completed_by' => $people[(int) $s->completed_by] ?? null,
            'completed_at' => $s->completed_at?->toIso8601String(),
            'undone_by' => $s->undone_by ? ($people[(int) $s->undone_by] ?? null) : null,
            'undone_at' => $s->undone_at?->toIso8601String(),
            'undo_reason' => $s->undo_reason,
            'changed_since_completion' => $this->changedFast($s, $late),
            'can_undo' => $canUndo,
        ];
    }

    /** R7: only the latest completed statement; dated in a locked month or a closed year: Super Admin only. */
    private function mayUndo(BankStatement $s, User $user): bool
    {
        return $user->is_super_admin || ! $this->periodClosed($s);
    }

    private function periodClosed(BankStatement $s, bool $share = false): bool
    {
        $date = $s->statement_date->format('Y-m-d');

        return PeriodLock::isLocked((int) $s->branch_id, $date, $share) || $this->yearEnd->closedYearOf($date, $share) !== null;
    }

    // ------------------------------------------------------------------ R4 marks (POST)

    /**
     * @param  array<int, array{entry_id: int, cleared_date: string}>  $mark
     * @param  int[]  $unmark
     * @return array{marked: int, unmarked: int}
     */
    public function saveMarks(Account $account, array $mark, array $unmark, User $user): array
    {
        $this->assertBank($account);
        if (array_intersect(array_map(fn ($m) => (int) $m['entry_id'], $mark), array_map('intval', $unmark))) {
            self::fail('A line cannot be ticked and unticked at once.');
        }

        return DB::transaction(function () use ($account, $mark, $unmark, $user) {
            // The account row is read only (current_balance is never written); the lock serialises ticks with a void (R5).
            $acc = Account::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);
            $last = $this->lastCompleted($acc->id);
            $lastDate = $last?->statement_date->format('Y-m-d');
            $ids = array_values(array_unique([...array_map(fn ($m) => (int) $m['entry_id'], $mark), ...array_map('intval', $unmark)]));
            $entries = LedgerEntry::with('transaction:id,ref_no,status,created_by')->whereIn('id', $ids)->get()->keyBy('id');
            $active = BankReconciliationMark::withoutGlobalScopes()->active()->with('statement')->whereIn('ledger_entry_id', $ids)
                ->lockForUpdate()->get()->keyBy('ledger_entry_id');
            $today = BusinessDate::today();
            $ref = fn (LedgerEntry $e) => $e->transaction->ref_no ?? "#{$e->transaction_id}";
            $frozenMessage = fn (LedgerEntry $e, BankReconciliationMark $m) => $ref($e).' is in the completed statement of '
                .self::label($m->statement->statement_date->format('Y-m-d')).'. Undo that statement first.';
            $isFrozen = fn (?BankReconciliationMark $m) => $m && $m->statement_id && $m->statement?->status === BankStatement::COMPLETED;
            $line = function (int $id) use ($entries, $acc): LedgerEntry {
                $e = $entries[$id] ?? null;
                if (! $e || (int) $e->account_id !== (int) $acc->id) {
                    self::fail("Line {$id} is not on this account.");
                }

                return $e;
            };

            $ops = [];
            foreach ($mark as $item) {
                $e = $line((int) $item['entry_id']);
                if ($e->transaction->status === 'void') {
                    self::fail('Voided entries cannot be reconciled ('.$ref($e).').');
                }
                if (! in_array($e->transaction->status, ['posted', 'reversed'], true)) {
                    self::fail("Line {$e->id} is not on this account.");
                }
                $date = (string) $item['cleared_date'];
                try {
                    BusinessDate::normalize($date);
                } catch (\InvalidArgumentException) {
                    self::fail('The cleared date must be in YYYY-MM-DD format.');
                }
                if ($date > $today) {
                    self::fail('The cleared date cannot be in the future.');
                }
                if ($lastDate !== null && $date <= $lastDate) {
                    self::fail('The cleared date must be after '.self::label($lastDate).', the last completed statement.');
                }
                $m = $active[$e->id] ?? null;
                if ($isFrozen($m)) {
                    self::fail($frozenMessage($e, $m)); // review finding 6: a frozen mark is never re-dated
                }
                if ($m && $m->cleared_date->format('Y-m-d') === $date) {
                    self::fail($ref($e).' is already reconciled.');
                }
                $ops[] = ['kind' => $m ? 'changed' : 'marked', 'entry' => $e, 'date' => $date, 'old' => $m];
            }
            foreach ($unmark as $id) {
                $e = $line((int) $id);
                $m = $active[$e->id] ?? null;
                if (! $m) {
                    self::fail($ref($e).' is not reconciled.');
                }
                if ($isFrozen($m)) {
                    self::fail($frozenMessage($e, $m));
                }
                $ops[] = ['kind' => 'unmarked', 'entry' => $e, 'old' => $m];
            }

            $now = now();
            $audit = ['account_id' => $acc->id, 'marked' => [], 'unmarked' => [], 'changed' => [], 'self_count' => 0];
            foreach ($ops as $op) {
                $e = $op['entry'];
                if ($op['old']) {
                    $op['old']->update(['unmarked_at' => $now, 'unmarked_by' => $user->id]);
                }
                if ($op['kind'] !== 'unmarked') {
                    try {
                        BankReconciliationMark::create(['branch_id' => $acc->branch_id, 'account_id' => $acc->id, 'ledger_entry_id' => $e->id,
                            'cleared_date' => $op['date'], 'marked_by' => $user->id, 'marked_at' => $now]);
                    } catch (QueryException $ex) {
                        if (! self::isActiveMarkViolation($ex)) {
                            throw $ex; // review finding 8: any other integrity error is a real fault
                        }
                        self::fail($ref($e).' is already reconciled.'); // unique active_key under a race (R12)
                    }
                }
                $audit['self_count'] += (int) $e->transaction->created_by === (int) $user->id ? 1 : 0;
                $audit[$op['kind']][] = match ($op['kind']) {
                    'marked' => ['entry_id' => $e->id, 'ref_no' => $ref($e), 'entry_date' => $e->entry_date->format('Y-m-d'),
                        'amount' => Money::fromCents(Money::toCents($e->debit) - Money::toCents($e->credit)), 'cleared_date' => $op['date']],
                    'changed' => ['entry_id' => $e->id, 'ref_no' => $ref($e), 'old_cleared_date' => $op['old']->cleared_date->format('Y-m-d'), 'cleared_date' => $op['date']],
                    'unmarked' => ['entry_id' => $e->id, 'ref_no' => $ref($e), 'cleared_date' => $op['old']->cleared_date->format('Y-m-d')],
                };
            }
            AuditLog::record('reconciliation.saved', $acc, null, $audit);

            return ['marked' => count($mark), 'unmarked' => count($unmark)];
        }, LedgerService::ATTEMPTS);
    }

    /** The one-active-mark-per-line unique index (MySQL active_key, SQLite partial index), as YearEnd::isActiveKeyViolation. */
    public static function isActiveMarkViolation(QueryException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $msg = $e->getMessage();

        return ($driverCode === 1062 && str_contains($msg, 'bank_reconciliation_marks_active_key_unique'))
            || ($driverCode === 19 && str_contains($msg, 'UNIQUE constraint failed: bank_reconciliation_marks.ledger_entry_id'));
    }

    // ------------------------------------------------------------------ R6 complete

    public function complete(Account $account, string $statementDate, string $balance, User $user): BankStatement
    {
        $this->assertBank($account);

        return DB::transaction(function () use ($account, $statementDate, $balance, $user) {
            $acc = Account::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);
            try {
                $s = BusinessDate::normalize($statementDate);
            } catch (\InvalidArgumentException) {
                self::fail('The statement date must be in YYYY-MM-DD format.');
            }
            if ($s > BusinessDate::today()) {
                self::fail('The statement date cannot be in the future.');
            }
            $last = $this->lastCompleted($acc->id);
            $lastDate = $last?->statement_date->format('Y-m-d');
            if ($lastDate !== null && $s <= $lastDate) {
                self::fail('The statement date must be after '.self::label($lastDate).'.');
            }
            if (! preg_match(self::BALANCE_PATTERN, $balance)) {
                self::fail(self::BALANCE_MESSAGE);
            }
            $balanceCents = Money::toCents($balance);
            $reconciled = $this->reconciledCents($acc->id, $s);
            $difference = $balanceCents - $reconciled;
            if ($difference !== 0) {
                self::fail('Difference is '.Money::format(Money::fromCents($difference)).'. The statement balance must equal the reconciled balance ('
                    .Money::format(Money::fromCents($reconciled)).').');
            }
            $f = $this->figures($acc->id, $s);
            // Review finding 7: the high-water mark for late entries (the account row lock keeps new lines out meanwhile).
            $maxEntryId = (int) DB::table('ledger_entries')->where('account_id', $acc->id)->max('id');

            // The marks this statement freezes: active, cleared in (last S, S].
            $marks = BankReconciliationMark::withoutGlobalScopes()->active()->where('account_id', $acc->id)
                ->whereDate('cleared_date', '<=', $s)->when($lastDate, fn ($q, $d) => $q->whereDate('cleared_date', '>', $d))
                ->lockForUpdate()->get(['id', 'ledger_entry_id']);
            $self = $marks->isEmpty() ? 0 : DB::table('ledger_entries as le')->join('transactions as t', 't.id', '=', 'le.transaction_id')
                ->whereIn('le.id', $marks->pluck('ledger_entry_id'))->where('t.created_by', $user->id)->count();

            $statement = BankStatement::create([
                'branch_id' => $acc->branch_id, 'account_id' => $acc->id, 'statement_date' => $s, 'statement_balance_cents' => $balanceCents,
                'reconciled_cents' => $reconciled, 'book_balance_cents' => $f['book'], 'uncleared_receipts_cents' => $f['uncleared_receipts'],
                'uncleared_payments_cents' => $f['uncleared_payments'], 'cleared_booked_later_cents' => $f['cleared_booked_later'],
                'self_count' => $self, 'lines_count' => $marks->count(), 'status' => BankStatement::COMPLETED,
                'completed_by' => $user->id, 'completed_at' => now(), 'max_entry_id' => $maxEntryId,
            ]);
            if ($marks->isNotEmpty()) {
                BankReconciliationMark::withoutGlobalScopes()->whereIn('id', $marks->pluck('id'))->update(['statement_id' => $statement->id, 'updated_at' => now()]);
            }
            AuditLog::record('reconciliation.completed', $acc, null, [
                'statement_id' => $statement->id,
                'statement_date' => $s,
                'statement_balance' => Money::fromCents($balanceCents),
                'reconciled' => Money::fromCents($reconciled),
                'book_balance' => Money::fromCents($f['book']),
                'uncleared_receipts' => Money::fromCents($f['uncleared_receipts']),
                'uncleared_payments' => Money::fromCents($f['uncleared_payments']),
                'cleared_booked_later' => Money::fromCents($f['cleared_booked_later']),
                'lines_count' => $marks->count(),
                'self_count' => $self,
            ]);

            return $statement;
        }, LedgerService::ATTEMPTS);
    }

    // ------------------------------------------------------------------ R7 undo

    public function undo(Account $account, int $statementId, string $reason, User $user): BankStatement
    {
        $this->assertBank($account);

        return DB::transaction(function () use ($account, $statementId, $reason, $user) {
            $acc = Account::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);
            $s = BankStatement::withoutGlobalScopes()->where('account_id', $acc->id)->lockForUpdate()->find($statementId);
            if (! $s) {
                self::fail('Statement not found.', 404);
            }
            if ($s->status === BankStatement::UNDONE) {
                self::fail('This statement is already undone.');
            }
            $latest = $this->lastCompleted($acc->id);
            if ($latest && $latest->id !== $s->id) {
                self::fail('Only the latest statement ('.self::label($latest->statement_date->format('Y-m-d')).') can be undone.');
            }
            if (! $user->is_super_admin && $this->periodClosed($s, share: true)) {
                self::fail(self::LOCKED_UNDO, 403);
            }
            $old = ['status' => BankStatement::COMPLETED, 'completed_by' => $s->completed_by, 'completed_at' => $s->completed_at->toIso8601String()];
            $s->update(['status' => BankStatement::UNDONE, 'undone_by' => $user->id, 'undone_at' => now(), 'undo_reason' => $reason]);
            AuditLog::record('reconciliation.undone', $acc, $old, ['status' => BankStatement::UNDONE, 'statement_id' => $s->id,
                'statement_date' => $s->statement_date->format('Y-m-d'), 'reason' => $reason]);

            return $s->fresh();
        }, LedgerService::ATTEMPTS);
    }

    // ------------------------------------------------------------------ R5 (called by LedgerService::void)

    /**
     * The first active mark on any of these lines (a locking read: run after the account rows are locked), with the
     * refusal text of R5, or null.
     */
    public static function voidRefusal(array $entryIds, string $ref): ?string
    {
        if (! $entryIds) {
            return null;
        }
        $m = self::installed(fn () => BankReconciliationMark::withoutGlobalScopes()->active()->whereIn('ledger_entry_id', $entryIds)
            ->orderBy('id')->lockForUpdate()->first(), null);
        if (! $m) {
            return null;
        }
        $name = Account::withoutGlobalScopes()->whereKey($m->account_id)->value('name');

        return "{$ref} is reconciled on {$name} (cleared {$m->cleared_date->format('Y-m-d')}). If the bank processed it, correct it with a reversal; "
            .'if the tick was a mistake, untick it first.';
    }

    // ------------------------------------------------------------------ S3 report

    /** Validate the report filters (reports R1 for the branches). `from` = `to` = As at. */
    public function reportParams(array $input): ReportParams
    {
        $d = validator($input, [
            'as_at' => 'nullable|date_format:Y-m-d',
            'branches' => 'nullable|array',
            'branches.*' => 'integer|distinct',
            'account_ids' => 'nullable|array',
            'account_ids.*' => 'integer|distinct',
            'older_than' => 'nullable|integer|min:0|max:3650',
            'statement_id' => 'nullable|integer',
        ])->validate();
        $statementId = isset($d['statement_id']) ? (int) $d['statement_id'] : null;
        $accountIds = array_map('intval', $d['account_ids'] ?? []);
        $requested = array_map('intval', $d['branches'] ?? []);
        if ($statementId !== null) {
            $s = BankStatement::withoutGlobalScopes()->find($statementId);
            $ctx = app(BranchContext::class);
            if (! $s || ($ctx->isScoped() && (int) $s->branch_id !== $ctx->branchId)) {
                self::fail('Statement not found.', 404);
            }
            $asAt = $s->statement_date->format('Y-m-d');
            $accountIds = [(int) $s->account_id];
            $requested = $ctx->isScoped() ? [] : [(int) $s->branch_id];
        } else {
            if (empty($d['as_at'])) {
                throw ValidationException::withMessages(['as_at' => 'The As at date is required.']);
            }
            $asAt = $d['as_at'];
            if ($asAt > BusinessDate::today()) {
                throw ValidationException::withMessages(['as_at' => 'The As at date cannot be in the future.']);
            }
        }
        [$branches, $all, $subset] = app(ReportService::class)->branchScope($requested);
        if ($accountIds) {
            $ok = DB::table('accounts')->where('type', 'bank')->whereIn('id', $accountIds)->whereIn('branch_id', $branches->pluck('id'))->count();
            if ($ok !== count($accountIds)) {
                throw ValidationException::withMessages(['account_ids' => 'Unknown account.']);
            }
        }

        // Review finding 2: a statement always lists every uncleared line (its rows add up to its totals), so Older than
        // is ignored with statement_id, and the output says so.
        $olderThan = isset($d['older_than']) ? (int) $d['older_than'] : null;

        return new ReportParams($asAt, $asAt, $branches, $all, $subset, [
            'as_at' => $asAt, 'account_ids' => $accountIds,
            'older_than' => $statementId === null ? $olderThan : null, 'statement_id' => $statementId,
            'older_than_ignored' => $statementId !== null && $olderThan !== null,
        ]);
    }

    /**
     * S3 data. $cap (the screen GET, review finding 9): at most this many uncleared rows in all; the rest are counted in
     * `rows_not_shown` (exports pass null and are bounded by the export caps instead).
     */
    public function report(ReportParams $p, ?int $cap = null): array
    {
        $asAt = $p->extra['as_at'];
        $olderThan = $p->extra['older_than'];
        $left = $cap;
        $settings = app(Settings::class);
        $accounts = Account::withoutGlobalScopes()->with('branch:id,code,name,is_main')->where('type', 'bank')
            ->whereIn('branch_id', $p->branchIds())
            ->when($p->extra['account_ids'], fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get()->sortBy(fn (Account $a) => [$a->branch->is_main ? 0 : 1, $a->branch->code, $a->code, $a->name])->values();
        $asAtDay = CarbonImmutable::parse($asAt);

        $out = [];
        foreach ($accounts as $a) {
            $f = $this->figures($a->id, $asAt);
            $last = $this->lastCompleted($a->id, $asAt);
            $rows = $this->unclearedQuery($a->id, $asAt)->leftJoin('transactions as o', 'o.id', '=', 't.reversal_of_id')
                ->orderBy('le.entry_date')->orderBy('le.id')
                ->get(['le.id', 'le.transaction_id', 'le.entry_date', 't.ref_no', 't.type', 't.payee_payer', 't.description', 't.reference', 'o.ref_no as reversal_of_ref',
                    DB::raw("{$this->dr()} as dr"), DB::raw("{$this->cr()} as cr")])
                ->map(function ($r) use ($asAtDay) {
                    $row = $this->row($r);
                    $row['age_days'] = (int) CarbonImmutable::parse($row['date'])->diffInDays($asAtDay);
                    // Review finding 8: an uncleared payment older than six months.
                    $row['stale'] = (int) $r->cr > 0 && $row['age_days'] > self::STALE_DAYS;

                    return $row;
                });
            $uncleared = $rows->count();
            $rows = $rows->filter(fn ($r) => $olderThan === null || $r['age_days'] >= $olderThan)->values();
            $filteredOut = $uncleared - $rows->count();
            $notShown = 0;
            if ($left !== null) {
                $notShown = max(0, $rows->count() - $left);
                $rows = $rows->take($left);
                $left -= $rows->count();
            }
            $rows = $rows->all();
            $later = $this->clearedLaterQuery($a->id, $asAt)->join('transactions as t', 't.id', '=', 'le.transaction_id')
                ->leftJoin('transactions as o', 'o.id', '=', 't.reversal_of_id')
                ->orderBy('le.entry_date')->orderBy('le.id')
                ->get(['le.id', 'le.transaction_id', 'le.entry_date', 't.ref_no', 't.type', 't.payee_payer', 't.description', 't.reference', 'o.ref_no as reversal_of_ref',
                    'm.cleared_date', DB::raw("{$this->dr()} as dr"), DB::raw("{$this->cr()} as cr")])
                ->map(fn ($r) => $this->row($r) + ['cleared_date' => substr((string) $r->cleared_date, 0, 10)])->values()->all();

            $out[] = [
                'account' => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'status' => $a->status,
                    'branch' => ['id' => $a->branch->id, 'code' => $a->branch->code, 'name' => $a->branch->name]],
                'last_statement' => $last ? ['id' => $last->id, 'statement_date' => $last->statement_date->format('Y-m-d'),
                    'statement_balance' => Money::fromCents($last->statement_balance_cents)] : null,
                'book_balance' => Money::fromCents($f['book']),
                'uncleared_receipts' => Money::fromCents($f['uncleared_receipts']),
                'uncleared_payments' => Money::fromCents($f['uncleared_payments']),
                'cleared_booked_later' => Money::fromCents($f['cleared_booked_later']),
                'cleared_booked_later_rows' => $later,
                'expected_bank_balance' => Money::fromCents($f['expected']),
                'rows' => $rows,
                'rows_filtered_out' => $filteredOut,
                'rows_not_shown' => $notShown,
                // Review finding 2: the empty text tells "all reconciled" from "none older than N days".
                'empty_text' => $rows || $notShown ? null : ($filteredOut
                    ? "No uncleared entries older than {$olderThan} days. {$filteredOut} newer uncleared "
                        .($filteredOut === 1 ? 'entry is' : 'entries are').' included in the totals.'
                    : 'All bank entries up to '.$settings->formatDate($asAt).' are reconciled.'),
            ];
        }

        $statement = null;
        if ($p->extra['statement_id']) {
            $s = BankStatement::withoutGlobalScopes()->with(['completedBy', 'undoneBy'])->findOrFail($p->extra['statement_id']);
            $f = $this->figures((int) $s->account_id, $asAt);
            $people = $this->people(array_filter([$s->completed_by, $s->undone_by]), (int) $s->branch_id);
            $statement = [
                'id' => $s->id,
                'status' => $s->status,
                'statement_date' => $asAt,
                'statement_balance' => Money::fromCents($s->statement_balance_cents),
                'overdrawn' => $s->statement_balance_cents < 0,
                'stored' => [
                    'reconciled' => Money::fromCents($s->reconciled_cents),
                    'book_balance' => Money::fromCents($s->book_balance_cents),
                    'uncleared_receipts' => Money::fromCents($s->uncleared_receipts_cents),
                    'uncleared_payments' => Money::fromCents($s->uncleared_payments_cents),
                    'cleared_booked_later' => Money::fromCents($s->cleared_booked_later_cents),
                ],
                'expected_bank_balance' => Money::fromCents($f['expected']),
                'difference' => Money::fromCents($s->statement_balance_cents - $f['expected']),
                'changed_since_completion' => $this->changed($s, $f),
                'late_entries' => $this->lateEntries($s),
                'lines_count' => $s->lines_count,
                'self_count' => $s->self_count,
                'completed_by' => $people[(int) $s->completed_by] ?? null,
                'completed_at' => $s->completed_at->toIso8601String(),
                'undone' => $s->status === BankStatement::UNDONE ? ['by' => $people[(int) $s->undone_by] ?? null,
                    'at' => $s->undone_at?->toIso8601String(), 'reason' => $s->undo_reason] : null,
            ];
        }

        return array_merge(app(ReportService::class)->meta($p), [
            'provisional' => false, // a status as at a date, not period figures (S3)
            'as_at' => $asAt,
            'older_than' => $olderThan,
            'older_than_ignored' => $p->extra['older_than_ignored'] ?? false,
            'filter_note' => self::filterNote($olderThan, $p->extra['older_than_ignored'] ?? false),
            'basis' => self::BASIS,
            'accounts' => $out,
            'statement' => $statement,
        ]);
    }

    /** Review finding 2: the Older than filter (or that it was ignored), printed with the header on screen, PDF and Excel. */
    public static function filterNote(?int $olderThan, bool $ignored): ?string
    {
        if ($ignored) {
            return 'Older than is ignored for a reconciliation statement: every uncleared entry is listed.';
        }

        return $olderThan === null ? null
            : "Rows limited to entries older than {$olderThan} days; totals cover all uncleared entries.";
    }

    private function row(object $r): array
    {
        return [
            'entry_id' => (int) $r->id,
            'transaction_id' => (int) $r->transaction_id,
            'date' => substr((string) $r->entry_date, 0, 10),
            'ref_no' => $r->ref_no,
            'type' => $r->type,
            'reversal_of_ref' => $r->reversal_of_ref,
            'description' => (string) ($r->payee_payer ?: $r->description),
            'reference' => $r->reference,
            'in' => Money::fromCents((int) $r->dr),
            'out' => Money::fromCents((int) $r->cr),
        ];
    }

    /** Detail rows of the report (export caps). */
    public static function reportRows(array $d): int
    {
        return (int) array_sum(array_map(fn ($a) => count($a['rows']) + count($a['cleared_booked_later_rows']), $d['accounts']))
            + count($d['statement']['late_entries'] ?? []);
    }

    // ------------------------------------------------------------------ AG-033c S6 statements list

    /** R4: at most this many statements may match the SQL filters; more is a refusal, never a silent cut. */
    public const LIST_CAP = 2000;

    public const LIST_TOO_MANY = 'Too many statements match: narrow the filters (at most 2,000).';

    public const LIST_BASIS = 'Completed and undone bank reconciliation statements by statement date. Figures as stored at completion; '
        ."'Changed since completion' and late entries are worked out now.";

    public const COPIES_UNAVAILABLE = 'Bank statement copies are not available yet.';

    /** The filter labels of the screen and the exports (accounting review finding 4). */
    public const LIST_FILTER_LABELS = [
        'status' => ['all' => 'All', 'completed' => 'Completed', 'undone' => 'Undone'],
        'changed' => ['any' => 'All', 'yes' => 'Yes', 'no' => 'No'],
        'late' => ['any' => 'All', 'some' => 'Some', 'none' => 'None'],
        'copy' => ['any' => 'All', 'attached' => 'Attached', 'none' => 'None'],
    ];

    /** Validate the S6 filters (section 5); branches as reports R1, accounts must be bank accounts in that set. */
    public function statementListParams(array $input): ReportParams
    {
        $d = validator($input, [
            'branches' => 'nullable|array',
            'branches.*' => 'integer|distinct',
            'account_ids' => 'nullable|array',
            'account_ids.*' => 'integer|distinct',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'status' => 'nullable|in:all,completed,undone',
            'changed' => 'nullable|in:any,yes,no',
            'late' => 'nullable|in:any,some,none',
            'copy' => 'nullable|in:any,attached,none',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ])->validate();
        $today = BusinessDate::today();
        $to = $d['to'] ?? $today;
        $from = $d['from'] ?? CarbonImmutable::parse($today)->subMonthsNoOverflow(12)->format('Y-m-d');
        if ($to > $today) {
            throw ValidationException::withMessages(['to' => 'The To date cannot be in the future.']);
        }
        if ($from > $to) {
            throw ValidationException::withMessages(['from' => 'From must be on or before To.']);
        }
        $copy = $d['copy'] ?? 'any';
        if ($copy !== 'any' && ! ReportService::statementCopiesSupported()) {
            throw ValidationException::withMessages(['copy' => self::COPIES_UNAVAILABLE]);
        }
        [$branches, $all, $subset] = app(ReportService::class)->branchScope(array_map('intval', $d['branches'] ?? []));
        $accountIds = array_map('intval', $d['account_ids'] ?? []);
        if ($accountIds) {
            $ok = DB::table('accounts')->where('type', 'bank')->whereIn('id', $accountIds)->whereIn('branch_id', $branches->pluck('id'))->count();
            if ($ok !== count($accountIds)) {
                throw ValidationException::withMessages(['account_ids' => 'Unknown bank account.']);
            }
        }

        return new ReportParams($from, $to, $branches, $all, $subset, [
            'account_ids' => $accountIds,
            'status' => $d['status'] ?? 'all',
            'changed' => $d['changed'] ?? 'any',
            'late' => $d['late'] ?? 'any',
            'copy' => $copy,
            'page' => (int) ($d['page'] ?? 1),
            'per_page' => (int) ($d['per_page'] ?? 50),
        ]);
    }

    /**
     * S6 data (section 5). Read only. $paged false: every matching row (the exports). The SQL filters (branch, account,
     * statement date, status, copy) are capped at LIST_CAP before the computed ones (changed, late), so the same SQL
     * filters always give the same answer (R4).
     */
    public function statementList(ReportParams $p, bool $paged = true): array
    {
        $x = $p->extra;
        $copies = ReportService::statementCopiesSupported();
        $ctx = app(BranchContext::class);
        // R1: the branch scope in branch mode; in All mode the set resolved by branchScope(), filtered explicitly.
        $q = ($ctx->isScoped() ? BankStatement::query() : BankStatement::withoutGlobalScopes())
            ->whereIn('bank_statements.branch_id', $p->branchIds())
            ->when($x['account_ids'], fn ($q, $ids) => $q->whereIn('bank_statements.account_id', $ids))
            ->whereDate('bank_statements.statement_date', '>=', $p->from)
            ->whereDate('bank_statements.statement_date', '<=', $p->to)
            ->when($x['status'] !== 'all', fn ($q) => $q->where('bank_statements.status', $x['status']));
        if ($copies && $x['copy'] !== 'any') {
            $active = fn ($s) => $s->selectRaw('1')->from('attachments as att')->whereColumn('att.bank_statement_id', 'bank_statements.id')->whereNull('att.removed_at');
            $x['copy'] === 'attached'
                ? $q->whereExists($active)
                : $q->where('bank_statements.status', BankStatement::COMPLETED)->whereNotExists($active);
        }
        if ((clone $q)->count() > self::LIST_CAP) {
            self::fail(self::LIST_TOO_MANY);
        }
        $statements = $q->with(['account:id,code,name,status,branch_id', 'branch:id,code,name,is_main'])->get();

        // R3: late lines of every completed statement in one grouped query; `changed` only where there is a late line.
        $late = $this->lateCounts($statements->where('status', BankStatement::COMPLETED));
        $changed = [];
        $changedOf = function (BankStatement $s) use ($late, &$changed): bool {
            return $changed[$s->id] ??= $s->status === BankStatement::COMPLETED && ($late[$s->id]['n'] ?? 0) > 0 && $this->changed($s);
        };
        $rows = $statements->filter(function (BankStatement $s) use ($x, $late, $changedOf) {
            $completed = $s->status === BankStatement::COMPLETED;
            $n = $late[$s->id]['n'] ?? 0;

            return match ($x['changed']) {
                'yes' => $changedOf($s),
                'no' => ! $changedOf($s),
                default => true,
            } && match ($x['late']) {
                'some' => $completed && $n > 0,
                'none' => $completed && $n === 0,
                default => true,
            };
        })->sort(fn (BankStatement $a, BankStatement $b) => [$b->statement_date->format('Y-m-d'), $a->branch->is_main ? 0 : 1, $a->branch->code, (string) $a->account->code]
            <=> [$a->statement_date->format('Y-m-d'), $b->branch->is_main ? 0 : 1, $b->branch->code, (string) $b->account->code]
            ?: [$b->completed_at?->getTimestamp(), $b->id] <=> [$a->completed_at?->getTimestamp(), $a->id])->values();

        $total = $rows->count();
        $page = $paged ? $x['page'] : 1;
        $perPage = $paged ? $x['per_page'] : max(1, $total);
        $rows = $paged ? $rows->forPage($page, $perPage)->values() : $rows;

        $files = $copies ? $this->statementFiles($rows->pluck('id')->all()) : [];
        $people = [];
        foreach ($rows->groupBy('branch_id') as $branchId => $group) {
            // R2: the controls R10 tag is per branch.
            $people[(int) $branchId] = $this->people($group->pluck('completed_by')->merge($group->pluck('undone_by'))->filter()->unique()->values()->all(), (int) $branchId);
        }

        $data = $rows->map(function (BankStatement $s) use ($late, $changedOf, $files, $copies, $people) {
            $completed = $s->status === BankStatement::COMPLETED;
            $who = $people[(int) $s->branch_id] ?? [];

            return [
                'id' => $s->id,
                'branch' => ['id' => $s->branch->id, 'code' => $s->branch->code],
                'account' => ['id' => $s->account->id, 'code' => $s->account->code, 'name' => $s->account->name, 'status' => $s->account->status],
                'statement_date' => $s->statement_date->format('Y-m-d'),
                'statement_balance' => Money::fromCents($s->statement_balance_cents),
                'lines_count' => $s->lines_count,
                'self_count' => $s->self_count,
                'status' => $s->status,
                'completed_by' => $who[(int) $s->completed_by] ?? null,
                'completed_at' => $s->completed_at?->toIso8601String(),
                'undone_by' => $s->undone_by ? ($who[(int) $s->undone_by] ?? null) : null,
                'undone_at' => $s->undone_at?->toIso8601String(),
                'undo_reason' => $s->undo_reason,
                'changed_since_completion' => $changedOf($s),
                'late_entries_count' => $completed ? ($late[$s->id]['n'] ?? 0) : null,
                'late_voided_count' => $completed ? ($late[$s->id]['v'] ?? 0) : null,
                'attachments_count' => $copies ? ($files[$s->id]['active'] ?? 0) : null,
                'attachments_removed_count' => $copies ? ($files[$s->id]['removed'] ?? 0) : null,
            ];
        })->values()->all();

        $accounts = $this->listAccounts($p->branchIds());
        $coverage = $this->coverage($p, $accounts);

        return array_merge(app(ReportService::class)->meta($p), [
            'provisional' => false, // the stored figures (finding 4)
            'basis' => self::LIST_BASIS,
            'filters' => ['status' => $x['status'], 'changed' => $x['changed'], 'late' => $x['late'], 'copy' => $x['copy'], 'account_ids' => $x['account_ids']],
            'copies_supported' => $copies,
            'accounts' => $accounts->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name,
                'branch' => ['id' => $a->branch->id, 'code' => $a->branch->code], 'status' => $a->status,
                'last_statement_date' => $a->getAttribute('last_statement_date')])->values()->all(),
            'coverage' => $coverage,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'data' => $data,
        ]);
    }

    /**
     * R3: per completed statement, the lines lateEntries() lists (n) and how many of them are void contras (v), in one
     * grouped query (the same rule as lateQuery: dated on or before S, line id above max_entry_id).
     *
     * @param  Collection<int, BankStatement>  $statements
     * @return array<int, array{n: int, v: int}>
     */
    private function lateCounts(Collection $statements): array
    {
        if ($statements->isEmpty()) {
            return [];
        }

        return DB::table('bank_statements as s')
            ->join('ledger_entries as le', fn ($j) => $j->on('le.account_id', '=', 's.account_id')->on('le.id', '>', 's.max_entry_id'))
            ->join('transactions as t', 't.id', '=', 'le.transaction_id')
            ->whereIn('s.id', $statements->pluck('id')->all())
            ->whereRaw('DATE(le.entry_date) <= DATE(s.statement_date)')
            ->groupBy('s.id')
            ->selectRaw('s.id as sid, COUNT(le.id) as n, SUM(CASE WHEN le.contra_of_id IS NULL THEN 0 ELSE 1 END) as v')
            ->get()->mapWithKeys(fn ($r) => [(int) $r->sid => ['n' => (int) $r->n, 'v' => (int) $r->v]])->all();
    }

    /**
     * R6: active and removed bank statement copies per statement (only when AG-033b's column exists).
     *
     * @return array<int, array{active: int, removed: int}>
     */
    private function statementFiles(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return DB::table('attachments')->whereIn('bank_statement_id', $ids)->groupBy('bank_statement_id')
            ->selectRaw('bank_statement_id as sid, SUM(CASE WHEN removed_at IS NULL THEN 1 ELSE 0 END) as active, '
                .'SUM(CASE WHEN removed_at IS NULL THEN 0 ELSE 1 END) as removed')
            ->get()->mapWithKeys(fn ($r) => [(int) $r->sid => ['active' => (int) $r->active, 'removed' => (int) $r->removed]])->all();
    }

    /**
     * Bank accounts of the branch set (inactive included), Main first, then branch code, account code, each with
     * `last_statement_date` (latest completed statement).
     *
     * @param  int[]  $branchIds
     * @return Collection<int, Account>
     */
    private function listAccounts(array $branchIds): Collection
    {
        $accounts = Account::withoutGlobalScopes()->with('branch:id,code,is_main')->where('type', 'bank')->whereIn('branch_id', $branchIds)
            ->get(['id', 'code', 'name', 'status', 'branch_id', 'type'])
            ->sortBy(fn (Account $a) => [$a->branch->is_main ? 0 : 1, $a->branch->code, (string) $a->code, $a->name])->values();
        $last = self::lastReconciledDates($accounts->pluck('id')->all());
        foreach ($accounts as $a) {
            $a->setAttribute('last_statement_date', $last[$a->id] ?? null);
        }

        return $accounts;
    }

    /** R9: the last month-end on or before $to. */
    public static function reconciledTo(string $to): string
    {
        $d = CarbonImmutable::parse($to);

        return $d->isLastOfMonth() ? $d->format('Y-m-d') : $d->startOfMonth()->subDay()->format('Y-m-d');
    }

    /**
     * R9 coverage line: bank accounts in the branch set and the Accounts filter, with a line dated on or before the cut-off,
     * active or with a non-zero book balance there, whose latest completed statement is before the cut-off (or none).
     * Not affected by from, status, changed, late, copy or paging. Information only.
     *
     * @param  Collection<int, Account>  $accounts  listAccounts() of the branch set
     */
    private function coverage(ReportParams $p, Collection $accounts): array
    {
        $cut = self::reconciledTo($p->to);
        $ids = $p->extra['account_ids'];
        $scope = $ids ? $accounts->filter(fn (Account $a) => in_array($a->id, $ids, true))->values() : $accounts;
        $books = $scope->isEmpty() ? collect() : DB::table('ledger_entries as le')->whereIn('le.account_id', $scope->pluck('id')->all())
            ->whereDate('le.entry_date', '<=', $cut)->groupBy('le.account_id')
            ->selectRaw("le.account_id as aid, COALESCE(SUM({$this->dr()} - {$this->cr()}), 0) as c")
            ->get()->mapWithKeys(fn ($r) => [(int) $r->aid => (int) $r->c]);
        $out = [];
        foreach ($scope as $a) {
            if (! $books->has($a->id) || ($a->status !== 'active' && $books[$a->id] === 0)) {
                continue;
            }
            $last = $a->getAttribute('last_statement_date');
            if ($last !== null && $last >= $cut) {
                continue;
            }
            $out[] = ['account' => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'status' => $a->status],
                'branch' => ['id' => $a->branch->id, 'code' => $a->branch->code], 'last_statement_date' => $last];
        }

        return ['reconciled_to' => $cut, 'not_reconciled' => $out];
    }

    /** R9 text, as on S6: "Not reconciled to 30 Nov 2026: GAL Bank (12002), last statement 31 Oct 2026; ..." */
    public static function coverageText(array $coverage): string
    {
        $to = self::label($coverage['reconciled_to']);
        if (! $coverage['not_reconciled']) {
            return "Every bank account in scope is reconciled to {$to}.";
        }

        return "Not reconciled to {$to}: ".implode('; ', array_map(fn ($n) => $n['account']['name']
            .($n['account']['code'] ? " ({$n['account']['code']})" : '')
            .($n['last_statement_date'] ? ', last statement '.self::label($n['last_statement_date']) : ', never reconciled'), $coverage['not_reconciled'])).'.';
    }

    /** Accounting review finding 4: the filters used, "All" when not filtered. */
    public static function listFilterText(array $d): string
    {
        $f = $d['filters'];
        $names = collect($d['accounts'])->keyBy('id');
        $accounts = $f['account_ids']
            ? implode(', ', array_map(fn ($id) => isset($names[$id]) ? $names[$id]['branch']['code'].' · '.$names[$id]['name'].($names[$id]['code'] ? " ({$names[$id]['code']})" : '') : "#{$id}", $f['account_ids']))
            : 'All';
        $parts = ['Status: '.self::LIST_FILTER_LABELS['status'][$f['status']], 'Changed since completion: '.self::LIST_FILTER_LABELS['changed'][$f['changed']],
            'Late entries: '.self::LIST_FILTER_LABELS['late'][$f['late']]];
        if ($d['copies_supported']) {
            $parts[] = 'Bank statement copy: '.self::LIST_FILTER_LABELS['copy'][$f['copy']];
        }
        $parts[] = 'Accounts: '.$accounts;

        return 'Filters: '.implode(' · ', $parts);
    }

    // ------------------------------------------------------------------ S4 period lock review

    /**
     * Bank accounts (active) of the lock's branches whose last completed statement is before the end of the month
     * being locked. Information only, never blocking (Q8).
     *
     * @param  int[]  $branchIds
     */
    public function lockReview(string $period, array $branchIds): array
    {
        $end = CarbonImmutable::createFromFormat('!Y-m', $period)->endOfMonth()->format('Y-m-d');
        $accounts = Account::withoutGlobalScopes()->with('branch:id,code,is_main')->where('type', 'bank')->where('status', 'active')
            ->whereIn('branch_id', $branchIds)->get()->sortBy(fn (Account $a) => [$a->branch->is_main ? 0 : 1, $a->branch->code, $a->code])->values();
        $out = [];
        foreach ($accounts as $a) {
            $last = $this->lastCompleted($a->id);
            if ($last && $last->statement_date->format('Y-m-d') >= $end) {
                continue;
            }
            $out[] = [
                'branch' => ['id' => $a->branch->id, 'code' => $a->branch->code],
                'account' => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name],
                'last_statement_date' => $last?->statement_date->format('Y-m-d'),
                'changed_since_completion' => $last ? $this->changedFast($last, $this->withLateLines(collect([$last]))) : false,
            ];
        }

        return $out;
    }

    /** SHOULD 10: latest completed statement date per bank account (for the late-entry warning on the forms). */
    public static function lastReconciledDates(array $accountIds): array
    {
        if (! $accountIds) {
            return [];
        }

        return self::installed(fn () => DB::table('bank_statements')->where('status', BankStatement::COMPLETED)->whereIn('account_id', $accountIds)
            ->groupBy('account_id')->selectRaw('account_id, MAX(statement_date) as d')->pluck('d', 'account_id')
            ->mapWithKeys(fn ($d, $id) => [(int) $id => substr((string) $d, 0, 10)])->all(), []);
    }

    /**
     * SHOULD 10 (follow-up): sets `last_reconciled_date` (Y-m-d or null) on the bank accounts among $accounts whose
     * reconciliation the user may see: a Super Admin every branch; anyone else only accounts of the active branch, and
     * only with `account.view` there (the same accounts the Accounts list shows them). Other accounts get no key, so a
     * picker or list never reveals another branch's reconciliation. Needs `id`, `type` and `branch_id` on each account.
     *
     * @param  iterable<Account|null>  $accounts
     */
    public static function attachLastReconciledDates(User $user, iterable $accounts): void
    {
        $branchId = app(BranchContext::class)->branchId;
        $mayView = $user->is_super_admin || ($branchId !== null && $user->canDo('account.view'));
        $visible = collect($accounts)->filter(fn ($a) => $a instanceof Account && $mayView && $a->type === 'bank'
            && ($user->is_super_admin || (int) $a->branch_id === (int) $branchId));
        $dates = self::lastReconciledDates($visible->pluck('id')->unique()->values()->all());
        foreach ($visible as $a) {
            $a->setAttribute('last_reconciled_date', $dates[$a->id] ?? null);
        }
    }

    /**
     * Runs a read of the reconciliation tables, or returns $none while they do not exist yet: earlier migrations (the
     * double-entry back-fill) post, void and list accounts before AG-033 is migrated (as YearEnd::closedYearOf). Only a
     * missing table is absorbed; any other error is thrown.
     */
    public static function installed(callable $read, mixed $none): mixed
    {
        try {
            return $read();
        } catch (QueryException $e) {
            if (! Schema::hasTable('bank_reconciliation_marks') || ! Schema::hasTable('bank_statements')) {
                return $none;
            }
            throw $e;
        }
    }
}
