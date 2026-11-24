<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\ReportParams;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * AG-033f bell reminder for overdue bank reconciliations (docs/specs/reconciliation-overdue-reminder.md).
 *
 * It posts nothing: no LedgerService call, and it never writes ledger_entries, accounts.current_balance, transactions,
 * bank_reconciliation_marks, bank_statements, bank_statement_checks or settings. It writes only the dedupe record
 * (bank_reconciliation_reminders), the bell rows (notifications), read_at on older reminder rows (R7) and one system
 * audit row per reminder (R9). It is the only place that reads every branch (R10) and it returns nothing to a user.
 */
class ReconciliationReminders
{
    public const URL = '/reports/reconciliation-status?status=overdue';

    public const RECONCILE = 'account.reconcile';

    public const CHECK = 'account.reconcile_check';

    public const REPORT = 'report.view';

    /** @var array<int, array{ids: int[], fallback: bool}> recipients per branch id, cached for one run (R13) */
    private array $recipients = [];

    public function __construct(private BankReconciliation $reconciliation, private InAppNotifications $notifications) {}

    /**
     * One run (R1 to R12). Returns `lines` (one per overdue account), and the `sent`, `skipped` and `failed` counts.
     *
     * @return array{today: string, overdue_through: string, lines: string[], sent: int, skipped: int, failed: int}
     */
    public function run(bool $dryRun = false): array
    {
        $this->recipients = [];
        $ctx = app(BranchContext::class);
        $saved = [$ctx->branchId, $ctx->allBranches];
        $ctx->set(null, false); // R1: no BranchContext; every branch by design
        try {
            $today = BusinessDate::today();
            $branches = Branch::query()->orderByDesc('is_main')->orderBy('code')->get();
            $p = new ReportParams($today, $today, $branches, true, false, ['as_at' => $today, 'status' => 'overdue']);
            $status = $this->reconciliation->status($p);
            $e = $status['overdue_through'];
            $byBranch = $branches->keyBy('id');

            $out = ['today' => $today, 'overdue_through' => $e, 'lines' => [], 'sent' => 0, 'skipped' => 0, 'failed' => 0];
            foreach ($status['data'] as $row) {
                $branch = $byBranch[(int) $row['branch']['id']];
                $prefix = "{$branch->code} · {$row['account']['name']} ({$row['account']['code']}) · overdue through {$e} · ".self::monthsText((int) $row['months_overdue']);
                try {
                    $plan = $this->plan($row, $branch, $e);
                    if ($plan === null) {
                        $out['skipped']++;
                        $out['lines'][] = "{$prefix}: already sent";

                        continue;
                    }
                    $who = $this->names($plan['recipient_ids']);
                    $round = $plan['round'] > 1 ? " (round {$plan['round']}, re-send after an undo)" : '';
                    if ($dryRun) {
                        $out['lines'][] = "{$prefix}{$round}: would send to {$who}";

                        continue;
                    }
                    if ($this->send($plan)) {
                        $out['sent']++;
                        $out['lines'][] = "{$prefix}{$round}: sent to {$who}";
                    } else {
                        $out['skipped']++;
                        $out['lines'][] = "{$prefix}: already sent";
                    }
                } catch (\Throwable $ex) {
                    // R8: this account's transaction rolled back; report and go on with the next one.
                    report($ex);
                    $out['failed']++;
                    $out['lines'][] = "{$prefix}: FAILED ({$ex->getMessage()})";
                }
            }

            return $out;
        } finally {
            $ctx->set(...$saved);
        }
    }

    /**
     * R4 / R6: what to send for an Overdue row, or null when it was already sent for this E (and no later undo of a
     * statement dated on or after E calls for a new round).
     */
    protected function plan(array $row, Branch $branch, string $e): ?array
    {
        $accountId = (int) $row['account']['id'];
        $latest = DB::table('bank_reconciliation_reminders')->where('account_id', $accountId)->whereDate('overdue_through', $e)
            ->orderByDesc('round')->first(['round', 'created_at']);
        $undone = null;
        if ($latest !== null) {
            $undone = BankStatement::withoutGlobalScopes()->where('account_id', $accountId)->where('status', BankStatement::UNDONE)
                ->whereDate('statement_date', '>=', $e)->where('undone_at', '>', $latest->created_at)
                ->orderByDesc('undone_at')->orderByDesc('id')->first();
            if ($undone === null) {
                return null;
            }
        }
        $r = $this->recipientsOf($branch);

        return [
            'account' => Account::withoutGlobalScopes()->findOrFail($accountId),
            'branch' => $branch,
            'overdue_through' => $e,
            'round' => $latest === null ? 1 : (int) $latest->round + 1,
            'months_overdue' => (int) $row['months_overdue'],
            'last_statement_date' => $row['last_statement']['statement_date'] ?? null,
            'recipient_ids' => $r['ids'],
            'super_admin_fallback' => $r['fallback'],
            'undone' => $undone,
        ];
    }

