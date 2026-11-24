# Spec: Bell reminder for overdue bank reconciliations

## 1. Goal and scope
**AG-033f** (follow-up to AG-033e; not yet a row in `docs/Account-gold_Product_Backlog.xlsx`, Q9): "As an Accountant or checker, I want a reminder in the bell when a bank account of my branch becomes overdue for reconciliation, so that month-end close does not depend on someone remembering to open the status report."

Source: AG-033e accountant sign-off, finding 4 ("A report is a detective control only if someone opens it"), and AG-033e Q4 / Q5. The control is the AG-033e report S7 (`/reports/reconciliation-status`). This story adds one way to bring people to it.

**Smallest useful story:**
- **Channel: bell only** (AG-094 in-app notification), one new kind `reconciliation.overdue`. No email (Q2).
- **Timing:** a daily command. It sends **once per account per overdue month-end**. The month-end moves on the 11th of each month (10 grace days), so an account that stays overdue gets one new reminder a month, with the higher count. There is no mid-month re-reminder (Q3).
- **Rule:** exactly the AG-033e status. A reminder goes for each account that S7 (All mode) shows as **Overdue** at the time of the run. Due, Up to date and Not required accounts get nothing.
- **It posts nothing.** No `LedgerService` call. It never writes `ledger_entries`, `accounts.current_balance`, `transactions`, `bank_reconciliation_marks`, `bank_statements`, `bank_statement_checks` or settings. It writes only: `bank_reconciliation_reminders` (new, the dedupe record), `notifications` rows, and `read_at` on older reminder rows (R7), plus one audit row per reminder (R9).
- **Depends on AG-033e (PR #30), AG-033d and AG-033c.** It calls `BankReconciliation::status()` and merges after PR #30.

**Out of scope:**
- An email or monthly digest email (Q2), and any per-user preference or opt-out (Q4).
- A re-reminder inside the same month (Q3), and a reminder when an account is only Due.
- A Super Admin summary row across all branches (Q1).
- A dashboard tile (AG-033e Q3), configurable grace days (AG-033e Q1), a quarterly frequency (AG-033e Q2).
- A reminder about unchecked or re-check-needed statements (AG-033d Q6). S7's check line already counts them.
- Back-filling reminders for month-ends before the current one (R4).
- Any change to S7, S6, the bell layout or the notifications page, apart from one icon entry.

## 2. Personas and permissions
**No new key. `PermissionSeeder` is unchanged.** These are the real keys:

| Need | Key |
|---|---|
| Receive the reminder for a bank account of branch X | (`account.reconcile` **or** `account.reconcile_check`) **and** `report.view`, each through an active role in X, while an active member of X (R2) |
| Open the link (S7) | `report.view` (unchanged) |

| Template | `account.reconcile` | `account.reconcile_check` | `report.view` | Gets the reminder for its branch |
|---|---|---|---|---|
| Branch Admin (`*`) | yes | yes | yes | yes |
| Accountant | yes | no | yes | yes (the preparer) |
| Viewer | no | yes | yes | yes (the checker, AG-033d) |
| Cashier | no | no | no | no |
| Super Admin | all | all | all | MAIN accounts always; a branch account only as fallback (R2), or through a branch role they hold |

`report.view` is required because the link opens S7 and every AG-033d check route needs it too. A custom role with `account.reconcile` but without `report.view` gets nothing (Q7).

## 3. User flows
- **F1 Accountant, branch user (ACC, KDY).** On Fri 11 Dec 2026 at 07:15 (Asia/Colombo) the job runs. When ACC signs in, the bell shows 3. The rows are tagged KDY:
  - "Bank reconciliation overdue: KDY Bank 5 (11006)" · "Not reconciled to 30 Nov 2026. Last statement 15 Sep 2026 · overdue 3 months."
  - "Bank reconciliation overdue: KDY Bank 2 (11003)" · "Not reconciled to 30 Nov 2026. Never reconciled · overdue 1 month."
  - "Bank reconciliation overdue: KDY Bank (11002)" · "Not reconciled to 30 Nov 2026. Last statement 31 Oct 2026 · overdue 1 month."

  ACC clicks one. The row is marked read and `/reports/reconciliation-status?status=overdue` opens in KDY (AG-094 S3 open flow). From S7, ACC clicks "Reconcile".
- **F2 Checker (VW, Viewer KDY).** VW gets the same three rows. VW follows up with ACC, and checks the statements on S6 once they are completed.
- **F3 Reconciled.** On 12 Dec ACC completes KDY Bank to 30 Nov 2026. The unread KDY Bank reminder of ACC, VW and BA becomes read (R7). The other two stay unread. No new KDY Bank reminder is sent in December.
- **F4 Still overdue next month.** KDY Bank 5 is not done. On 11 Jan 2027 everyone in F1 gets "KDY Bank 5 (11006)" · "Not reconciled to 31 Dec 2026. Last statement 15 Sep 2026 · overdue 4 months." If the December row is still unread, it becomes read (superseded).
- **F5 Super Admin, All branches mode (SA).** SA gets the MAIN Main Account reminder (tag MAIN), and a branch reminder only when nobody in that branch can receive it (R2), with the extra sentence "No one in GAL can reconcile or check this account." Clicking switches from All branches to the tagged branch and opens S7 there (AG-094 F6). SA can switch back to All branches and see every branch on S7.
- **F6 Lost access.** VW is removed from KDY. VW's old KDY reminders are shown redacted (AG-094 R5). VW gets no new KDY reminder.
- **F7 Operations.** The command runs from the scheduler. It needs no queue worker. `php artisan reconciliation:remind-overdue --dry-run` shows what would be sent and writes nothing.

## 4. Screens
No new screen. Two touches:
- **Bell and `/notifications` (AG-094 S1, S2):** add `'reconciliation.overdue': '!'` to `KIND_ICON` in `layout/notification-bell.component.ts`. The row layout, the 390px panel, the open flow and the redaction are unchanged.
- **S7 `/reports/reconciliation-status` (AG-033e):** no change. It must open with the Status filter set from `?status=overdue` (it already reads its filters from the URL). In branch mode the Branches filter is hidden, as today.
- **Audit log:** the new event gets the label "Overdue reconciliation reminder sent". It is in the existing "Bank reconciliation" group (prefix `reconciliation.`). A system row shows "System" as its user.

## 5. API and backend
**No new HTTP endpoint and no changed endpoint.** Users read the reminders through the existing AG-094 endpoints (`GET /v1/notifications`, `/unread-count`, `POST /{id}/read`, `/read-all`).

**New command** `reconciliation:remind-overdue {--dry-run}` (`App\Console\Commands\ReconciliationRemindOverdue`). It calls the new `App\Services\ReconciliationReminders::run(bool $dryRun): array`.
- Output: one line per account (branch, account, overdue_through, months, recipients or "already sent"), then "Sent N reminder(s), skipped M already sent, F failed."
- Exit 0, or 1 when any account failed (R8).

**Schedule** (`routes/console.php`, next to the AG-094 prune):
```php
// AG-033f: bell reminder for overdue bank reconciliations (posts nothing; writes bell rows directly, no queue).
Schedule::command('reconciliation:remind-overdue')->description('Remind overdue bank reconciliations')
    ->dailyAt('07:15')->timezone($tz)->withoutOverlapping(60)->onOneServer();
```
- It uses the existing scheduler lock store (`Schedule::useCache(backup.lock_store)`).
- **Queue:** none. The bell rows are written synchronously through `InAppNotifications::write` (AG-094 R3, database channel). It does not use the default worker or the `exports` worker, and it adds no job class. A run is a few queries per branch plus one transaction per overdue account.

**New table** `bank_reconciliation_reminders` (migration `2026_12_08_000000_bank_reconciliation_reminders`):

| Column | Type |
|---|---|
| `id` | bigint |
| `branch_id` | FK branches |
| `account_id` | FK accounts |
| `overdue_through` | date (E of AG-033e R2) |
| `months_overdue` | unsigned int |
| `last_statement_date` | date, null = never reconciled |
| `recipient_ids` | json, sorted user ids |
| `super_admin_fallback` | bool |
| `created_at` | timestamp (UTC) |

- **Unique `(account_id, overdue_through)`.** This is the dedupe (R4).
- Model `BankReconciliationReminder` uses `BranchScoped`. The command reads it with `withoutGlobalScopes()`, because it works over every branch by design (R1).
- Rows are never updated or deleted. There is no route to them, and `notifications:prune` does not touch them.

**Notification** (`InAppNotification::RECONCILIATION_OVERDUE = 'reconciliation.overdue'`), written per recipient:

| Field | Value |
|---|---|
| `title` | "Bank reconciliation overdue: <account name> (<code>)" |
| `body` | "Not reconciled to <E, d M Y>. " + ("Last statement <date> · overdue N month(s)." or "Never reconciled · overdue N month(s).") + (fallback only) " No one in <branch code> can reconcile or check this account." |
| `branch_id`, `branch_code` | the account's branch |
| `url` | `/reports/reconciliation-status?status=overdue` |
| `account_id`, `overdue_through`, `months_overdue` | for R7 and tests |

The texts use `BankReconciliation::label()` dates and AG-033e month wording ("1 month", "3 months"). No amount, balance or bank account number.

**Hook in `BankReconciliation::complete`:** `DB::afterCommit(fn () => app(ReconciliationReminders::class)->closeFor($accountId, $statementDate))` (R7). It is wrapped in try/report, so a failure there never fails the Complete.

## 6. Business rules
- **R1 Which accounts.**
  - T = `BusinessDate::today()` at the run (Asia/Colombo).
  - The command builds `ReportParams(T, T, Branch::all(), allBranches: true, subset: false, ['as_at' => T, 'status' => 'overdue'])` with no `BranchContext`. It then calls `BankReconciliation::status()`. The accounts reminded are exactly its `data` rows: S7 in All mode with Status = Overdue. Inactive branches are included, as on S7.
  - There is no new date or overdue logic. Grace (10 days), never-reconciled, closed nil-balance (F) and undone statements all behave as AG-033e R2 to R5 say.
- **R2 Recipients for an account in branch X** (read at run time):
  - **Holders:** active users who are members of X and hold `account.reconcile` or `account.reconcile_check` **and** `report.view` through active roles in X. Use `InAppNotifications::holderIdsOf([...], X)` for the first set, intersected with `holderIdsOf(['report.view'], X)`, then active users only.
  - **X is MAIN:** holders plus every active Super Admin.
  - **X is another branch:** holders. When there are none, every active Super Admin instead (`super_admin_fallback = true`, extra body sentence). A Super Admin who holds a role in X counts as a holder.
  - Inactive users are never notified. One row per user per account (AG-094 R2). There is no actor.
- **R3 Timing.**
  - The run is daily at 07:15 Asia/Colombo. Running daily, and not only on the 11th, means that an account that becomes overdue later in the month is reminded the next morning. Examples: a statement undone, a closed account reopened, a back-dated first entry on a new account. A day the server was down is also caught up the next day.
  - The first overdue day of month-end M is M + 11 days (AG-033e R2), so the "monthly" reminder lands on the 11th.
- **R4 Dedupe.**
  - The key is `(account_id, overdue_through)`, where `overdue_through` = E computed from T. It is a business date, not a timestamp, so the server time zone (UTC or other) and the hour of the run do not matter. A run at 18:30 UTC on 10 Dec is 00:00 on 11 Dec in Colombo, so it already uses E = 30 Nov.
  - **Per account, in one DB transaction:**
    1. `insertOrIgnore` the reminder row.
    2. When nothing was inserted (already sent), stop.
    3. Otherwise write the bell rows, close the superseded rows (R7), write the audit row (R9) and commit.

    Reruns, a manual run during the scheduled one, and two servers therefore produce one reminder at most. A crash before the commit leaves nothing, and the next run sends it.
  - **No back-fill:** only the current E is considered. On the first run after release, each currently overdue account gets one reminder, with its full months count.
  - A reminder row is written even when there are 0 recipients (no holders and no active Super Admin), so the run does not retry every day. Its audit row shows `recipient_ids: []`.
- **R5 Reconciled before the reminder.** An account that is Up to date, Due or Not required at the run gets nothing. This includes an account reconciled on the 10th that is never overdue.
- **R6 Undone after the reminder.** The dedupe key does not change, so there is no second reminder for the same E. S7 shows the account as Overdue again. The next reminder comes with the next E (Q5).
- **R7 Closing old rows.** Only `read_at` changes (AG-094 R6 style). No audit, and the rows are not deleted.
  - **(a) Reconciled.** After a statement for account A is completed with date D, every unread `reconciliation.overdue` row with `data.account_id = A` and `data.overdue_through <= D` gets `read_at = now`, for every user.
  - **(b) Superseded.** When a new reminder for A with E is written, unread rows for A with `overdue_through < E` get `read_at = now`.
  - A partial catch-up (for example to 31 Oct when E = 30 Nov) closes nothing. The row stays unread until the account is reconciled to E.
- **R8 Failure isolation.** When one account throws (for example the notifications insert fails), its transaction rolls back, the exception is reported (`report()`), and the run goes on with the next account. Exit code 1. The next run retries that account, because no reminder row was committed.
- **R9 Audit.** For each reminder row inserted (not on dry run), the run writes `AuditLog::record('reconciliation.overdue_reminder_sent', $account, null, [...], $account->branch_id, ['user_id' => null, 'ip' => null, 'user_agent' => 'console'])` with these new values:
  - `account`: "KDY Bank 5 (11006)";
  - `overdue_through`, `months_overdue`, `never_reconciled`, `last_statement_date`;
  - `recipient_ids` (sorted), `super_admin_fallback`;
  - `by_system: true`.

  Opening, reading, mark-all and R7 auto-read write no audit (AG-094 R10). A branch user with `audit.view` sees the rows of their branch only (the existing audit scoping).
- **R10 Branch isolation.**
  - A reminder carries its account's branch. It goes only to members of that branch (or to Super Admins).
  - The link opens S7, which re-checks access on the server.
  - A user removed from the branch sees the old rows redacted (AG-094 R5).
  - The command is the only place that reads every branch. It never returns data to a user.
- **R11 Retention.** Bell rows are pruned after 90 days like every notification (AG-094 R11). `bank_reconciliation_reminders` rows and audit rows are kept. A pruned bell row never causes a resend, because the dedupe is the reminder table.
- **R12 Dry run.** `--dry-run` runs R1 and R2 and prints what would be sent ("would send" or "already sent"). It writes no reminder, notification or audit row.
- **R13 Volume.** S7 is a fixed number of queries (AG-033e R8). Recipients are one `holderIdsOf` pair per branch with an overdue account, cached for the run. Bell rows are at most one per recipient per overdue account per month.

## 7. Acceptance criteria
**Setup.** Branches MAIN, KDY, GAL. Unless an AC says otherwise:
- The clock is frozen with `Carbon::setTestNow('2026-12-11 01:45:00 UTC')` (07:15 Asia/Colombo), so T = 2026-12-11 and E = 2026-11-30.
- The PHP default time zone is UTC.

Bank accounts:
- KDY Bank (11002): last completed statement 2026-10-31.
- KDY Bank 5 (11006): last completed statement 2026-09-15.
- KDY Bank 2 (11003): first line 2026-11-05; no statement.
- KDY Bank 3 (11004): no lines.
- KDY Cash: a cash account.
- GAL Bank (12002): last completed statement 2026-11-30.
- MAIN Main Account: one capital line dated 2026-08-01; no statement.

Users:
- KDY: ACC (Accountant), VW (Viewer), BA (Branch Admin), CSH (Cashier), VW2 (Viewer, `status = inactive`), REC (custom KDY role "Reconciler" with `account.reconcile` only).
- GAL: GACC (Accountant).
- MAIN: MBA (Branch Admin).
- Super Admins: SA and SA2 (active), SA3 (inactive).

1. Given the setup, when `reconciliation:remind-overdue` runs, then `bank_reconciliation_reminders` has exactly 4 rows, all with `overdue_through` 2026-11-30:
   - KDY Bank 5: months 3;
   - KDY Bank 2: months 1, `last_statement_date` null;
   - KDY Bank: months 1;
   - MAIN Main Account: months 4, `last_statement_date` null.

   KDY Bank 3, KDY Cash and GAL Bank have none. The command prints "Sent 4 reminder(s), skipped 0 already sent, 0 failed." and exits 0.
2. Given AC 1, then:
   - ACC, VW and BA each have exactly 3 unread `reconciliation.overdue` rows (KDY Bank 5, KDY Bank 2, KDY Bank) with `branch_code` KDY;
   - MBA, SA and SA2 each have exactly 1 (MAIN Main Account, `branch_code` MAIN);
   - CSH, VW2, REC, GACC and SA3 have none;
   - SA and SA2 have no KDY row.
3. Given AC 1, then ACC's KDY Bank 5 row has:
   - title "Bank reconciliation overdue: KDY Bank 5 (11006)";
   - body "Not reconciled to 30 Nov 2026. Last statement 15 Sep 2026 · overdue 3 months.";
   - `url` `/reports/reconciliation-status?status=overdue`;
   - `data.account_id` = KDY Bank 5, `data.overdue_through` "2026-11-30", `data.months_overdue` 3.

   The KDY Bank 2 body is "Not reconciled to 30 Nov 2026. Never reconciled · overdue 1 month.". No row's `data` contains an amount, a balance or "LKR".
4. Given AC 1, when the command runs again at 2026-12-11 09:00 Colombo, and on every day from 2026-12-12 to 2026-12-31, then:
   - no reminder, notification or audit row is added;
   - each run prints "Sent 0 reminder(s), skipped 4 already sent, 0 failed.".
5. **Time zone.**
   - Given the clock at 2026-12-10 18:29:59 UTC (23:59:59 on 10 Dec in Colombo), when the command runs, then no reminder with `overdue_through` 2026-11-30 is created. KDY Bank and KDY Bank 2 get nothing (Due).
   - Given 2026-12-10 18:30:00 UTC (00:00 on 11 Dec in Colombo), then the 4 reminders of AC 1 are created.
   - The result is the same with the PHP default time zone set to `America/Los_Angeles`.
6. **Grace and supersede.** Given the clock at 2026-12-10 07:15 Colombo (E = 2026-10-31), when the command runs, then exactly 2 reminders are created:
   - KDY Bank 5, overdue_through 2026-10-31, months 2;
   - MAIN Main Account, months 3.

   Given those rows are unread, when it runs on 2026-12-11, then the 4 reminders of AC 1 are created. The 10 Dec KDY Bank 5 and MAIN rows now have `read_at` set. The table has 6 rows.
7. **Reconciled.** Given AC 1, when ACC completes KDY Bank to 2026-11-30 on 2026-12-12, then:
   - ACC's, VW's and BA's KDY Bank rows have `read_at` set;
   - their KDY Bank 5 and KDY Bank 2 rows are still unread;
   - no audit row other than `reconciliation.completed` is written;
   - the run on 2026-12-13 creates nothing.
8. **Partial catch-up.** Given AC 1, when ACC completes KDY Bank 5 to 2026-10-31, then its rows stay unread and the December runs create nothing for it. On 2027-01-11 07:15 Colombo, the run creates a KDY Bank 5 reminder with `overdue_through` 2026-12-31, months 2, body "Not reconciled to 31 Dec 2026. Last statement 31 Oct 2026 · overdue 2 months.". The 2026-11-30 rows, if unread, become read.
9. **Overdue mid-month.** Given GAL Bank's 2026-11-30 statement is undone on 2026-12-15, when the command runs on 2026-12-16, then:
   - GACC gets one GAL row "... Last statement 31 Oct 2026 · overdue 1 month.";
   - no KDY user and no Super Admin gets it;
   - one reminder row and one audit row are added.
10. **Undone after the reminder.** Given AC 7, when BA undoes the KDY Bank 2026-11-30 statement on 2026-12-14, then:
    - the run on 2026-12-15 creates no new KDY Bank reminder;
    - S7 shows KDY Bank as "Overdue 1 month";
    - on 2027-01-11 a reminder with `overdue_through` 2026-12-31 and months 2 is created.
11. **Fallback.** Given GACC is deactivated before AC 9's run, then:
    - SA and SA2 each get the GAL Bank row, ending with " No one in GAL can reconcile or check this account.";
    - the reminder row has `super_admin_fallback` true;
    - SA3 gets nothing.

    Given a GAL Viewer with `account.reconcile_check` and `report.view` is active, then the Super Admins get nothing and only that Viewer is notified.
12. **Super Admin as a branch holder.** Given SA also holds the Branch Admin role in KDY, then after AC 1's run SA has exactly 4 rows: 3 KDY and 1 MAIN.
13. **Open flow (UI).**
    - Given ACC clicks the KDY Bank 5 row in the bell, then the row is marked read and `/reports/reconciliation-status?status=overdue` shows only Overdue rows of KDY.
    - Given SA in All branches mode clicks the MAIN row, then the switcher shows MAIN and S7 lists MAIN Main Account as overdue 4.
    - The row shows the `!` icon.
14. **Redaction.** Given VW is removed from KDY after AC 1, then:
    - `GET /notifications` returns VW's 3 KDY rows with title "Notification for KDY (no longer available)" and `body` null;
    - the stored rows are unchanged;
    - the run on 2027-01-11 does not notify VW.
15. **Audit.** Given AC 1, then 4 `reconciliation.overdue_reminder_sent` rows exist. Each has:
    - `user_id` null and `branch_id` = the account's branch;
    - `auditable` = the account;
    - `new_values` with `overdue_through`, `months_overdue`, `never_reconciled`, `last_statement_date`, `recipient_ids` and `by_system` true.

    The KDY Bank 5 row has `recipient_ids` = sorted [ACC, VW, BA] and `super_admin_fallback` false. BA (`audit.view`) sees the 3 KDY rows in the "Bank reconciliation" group, labelled "Overdue reconciliation reminder sent" with user "System", and not the MAIN row. Reading, read-all and the R7 auto-read write no audit row.
16. **No postings.** Given the runs of AC 1 to 11, then:
    - `ledger_entries`, every `accounts.current_balance`, `transactions`, `bank_reconciliation_marks`, `bank_statements` (apart from the statements the ACs complete or undo themselves), `bank_statement_checks` and `settings` are unchanged by the command;
    - `ledger:verify` exits 0.
17. **Same rule as S7.** On 2026-12-10, 2026-12-11 and 2026-12-31, right after a run, the accounts with a reminder for that day's E are exactly the Overdue rows of S7 in All mode for SA (`GET /v1/reports/reconciliation-status?status=overdue`).
18. **Failure isolation.** Given writing the notifications throws for KDY Bank 2 only (test double), when the command runs, then:
    - KDY Bank 5, KDY Bank and MAIN get their reminders;
    - KDY Bank 2 has no reminder row, no bell row and no audit row;
    - the exception is reported and the exit code is 1.

    On the next run, with the double removed, KDY Bank 2's reminder is created.
19. **Dry run.** Given `--dry-run` on the setup, then the output lists the 4 accounts with their recipients and "would send". No reminder, notification or audit row is written. Run after AC 1, each account shows "already sent".
20. **Scheduler and queue.**
    - `php artisan schedule:list` shows `reconciliation:remind-overdue` daily at 07:15 Asia/Colombo.
    - The schedule event has `withoutOverlapping` and `onOneServer`.
    - With `QUEUE_CONNECTION=database` and no worker running, AC 1's bell rows exist right after the command, and no row is added to `jobs`.
21. **Concurrency.** Given two runs of the command started at the same time (MySQL), then there are still exactly 4 reminder rows, one bell row per recipient per account, and 4 audit rows.
22. **Prune.** Given AC 1's bell rows are older than 90 days and `notifications:prune` deletes them, when the command runs again with the same E, then nothing is resent and the 4 reminder rows are still there.
23. **Build checks.** `php artisan test`, `./vendor/bin/pint --test` and `npx ng build` pass.

## 8. Open questions (assumption in bold; work proceeds on it)
- **Q1 (product owner)** Should Super Admins get every branch's overdue reminders? **No. Super Admins get MAIN accounts, plus a branch account only when nobody in that branch can receive it (R2).** With 20 branches and 5 accounts each, every branch row would mean up to 100 rows a month. A Super Admin reviews all branches on S7 in All mode before locking (AG-033d D4). A later option is one monthly summary row per Super Admin ("Bank reconciliations overdue: 7 accounts in 3 branches").
- **Q2 (product owner)** Is an email (for example a digest on the 11th) needed? **Not in this story. Bell only.** The recipients are Accountants, Branch Admins and Viewers, who sign in during month-end. An email needs a preference column, a queued mail with a re-check at send time (AG-094 R7 pattern) and its own ACs. It can reuse `bank_reconciliation_reminders` as its dedupe.
- **Q3 (product owner / accountant)** Should there be a second reminder in the same month, for example on the 20th, if the account is still overdue? **No. One reminder per account per month-end. The next month's reminder, with a higher count, is the re-reminder.** A mid-month nudge would be a second key (`account_id, overdue_through, kind`).
- **Q4 (product owner)** Should users be able to turn the reminder off? **No. It is a control, there is at most one row per account per month, and it is bell only.** Someone who should not get it should not hold the key: the Branch Admin removes the key from the role. AG-094 has no per-kind bell preference to extend.
- **Q5 (accountant)** After a statement is undone, should the account be reminded again in the same month? **No (R6). The dedupe key is the month-end, and S7 shows the account as Overdue.** The undo is already audited, and AG-033d tells the checker about it (`reconciliation.check_cancelled`). Adding the last statement id to the key would re-remind, at the cost of possible repeats when someone completes and undoes several times.
- **Q6 (product owner)** What time should the reminder arrive? **07:15 Asia/Colombo**, before the working day, and away from the 00:30 to 04:00 maintenance jobs.
- **Q7 (product owner)** Should a custom role with `account.reconcile` but without `report.view` get the reminder? **No. The link would give a 403.** No default template has this combination.
- **Q8 (accountant)** Should the reminder go out when an account is first Due (the 1st of the month), and not only when it is Overdue? **No. Only Overdue, after the 10 grace days, so the bell is not noisy every month for accounts that are done on time.**
- **Q9 (product owner)** A backlog row is needed. **The product owner adds AG-033f to the backlog sheet. This spec does not edit the xlsx.**
