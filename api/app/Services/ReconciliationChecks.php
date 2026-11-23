<?php

namespace App\Services;

use App\Events\ReconciliationCheckCancelled;
use App\Events\ReconciliationChecked;
use App\Events\ReconciliationCheckWithdrawn;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\BankStatementCheck;
use App\Models\Branch;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * AG-033d "Checked by" on bank reconciliation statements (docs/specs/reconciliation-checked-by.md). It posts nothing:
 * recording, re-checking, withdrawing or cancelling a check never writes ledger_entries, accounts.current_balance,
 * transactions, bank_reconciliation_marks, attachments or the figures and status of bank_statements (section 1, AC 28).
 * LedgerService never reads or locks checks (R5).
 */
class ReconciliationChecks
{
    public const NOT_COMPLETED = 'Only a completed statement can be checked.';

    public const PREPARER_COMPLETED = 'You completed this statement. Someone else must check it.';

    public const PREPARER_TICKED = 'You ticked lines in this statement. Someone else must check it.';

    public const ALREADY = 'This statement is already checked.';

    public const COMPARED_MESSAGE = 'Choose what you compared the statement with.';

    public const CONFIRM_MESSAGE = "Confirm that you compared the statement with the bank's own record.";

    public const NOTE_REQUIRED = 'Explain the late entries in the note (at least 10 characters).';

    public const NOTE_LONG = 'The note may be at most 500 characters.';

    public const WITHDRAW_WHO = 'Only the checker or a Super Admin can withdraw this check.';

    public const NOT_ACTIVE = 'This check is no longer active.';

    public const REASON_MESSAGE = 'Give the reason for withdrawing the check (at least 10 characters).';

    public const REASON_LONG = 'The reason may be at most 500 characters.';

    public const NOTE_MIN = 10;

    public const NOTE_MAX = 500;

    /** S3 wording of `compared_with`. */
    public const COMPARED_LABELS = ['bank_statement' => "the bank's statement", 'internet_banking' => 'internet banking'];

    /** Excel "Compared with" column. */
    public const COMPARED_SHORT = ['bank_statement' => "Bank's statement", 'internet_banking' => 'Internet banking'];

    public const STATUS_LABELS = ['checked' => 'Checked', 'recheck' => 'Re-check needed', 'not_checked' => 'Not checked', 'cancelled' => 'Check cancelled'];

    public function __construct(private BankReconciliation $recon, private YearEnd $yearEnd) {}

    private static ?bool $installed = null;

    /**
     * The checks table exists (earlier migrations and rolled-back test databases run without it). Memoised per process
     * like ReportService::statementCopiesSupported(); a migrate or rollback resets it (MigrationsEnded, forgetInstalled()).
     */
    public static function installed(): bool
    {
        return self::$installed ??= Schema::hasTable('bank_statement_checks');
    }

    public static function forgetInstalled(): void
    {
        self::$installed = null;
    }

    // ------------------------------------------------------------------ R2 segregation

    /** R2: the refusal for a preparer of $s (completed it, or ticked a line frozen in it), or null. */
    public function preparerReason(BankStatement $s, User $user): ?string
    {
        if ((int) $s->completed_by === (int) $user->id) {
            return self::PREPARER_COMPLETED;
        }
        $ticked = DB::table('bank_reconciliation_marks')->where('statement_id', $s->id)->where('marked_by', $user->id)->exists();

        return $ticked ? self::PREPARER_TICKED : null;
    }

    // ------------------------------------------------------------------ R3 record