    /**
     * R4 steps 1 to 3 in one transaction: insertOrIgnore the reminder row; when it was already there, stop; otherwise
     * close the superseded rows (R7 b), write the bell rows and the audit row (R9). Returns whether it was sent.
     */
    protected function send(array $plan): bool
    {
        return DB::transaction(function () use ($plan) {
            /** @var Account $account */
            $account = $plan['account'];
            $inserted = DB::table('bank_reconciliation_reminders')->insertOrIgnore([
                'branch_id' => $account->branch_id,
                'account_id' => $account->id,
                'overdue_through' => $plan['overdue_through'],
                'round' => $plan['round'],
                'months_overdue' => $plan['months_overdue'],
                'last_statement_date' => $plan['last_statement_date'],
                'recipient_ids' => json_encode($plan['recipient_ids']),
                'super_admin_fallback' => $plan['super_admin_fallback'],
                'created_at' => now(),
            ]);
            if ($inserted === 0) {
                return false;
            }

            // R7 (b): unread rows of earlier month-ends, and of earlier rounds of this one, become read.
            $this->closeWhere($account->id, $plan['overdue_through']);

            $data = $this->data($plan);
            $users = $plan['recipient_ids'] === [] ? collect() : User::whereIn('id', $plan['recipient_ids'])->orderBy('id')->get();
            foreach ($users as $u) {
                $this->notifications->write($u, InAppNotification::RECONCILIATION_OVERDUE, $data);
            }

            $new = [
                'account' => "{$account->name} ({$account->code})",
                'overdue_through' => $plan['overdue_through'],
                'months_overdue' => $plan['months_overdue'],
                'never_reconciled' => $plan['last_statement_date'] === null,
                'last_statement_date' => $plan['last_statement_date'],
                'recipient_ids' => $plan['recipient_ids'],
                'super_admin_fallback' => $plan['super_admin_fallback'],
                'round' => $plan['round'],
            ];
            if ($plan['round'] > 1) {
                $new['undone_statement_id'] = $plan['undone']->id;
            }
            $new['by_system'] = true;
            AuditLog::record('reconciliation.overdue_reminder_sent', $account, null, $new, (int) $account->branch_id,
                ['user_id' => null, 'ip' => null, 'user_agent' => 'console']);

            return true;
        }, LedgerService::ATTEMPTS);
    }

    /** Section 5 "Notification": the snapshot written per recipient. No amount, balance or bank account number. */
    private function data(array $plan): array
    {
        /** @var Account $account */
        $account = $plan['account'];
        /** @var Branch $branch */
        $branch = $plan['branch'];
        $last = $plan['last_statement_date'];
        $body = 'Not reconciled to '.BankReconciliation::label($plan['overdue_through']).'. '
            .($last === null ? 'Never reconciled' : 'Last statement '.BankReconciliation::label($last))
            .' · overdue '.self::monthsText($plan['months_overdue']).'.';
        if ($plan['round'] > 1) {
            /** @var BankStatement $s */
            $s = $plan['undone'];
            $body .= ' The statement to '.BankReconciliation::label($s->statement_date->format('Y-m-d')).' was undone on '
                .$s->undone_at->copy()->setTimezone(BusinessDate::timezone())->format('j M Y').'.';
        }
        if ($plan['super_admin_fallback']) {
            $body .= " No one in {$branch->code} can reconcile this account.";
        }

        return [
            'title' => "Bank reconciliation overdue: {$account->name} ({$account->code})",
            'body' => $body,
            'branch_id' => (int) $branch->id,
            'branch_code' => $branch->code,
            'url' => self::URL,
            'account_id' => (int) $account->id,
            'overdue_through' => $plan['overdue_through'],
            'months_overdue' => $plan['months_overdue'],
        ];
    }

    /**
     * R2 (with accounting review finding 3): the holders of X are its active members holding (account.reconcile or
     * account.reconcile_check) and report.view through active roles in X. MAIN: holders plus every active Super Admin.
     * Another branch: holders, plus every active Super Admin as fallback when no holder can reconcile (holds
     * account.reconcile and report.view). One id per user, sorted.
     *
     * @return array{ids: int[], fallback: bool}
     */
    private function recipientsOf(Branch $branch): array
    {
        if (isset($this->recipients[$branch->id])) {
            return $this->recipients[$branch->id];
        }
        $id = (int) $branch->id;
        $report = $this->notifications->holderIdsOf([self::REPORT], $id);
        $holderIds = array_values(array_intersect($this->notifications->holderIdsOf([self::RECONCILE, self::CHECK], $id), $report));
        $preparerIds = array_values(array_intersect($this->notifications->holderIdsOf([self::RECONCILE], $id), $report));
        $active = $holderIds === [] ? [] : User::whereIn('id', $holderIds)->where('status', 'active')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $fallback = false;
        $ids = $active;
        if ($branch->is_main) {
            $ids = array_merge($ids, $this->superAdminIds());
        } elseif (array_intersect($preparerIds, $active) === []) {
            $fallback = true;
            $ids = array_merge($ids, $this->superAdminIds());
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $this->recipients[$branch->id] = ['ids' => $ids, 'fallback' => $fallback];
    }

    /** @return int[] */
    private function superAdminIds(): array
    {
        return $this->notifications->superAdmins()->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function names(array $ids): string
    {
        if ($ids === []) {
            return 'no recipients';
        }
        $names = User::whereIn('id', $ids)->orderBy('id')->get(['id', 'name'])->map(fn (User $u) => "{$u->name} (#{$u->id})");

        return $names->implode(', ');
    }

    private static function monthsText(int $n): string
    {
        return $n.' '.($n === 1 ? 'month' : 'months');
    }

    // ------------------------------------------------------------------ R7 (a)

    /**
     * R7 (a): after a statement of $accountId is completed with date $statementDate, every unread reminder row of that
     * account with overdue_through on or before it becomes read, for every user. Only read_at changes; no audit.
     */
    public function closeFor(int $accountId, string $statementDate): int
    {
        return $this->closeWhere($accountId, $statementDate);
    }

    private function closeWhere(int $accountId, string $through): int
    {
        $now = now();

        return DatabaseNotification::query()->where('type', InAppNotification::RECONCILIATION_OVERDUE)->whereNull('read_at')
            ->where('data->account_id', $accountId)->where('data->overdue_through', '<=', $through)
            ->update(['read_at' => $now, 'updated_at' => $now]);
    }
}