    /**
     * R3: record a check (or a re-check) of a completed statement. One transaction: the account row, then the statement
     * row are locked (the order of Undo), so a check and an undo cannot race; the first failing rule wins.
     *
     * @return array{0: BankStatementCheck, 1: ?BankStatementCheck} the new check, and the check it superseded
     */
    public function record(Account $account, BankStatement $statement, array $input, User $user): array
    {
        return DB::transaction(function () use ($account, $statement, $input, $user) {
            $acc = Account::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);
            $s = BankStatement::withoutGlobalScopes()->where('account_id', $acc->id)->lockForUpdate()->findOrFail($statement->id);
            if ($s->status !== BankStatement::COMPLETED) {
                BankReconciliation::fail(self::NOT_COMPLETED);
            }
            if ($why = $this->preparerReason($s, $user)) {
                BankReconciliation::fail($why, 403);
            }
            $active = BankStatementCheck::withoutGlobalScopes()->where('bank_statement_id', $s->id)->active()->lockForUpdate()->first();
            $lateSince = $active ? ($this->lateSinceMap(collect([$active]))[$active->id] ?? 0) : null;
            if ($active && $lateSince === 0) {
                BankReconciliation::fail(self::ALREADY);
            }

            $compared = $input['compared_with'] ?? null;
            if (! is_string($compared) || ! in_array($compared, BankStatementCheck::COMPARED_WITH, true)) {
                throw ValidationException::withMessages(['compared_with' => self::COMPARED_MESSAGE]);
            }
            if (! self::accepted($input['confirmed'] ?? null)) {
                throw ValidationException::withMessages(['confirmed' => self::CONFIRM_MESSAGE]);
            }
            $note = $input['note'] ?? null;
            if ($note !== null && ! is_string($note)) {
                throw ValidationException::withMessages(['note' => self::NOTE_REQUIRED]);
            }
            $note = $note === null ? null : (trim($note) === '' ? null : trim($note));

            $date = $s->statement_date->format('Y-m-d');
            $changed = $this->recon->changed($s, $this->recon->figures((int) $acc->id, $date));
            $lateCount = $this->recon->lateCount($s);
            $required = $changed || $lateCount > 0 || $active !== null;
            if ($required && ($note === null || mb_strlen($note) < self::NOTE_MIN)) {
                throw ValidationException::withMessages(['note' => self::NOTE_REQUIRED]);
            }
            if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
                throw ValidationException::withMessages(['note' => self::NOTE_LONG]);
            }

            // The high-water mark is read under the account row lock, so no posting of the account slips under it.
            $maxEntryId = (int) DB::table('ledger_entries')->where('account_id', $acc->id)->max('id');
            $entries = $this->recon->statementEntries($s);
            $mine = array_filter($entries, fn ($e) => $e['created_by'] === (int) $user->id);
            // R8: a check in a locked month or a closed year is allowed and flagged (the same locking read as a withdraw).
            $afterClose = $this->yearEnd->closedOrLockedMessage((int) $s->branch_id, $date, share: true) !== null ? 'close' : null;
            $now = now();

            if ($active) {
                $active->update(['status' => BankStatementCheck::SUPERSEDED, 'ended_by' => $user->id, 'ended_at' => $now]);
            }
            try {
                $check = BankStatementCheck::create([
                    'branch_id' => $s->branch_id, 'bank_statement_id' => $s->id, 'account_id' => $acc->id, 'checked_by' => $user->id,
                    'checked_at' => $now, 'compared_with' => $compared, 'note' => $note, 'max_entry_id' => $maxEntryId,
                    'changed_at_check' => $changed, 'late_count_at_check' => $lateCount,
                    'checker_recorded_count' => count($mine), 'checker_uncleared_count' => count(array_filter($mine, fn ($e) => $e['uncleared'])),
                    'entries_count' => count($entries), 'after_close' => $afterClose, 'status' => BankStatementCheck::ACTIVE,
                ]);
            } catch (QueryException $e) {
                if (! self::isActiveCheckViolation($e)) {
                    throw $e;
                }
                BankReconciliation::fail(self::ALREADY); // the unique active_key under a race (R12)
            }
            if ($active) {
                $active->update(['superseded_by_id' => $check->id]);
            }

            AuditLog::record('reconciliation.checked', $s, $active ? [
                'check_id' => $active->id, 'status' => BankStatementCheck::ACTIVE, 'checked_by' => (int) $active->checked_by,
                'checked_at' => $active->checked_at->toIso8601String(), 'late_since_check' => $lateSince,
            ] : null, [
                'check_id' => $check->id, 'statement_id' => $s->id, 'account_id' => (int) $acc->id, 'statement_date' => $date,
                'statement_balance' => Money::fromCents($s->statement_balance_cents), 'compared_with' => $compared, 'confirmed' => true,
                'note' => $note, 'changed_at_check' => $changed, 'late_count_at_check' => $lateCount,
                'checker_recorded_count' => $check->checker_recorded_count, 'checker_uncleared_count' => $check->checker_uncleared_count,
                'entries_count' => $check->entries_count, 'after_close' => $afterClose, 'supersedes_id' => $active?->id,
            ]);
            $id = $check->id;
            DB::afterCommit(fn () => ReconciliationChecked::dispatch($id, $user->id));

            return [$check->fresh(), $active?->fresh()];
        }, LedgerService::ATTEMPTS);
    }

    /** Laravel's `accepted` rule: yes, on, 1, true. */
    private static function accepted(mixed $v): bool
    {
        return in_array($v, ['yes', 'on', '1', 1, true, 'true'], true);
    }

    /** The one-active-check-per-statement unique index (MySQL active_key, SQLite partial index). */
    public static function isActiveCheckViolation(QueryException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $msg = $e->getMessage();

        return ($driverCode === 1062 && str_contains($msg, 'bank_statement_checks_active_key_unique'))
            || ($driverCode === 19 && str_contains($msg, 'UNIQUE constraint failed: bank_statement_checks.bank_statement_id'));
    }

    // ------------------------------------------------------------------ R7 withdraw

    public function withdraw(Account $account, BankStatement $statement, BankStatementCheck $check, string $reason, User $user): BankStatementCheck
    {
        return DB::transaction(function () use ($account, $statement, $check, $reason, $user) {
            Account::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->id);
            $s = BankStatement::withoutGlobalScopes()->where('account_id', $account->id)->lockForUpdate()->findOrFail($statement->id);
            $c = BankStatementCheck::withoutGlobalScopes()->where('bank_statement_id', $s->id)->lockForUpdate()->findOrFail($check->id);
            $self = (int) $c->checked_by === (int) $user->id;
            if (! $self && ! $user->is_super_admin) {
                BankReconciliation::fail(self::WITHDRAW_WHO, 403);
            }
            if ($c->status !== BankStatementCheck::ACTIVE) {
                BankReconciliation::fail(self::NOT_ACTIVE);
            }
            // The sign-off the Super Admin relied on at lock does not change without an audited unlock (Super Admin too).
            if ($closed = $this->yearEnd->closedOrLockedMessage((int) $s->branch_id, $s->statement_date->format('Y-m-d'), share: true)) {
                BankReconciliation::fail($closed);
            }
            $c->update(['status' => BankStatementCheck::WITHDRAWN, 'ended_by' => $user->id, 'ended_at' => now(), 'end_reason' => $reason]);
            AuditLog::record('reconciliation.check_withdrawn', $s,
                ['check_id' => $c->id, 'status' => BankStatementCheck::ACTIVE, 'checked_by' => (int) $c->checked_by, 'checked_at' => $c->checked_at->toIso8601String()],
                ['status' => BankStatementCheck::WITHDRAWN, 'reason' => $reason, 'self' => $self]);
            $id = $c->id;
            DB::afterCommit(fn () => ReconciliationCheckWithdrawn::dispatch($id, $user->id));

            return $c->fresh();
        }, LedgerService::ATTEMPTS);
    }

    // ------------------------------------------------------------------ R6 undo

    /** R6: called by BankReconciliation::undo inside its transaction, after the statement row is locked and undone. */
    public function cancelOnUndo(BankStatement $s, string $undoReason, User $user): void
    {
        if (! self::installed()) {
            return;
        }
        $c = BankStatementCheck::withoutGlobalScopes()->where('bank_statement_id', $s->id)->active()->lockForUpdate()->first();
        if (! $c) {
            return;
        }
        $c->update(['status' => BankStatementCheck::CANCELLED, 'ended_by' => $user->id, 'ended_at' => now(), 'end_reason' => "Statement undone: {$undoReason}"]);
        AuditLog::record('reconciliation.check_cancelled', $s,
            ['check_id' => $c->id, 'status' => BankStatementCheck::ACTIVE, 'checked_by' => (int) $c->checked_by, 'checked_at' => $c->checked_at->toIso8601String()],
            ['status' => BankStatementCheck::CANCELLED, 'undo_reason' => $undoReason]);
        $id = $c->id;
        DB::afterCommit(fn () => ReconciliationCheckCancelled::dispatch($id, $user->id));
    }

    // ------------------------------------------------------------------ R4 status

    /**
     * R4 late_since_check per check, in one grouped query: lines of the check's account dated on or before S with an id
     * above the check's max_entry_id (void contras count).
     *
     * @param  Collection<int, BankStatementCheck>  $checks
     * @return array<int, int> check id => count (checks without such lines are absent)
     */
    public function lateSinceMap(Collection $checks): array
    {
        if ($checks->isEmpty()) {
            return [];
        }

        return DB::table('bank_statement_checks as c')->join('bank_statements as s', 's.id', '=', 'c.bank_statement_id')
            ->join('ledger_entries as le', fn ($j) => $j->on('le.account_id', '=', 'c.account_id')->on('le.id', '>', 'c.max_entry_id'))
            ->whereIn('c.id', $checks->pluck('id')->all())
            ->whereRaw('DATE(le.entry_date) <= DATE(s.statement_date)')
            ->groupBy('c.id')->selectRaw('c.id as cid, COUNT(le.id) as n')
            ->get()->mapWithKeys(fn ($r) => [(int) $r->cid => (int) $r->n])->all();
    }

    /** R4 check status. */
    public static function status(string $statementStatus, ?BankStatementCheck $active, bool $cancelled, ?int $lateSince): ?string
    {
        if ($statementStatus !== BankStatement::COMPLETED) {
            return $cancelled ? 'cancelled' : null;
        }
        if (! $active) {
            return 'not_checked';
        }

        return ($lateSince ?? 0) > 0 ? 'recheck' : 'checked';
    }

    /**
     * R4 for many statements, a fixed number of queries: per statement id, `status`, the active check summary (the S6 /
     * S1 `check` shape) and `late_since_check`.
     *
     * @param  Collection<int, BankStatement>  $statements
     * @return array<int, array{status: ?string, check: ?array, late_since_check: ?int}>
     */
    public function statusMap(Collection $statements): array
    {
        $out = [];
        if ($statements->isEmpty()) {
            return $out;
        }
        $checks = self::installed() ? BankStatementCheck::withoutGlobalScopes()->whereIn('bank_statement_id', $statements->pluck('id')->all())
            ->whereIn('status', [BankStatementCheck::ACTIVE, BankStatementCheck::CANCELLED])->get() : collect();
        $active = $checks->where('status', BankStatementCheck::ACTIVE)->keyBy('bank_statement_id');
        $cancelled = $checks->where('status', BankStatementCheck::CANCELLED)->pluck('bank_statement_id')->map(fn ($v) => (int) $v)->flip();
        $late = $this->lateSinceMap($active->values());
        $people = [];
        foreach ($active->groupBy('branch_id') as $branchId => $group) {
            $people[(int) $branchId] = $this->recon->people($group->pluck('checked_by')->unique()->values()->all(), (int) $branchId);
        }
        foreach ($statements as $s) {
            $c = $active[$s->id] ?? null;
            $n = $c ? ($late[$c->id] ?? 0) : null;
            $out[$s->id] = [
                'status' => self::status($s->status, $c, isset($cancelled[$s->id]), $n),
                'check' => $c ? [
                    'id' => $c->id,
                    'checked_by' => $people[(int) $c->branch_id][(int) $c->checked_by] ?? null,
                    'checked_at' => $c->checked_at->toIso8601String(),
                    'compared_with' => $c->compared_with,
                    'after_close' => $c->after_close,
                    'late_since_check' => $n,
                ] : null,
                'late_since_check' => $n,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ S3 reprint

    /**
     * S3: the check fields of the reprint `statement` (section 5): check_status, checks (oldest first), late_since_check,
     * check_actions for the signed-in user (none for a background export), `after_check` on each late entry, and
     * (AG-033b, when `attachments.bank_statement_id` exists) the ids of the statement files the active checker uploaded.
     */
    public function reprint(BankStatement $s, array $statement, ?User $user): array
    {
        $checks = self::installed() ? BankStatementCheck::withoutGlobalScopes()->where('bank_statement_id', $s->id)->orderBy('id')->get() : collect();
        $active = $checks->firstWhere('status', BankStatementCheck::ACTIVE);
        $lateSince = $active ? ($this->lateSinceMap(collect([$active]))[$active->id] ?? 0) : null;
        $people = $this->recon->people($checks->pluck('checked_by')->merge($checks->pluck('ended_by'))->filter()->unique()->values()->all(), (int) $s->branch_id);
        $branch = Branch::find($s->branch_id, ['id', 'code']);

        $statement['check_status'] = self::status($s->status, $active, $checks->contains('status', BankStatementCheck::CANCELLED), $lateSince);
        $statement['checks'] = $checks->map(fn (BankStatementCheck $c) => $this->present($c, $people, $branch))->values()->all();
        $statement['late_since_check'] = $lateSince;
        $statement['check_actions'] = $this->actions($s, $active, $lateSince, $user);
        $statement['late_entries'] = array_map(fn ($l) => $l + ['after_check' => $active !== null && $l['entry_id'] > (int) $active->max_entry_id],
            $statement['late_entries']);
        $statement['checker_attachment_ids'] = $active && ReportService::statementCopiesSupported()
            ? DB::table('attachments')->where('bank_statement_id', $s->id)->where('uploaded_by', $active->checked_by)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all()
            : null;

        return $statement;
    }

    /** S3 actions: can_check, can_withdraw and, for a user holding the keys, why the check button is disabled. */
    private function actions(BankStatement $s, ?BankStatementCheck $active, ?int $lateSince, ?User $user): array
    {
        $none = ['can_check' => false, 'can_withdraw' => false, 'blocked_reason' => null, 'withdraw_blocked_reason' => null];
        if (! $user || $s->status !== BankStatement::COMPLETED || ! self::installed()) {
            return $none;
        }
        $holds = $user->canDo('report.view') && $user->canDo('account.reconcile_check');
        if (! $holds) {
            return $none;
        }
        $blocked = $this->preparerReason($s, $user) ?? ($active && $lateSince === 0 ? self::ALREADY : null);

        $mayWithdraw = $active !== null && ((int) $active->checked_by === (int) $user->id || $user->is_super_admin);
        // R7: refused in a locked month or closed year (the withdraw transaction re-checks it with a locking read).
        $withdrawBlocked = $mayWithdraw ? $this->yearEnd->closedOrLockedMessage((int) $s->branch_id, $s->statement_date->format('Y-m-d')) : null;

        return [
            'can_check' => $blocked === null,
            'can_withdraw' => $mayWithdraw && $withdrawBlocked === null,
            'blocked_reason' => $blocked,
            'withdraw_blocked_reason' => $withdrawBlocked,
        ];
    }

    /** Section 5 `Check`. */
    public function present(BankStatementCheck $c, ?array $people = null, ?Branch $branch = null): array
    {
        $people ??= $this->recon->people(array_values(array_filter([(int) $c->checked_by, $c->ended_by ? (int) $c->ended_by : null])), (int) $c->branch_id);
        $branch ??= Branch::find($c->branch_id, ['id', 'code']);

        return [
            'id' => $c->id,
            'bank_statement_id' => (int) $c->bank_statement_id,
            'account_id' => (int) $c->account_id,
            'branch' => ['id' => (int) $c->branch_id, 'code' => $branch?->code],
            'checked_by' => $people[(int) $c->checked_by] ?? null,
            'checked_at' => $c->checked_at->toIso8601String(),
            'compared_with' => $c->compared_with,
            'note' => $c->note,
            'confirmed' => true,
            'changed_at_check' => (bool) $c->changed_at_check,
            'late_count_at_check' => (int) $c->late_count_at_check,
            'checker_recorded_count' => (int) $c->checker_recorded_count,
            'checker_uncleared_count' => (int) $c->checker_uncleared_count,
            'entries_count' => (int) $c->entries_count,
            'after_close' => $c->after_close,
            'status' => $c->status,
            'ended_by' => $c->ended_by ? ($people[(int) $c->ended_by] ?? null) : null,
            'ended_at' => $c->ended_at?->toIso8601String(),
            'end_reason' => $c->end_reason,
            'superseded_by_id' => $c->superseded_by_id ? (int) $c->superseded_by_id : null,
        ];
    }

    // ------------------------------------------------------------------ S3 / S6 texts (screen wording, PDF and Excel)

    /** "2026-12-10 14:05" in Asia/Colombo. */
    public static function at(string $iso): string
    {
        return CarbonImmutable::parse($iso)->setTimezone('Asia/Colombo')->format('Y-m-d H:i');
    }

    private static function name(?array $p): string
    {
        return $p ? $p['name'].($p['tag'] ? " ({$p['tag']})" : '') : '-';
    }

    /** S3 active check: "Checked by VW on 2026-12-10 14:05 against internet banking." */
    public static function checkedLine(array $c): string
    {
        return 'Checked by '.self::name($c['checked_by']).' on '.self::at($c['checked_at']).' against '.(self::COMPARED_LABELS[$c['compared_with']] ?? $c['compared_with']).'.';
    }

    /** S3 tags of the active check, each " - ...". */
    public static function tags(array $c): array
    {
        $tags = [];
        if ($c['after_close']) {
            $tags[] = ' - checked after close';
        }
        if ($c['checker_recorded_count'] > 0) {
            $tags[] = ' - the checker recorded '.self::recordedOf((int) $c['checker_recorded_count'], (int) $c['entries_count'])
                .($c['checker_uncleared_count'] > 0 ? ", {$c['checker_uncleared_count']} of them uncleared" : '');
        }
        if ($c['changed_at_check']) {
            $tags[] = ' - changed since completion when checked';
        }

        return $tags;
    }

    /** "1 of the 7 entries on this statement", "the only entry on this statement", "all 3 entries on this statement". */
    public static function recordedOf(int $n, int $of): string
    {
        return match (true) {
            $of === 1 && $n === 1 => 'the only entry on this statement',
            $n === $of => "all {$of} entries on this statement",
            default => "{$n} of the {$of} entries on this statement",
        };
    }

    /** "Re-check needed: 1 entry was recorded or voided after the check." */
    public static function recheckLine(int $n): string
    {
        return 'Re-check needed: '.($n === 1 ? '1 entry was' : "{$n} entries were").' recorded or voided after the check.';
    }

    /** An earlier (ended) check, for the S3 history. */
    public static function historyLine(array $c): string
    {
        $checked = 'checked by '.self::name($c['checked_by']).' on '.self::at($c['checked_at']);
        $ended = self::name($c['ended_by']).' on '.($c['ended_at'] ? self::at($c['ended_at']) : '-');

        return match ($c['status']) {
            BankStatementCheck::SUPERSEDED => "Superseded: {$checked}",
            BankStatementCheck::WITHDRAWN => "Withdrawn: {$checked}; withdrawn by {$ended}: {$c['end_reason']}",
            BankStatementCheck::CANCELLED => "Cancelled: {$checked}; statement undone by {$ended}",
            default => ucfirst($checked),
        };
    }

    /**
     * The S3 "Check" block as text lines (PDF and Excel): the active check with its tags and note, the re-check warning,
     * the earlier checks (oldest first), or "Not checked in the system.".
     *
     * @return string[]
     */
    public static function blockLines(array $st): array
    {
        $checks = $st['checks'] ?? [];
        $active = collect($checks)->firstWhere('status', BankStatementCheck::ACTIVE);
        $lines = [];
        if ($active) {
            $lines[] = self::checkedLine($active).implode('', self::tags($active));
            if ($active['note'] !== null && $active['note'] !== '') {
                $lines[] = 'Note: '.$active['note'];
            }
            if (($st['late_since_check'] ?? 0) > 0) {
                $lines[] = self::recheckLine((int) $st['late_since_check']);
            }
        } else {
            $lines[] = 'Not checked in the system.';
        }
        foreach ($checks as $c) {
            if ($c['status'] !== BankStatementCheck::ACTIVE) {
                $lines[] = self::historyLine($c);
            }
        }

        return $lines;
    }

    /** PDF signature line of the active check, or null (a blank Checked by line stays). */
    public static function signatureLine(array $st): ?string
    {
        $active = collect($st['checks'] ?? [])->firstWhere('status', BankStatementCheck::ACTIVE);
        if (! $active) {
            return null;
        }

        return 'Checked by '.self::name($active['checked_by']).' on '.self::at($active['checked_at'])." (recorded in Account-gold, check #{$active['id']})"
            .(($st['late_since_check'] ?? 0) > 0 ? ' - Re-check needed' : '');
    }

    /** S6 "Checked" cell: "Checked by VW, 2026-12-10", "Re-check needed (1 since check)", "Not checked", "Check cancelled" or ''. */
    public static function listText(array $r, bool $withChecker = false): string
    {
        $c = $r['check'] ?? null;
        $date = $c ? substr(self::at($c['checked_at']), 0, 10) : '';

        return match ($r['check_status'] ?? null) {
            'checked' => 'Checked by '.self::name($c['checked_by']).", {$date}",
            'recheck' => "Re-check needed ({$c['late_since_check']} since check)".($withChecker ? '; checked by '.self::name($c['checked_by']).", {$date}" : ''),
            'not_checked' => 'Not checked',
            'cancelled' => 'Check cancelled',
            default => '',
        };
    }

    // ------------------------------------------------------------------ S2 lock confirm

    /**
     * S2: per branch with months this lock newly closes, the completed statements of its bank accounts dated in those
     * months that are not checked, or whose check needs a re-check. Information only, never blocking (D4).
     *
     * @param  array<int, string>  $open  branch id => closed through before this lock ('' = nothing closed yet)
     * @return array<int, array{branch: array{id: int, code: string}, not_checked: array, recheck: array}>
     */
    public function lockReview(string $period, array $open): array
    {
        if (! $open || ! self::installed()) {
            return [];
        }
        $end = CarbonImmutable::createFromFormat('!Y-m', $period)->endOfMonth()->format('Y-m-d');
        $statements = BankStatement::withoutGlobalScopes()->with(['account:id,code,name', 'branch:id,code,is_main'])
            ->where('status', BankStatement::COMPLETED)->whereIn('branch_id', array_keys($open))
            ->whereDate('statement_date', '<=', $end)->get()
            ->filter(fn (BankStatement $s) => $open[(int) $s->branch_id] === '' || $s->statement_date->format('Y-m') > $open[(int) $s->branch_id])
            ->sortBy(fn (BankStatement $s) => [$s->statement_date->format('Y-m-d'), (string) $s->account->code, $s->id])->values();
        $map = $this->statusMap($statements);
        $out = [];
        $branches = Branch::whereIn('id', array_keys($open))->get(['id', 'code', 'is_main'])
            ->sortBy(fn (Branch $b) => [$b->is_main ? 0 : 1, $b->code])->values();
        foreach ($branches as $b) {
            $row = ['branch' => ['id' => $b->id, 'code' => $b->code], 'not_checked' => [], 'recheck' => []];
            foreach ($statements->where('branch_id', $b->id) as $s) {
                $status = $map[$s->id]['status'];
                if (in_array($status, ['not_checked', 'recheck'], true)) {
                    $row[$status][] = ['statement_id' => $s->id, 'account' => ['id' => $s->account->id, 'code' => $s->account->code, 'name' => $s->account->name],
                        'statement_date' => $s->statement_date->format('Y-m-d')];
                }
            }
            $out[] = $row;
        }

        return $out;
    }
}
