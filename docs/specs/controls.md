# Spec: Accounting controls (void without delete, audit log screen, period lock screen)

## 1. Goal and scope
Close the three control gaps left open by the reports and users-and-roles sign-offs.
- **AG-063 (fix part)** "Void keeps the record, sets status Void and excludes it from balances." Today `LedgerService::void` calls `$txn->entries()->delete()`, which breaks "nothing is hard-deleted" (reports spec, accountant finding 12). Void must keep the original ledger entries and post contra entries.
- **AG-090** "Logs create/update/void/approve/login events with user, branch, IP, timestamp, old/new values. Audit log is read-only and filterable; exportable." This covers the screen, the API and the deferred items from `users-and-roles.md` (author tag on audit rows, `user.*`/`role.*` and `self` filters, model-level refusal of update/delete).
- **AG-091** "Lock by month, globally or per branch. Any create/edit/void dated in a locked period is rejected (server-side). Unlock requires Super Admin and is audited."

Out of scope: approvals (AG-051/AG-064), so there are no `approve` events yet. Also out of scope: the transaction detail screen (AG-065), `AccessAlerts` for `user.updated` (follow-up in users-and-roles), fiscal-year settings (AG-092), audit log retention/archiving, PDF export of the audit log, and the rule that months must be locked in order (Q4).

## 2. Personas and permissions
Real keys (`PermissionSeeder::PERMISSIONS`): `audit.view`, `income.void`, `expense.void`, `transfer.reverse`, `income.edit`, `expense.edit`.

| Persona | Audit log | Period lock | Void |
|---|---|---|---|
| Super Admin (`is_super_admin`) | all rows, all branches, incl. rows with no branch | lock / unlock | yes |
| Branch Admin template (`*`) | active branch rows only | read-only banner only (no screen) | yes |
| Accountant template (has `audit.view`) | active branch rows only | no | yes (`*.void`) |
| Viewer template (has `audit.view`) | active branch rows only | no | no |
| Cashier template | no menu, 403 | no | no |

**Period lock permission (decision).** Locking is enforced with the existing `super` middleware, like `capital`, `allocations` and `branches`. `period.lock` is **not** added to the catalogue. `User::canDo` already returns true for every Super Admin, so the key would add nothing for them. And `PermissionSeeder::run` gives new catalogue keys to the `*` template (Branch Admin), which would hand every Branch Admin a key that the backlog reserves for Super Admin. See Q1.

## 3. User flows
**F1 Void (Accountant, KDY).** Transactions -> row menu "Void" -> reason (required) -> confirm. The row shows status **Void**. The account balance returns to its value before the entry. The account statement no longer shows the entry, unless "Show voided" is ticked.
**F2 Audit review (Super Admin, All branches).** Switcher = All -> Audit log -> filter Event = "Voids and reversals", Self = yes, last month -> open a row -> see old/new values -> Excel.
**F3 Audit review (Accountant or Viewer, KDY).** Switcher = KDY -> Audit log -> only KDY rows are shown. There is no Branches picker. Rows with no branch (logins, global locks, multi-branch exports, template changes) are not shown.
**F4 Month-end (doc 7.5, reports finding 7).** Super Admin -> Period locks -> year 2026 -> October row -> "Lock all branches" (or the KDY cell -> "Lock") -> confirm. Reports for October no longer say PROVISIONAL once every branch in scope is locked. Then print and sign.
**F5 Unlock.** Super Admin -> Period locks -> a locked cell -> "Unlock" -> reason (required, 10-500 chars) -> confirm. The month becomes open again and reports show PROVISIONAL. The lock row is kept as history and an audit row `period.unlocked` is written.

## 4. Screens
**S1 Audit log** `/audit-log`. Menu "Audit log" if `auth.can('audit.view')`. Route guarded by `permissionGuard('audit.view')`. The screen reloads on branch switch.
- Filters:
  - From / To (date, required, default = first day of current month .. today, To >= From, at most 366 days; dates are Asia/Colombo days).
  - Event group (select): All, Transactions (`transaction.*`), Voids and reversals (`transaction.voided`, `transaction.reversed`), Users (`user.*`), Roles (`role.*`), Accounts (`account.*`), Branches (`branch.*`), Period locks (`period.*`), Reports (`report.exported`, `audit.exported`), Logins (`auth.login`).
  - User (select from `GET /audit-logs/actors`, with the inactive / "no longer in this branch" tag).
  - Self only (checkbox).
  - Ref no (text, exact transaction ref).
  - Branches (multi-select, Super Admin in All mode only; it includes an option "No branch (global)").
- Table, newest first, 50 per page: Date time (Asia/Colombo, `YYYY-MM-DD HH:mm:ss`) | Branch (code, or "—") | User (name + tag) | Event (label, e.g. "Expense voided") | Subject (ref no / user name / role name / account name / period) | Summary (first 2 changed fields, `old -> new`) | Self (badge "Self" when `new_values.self` is true) | IP.
- Row click opens a detail panel. It shows event key, user agent, auditable type and id, and a diff table Field | Old | New. Changed fields are highlighted. Arrays (e.g. `permissions`) are shown as "Added: …" / "Removed: …". When old values are null, the panel shows "Created" with only the New column.
- Actions: "Excel" only (no edit, no delete).
- Empty state: "No audit entries for these filters." Errors: the API `message` in `.alert`.

**S2 Period locks** `/period-locks`. Menu "Period locks" for Super Admin only. Route guarded by `superGuard`. It works in any switcher mode.
- Year selector (default = current year).
- Grid: 12 month rows. Columns: **All branches** (global lock) + one column per branch (code, active and inactive).
- Each cell shows "Locked" with "by <name> <date>", or "Locked (all branches)" when covered by the global lock, or "Open".
- Actions:
  - On an open cell: Lock.
  - On a locked cell: Unlock.
  - On a global cell: Lock all branches / Unlock all branches.
  - A cell covered by the global lock has no action.
  - Future months: actions disabled, tooltip "Future months cannot be locked."
- Lock confirm: "Lock October 2026 for KDY? No entry dated in this month can be created, edited, voided or reversed until it is unlocked." For the current month the confirm adds "This blocks today's entries."
- Unlock dialog: Reason (textarea, required, 10-500 chars).
- History link per cell: earlier lock/unlock rows (who, when, reason).
- Errors: the API `message` in `.alert`.

**S3 Statement change** (accounts statement screen): checkbox "Show voided" (default off). When on, voided lines show a "Void" tag. A contra line's description is "Void of <ref>: <reason>".

**S4 Transaction list/detail:** a status Void row with no ledger lines (legacy, see R6) shows the note "Voided before <migration date>; ledger lines were not kept."

## 5. API (all under `auth:sanctum` + `branch`)
| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `GET /v1/audit-logs` | `perm:audit.view` | `from`,`to` (Y-m-d, req.), `event_group`, `event`, `user_id`, `self` (0/1), `ref_no`, `branches[]` (Super Admin All mode only; `none` = null branch), `page` | `{data:[{id, created_at, branch:{id,code}\|null, user:{id,name,tag}, event, event_label, subject, old_values, new_values, self, ip, user_agent}], meta}`. 422: bad dates or > 366 days. 403 if `branches[]` is sent outside Super Admin All mode. |
| `GET /v1/audit-logs/actors` | `perm:audit.view` | — | users who authored rows in scope `{id,name,tag}` |
| `POST /v1/audit-logs/export` | `perm:audit.view` | same filters | xlsx `audit-log_<from>_<to>.xlsx`. 422 "Too many rows (N). Narrow the filters." when > 5,000 (`ReportService::EXPORT_CAP`). Writes `audit.exported` `{filters, rows}`. |
| `GET /v1/period-locks?year=` | `super` | `year` (int) | `{year, branches:[{id,code,name,status}], locks:[{id, branch_id\|null, period, locked_by:{id,name,tag}, locked_at, unlocked_at, unlocked_by, unlock_reason}]}` (active and historical) |
| `POST /v1/period-locks` | `super` | `period` (`YYYY-MM`, req., <= current month), `branch_id` (nullable = global, must exist) | 201 lock. 422: "October 2026 is already locked for KDY." / "… already locked for all branches." / "Future months cannot be locked." Audit `period.locked` (new: `period`, `branch_id`). |
| `POST /v1/period-locks/{id}/unlock` | `super` | `reason` (req., 10-500) | 200. 422: "This lock is already unlocked." / "October 2026 is also locked for all branches. Unlock all branches first." (branch lock under an active global lock). Audit `period.unlocked` (old: `locked_by`, `locked_at`; new: `reason`). |
| `GET /v1/accounts/{id}/statement` | unchanged | + `include_voided` (0/1, default 0) | lines gain `status` and `contra` (bool). Opening/closing unchanged by the flag. |

There are no PUT/PATCH/DELETE routes for `audit-logs` or `period-locks`.

**Schema (one migration)**
- `ledger_entries`:
  - add `contra_of_id` (nullable FK -> `ledger_entries.id`, unique);
  - change the `transaction_id` FK from cascadeOnDelete to restrict.
- `period_locks`:
  - add `unlocked_at` (timestamp null), `unlocked_by` (FK users null), `unlock_reason` (text null);
  - drop unique(`branch_id`,`period`) and add index(`period`,`branch_id`). An unlock no longer deletes the row; a re-lock adds a new row.
- `audit_logs`: add index(`branch_id`,`created_at`), index(`event`), index(`user_id`).

## 6. Business rules and edge cases
**R1 Void = contra on the original date.**
- `void` locks the transaction and its account. It checks `assertOpen(branch, txn_date)` and, for income, `assertFunds`.
- For each original entry it creates a contra entry with the same `transaction_id`, `account_id`, `branch_id` and `entry_date` (= `txn_date`), debit and credit swapped, and `contra_of_id` = original id. It goes through the `entry()` helper, so `current_balance` moves by integer cents; it is no longer recalculated with `ledgerBalance()`.
- Then status = `void`. The audit row `transaction.voided` adds `reason`, `self` and `contra_entry_ids`.
- Original entries are never updated or deleted. `LedgerEntry` and `AuditLog` throw on `updating`/`deleting` model events.
- Why the original date and not the void date: a void means "this entry should never have been made". A void is only possible while the original month is open. Dating the contra on the same day keeps every balance at every date equal to "as if never entered". That is the same meaning reports R2 already relies on, so the opening sums in `AccountController::statement` and `ReportService::cashBook`, `Account::ledgerBalance` and the dashboard balances need no change. Correcting a locked month is done by **reversal**, dated in an open month. See Q2.

**R2 Reports.**
- `countedTransactions`, branch comparison and `cashBookEntries` already filter `t.status IN (posted, reversed)`, so voided originals and their contra entries are excluded. The unfiltered opening sums (cash book, statement) net to 0.00 for a void.
- No report gets a "show voided" option. The basis line stays "Posted entries incl. reversals; voided entries excluded".
- Update the `ReportService` header comment ("voided entries have no ledger entries") to "voided entries net to zero and are excluded by status".

**R3 Statement.**
- By default, entries whose transaction status is `void` are left out of the lines.
- With `include_voided=1`, both lines show in date/id order. The running balance moves and returns on the same day, and opening and closing are identical with the flag on or off.
- The statement exports nothing new.

**R4 Voids keep working only for income/expense.** Transfers and capital are reversed, never voided (existing rule). Voiding twice -> 422 "Only posted entries can be voided."

**R5 Edit (AG-063 edit path, already lock-checked).** A `category_id` change must be an active category of the same type (`income`/`expense`), global or of the transaction's branch, else 422. Today any category id is accepted, which can move an income into an expense category in reports.

**R6 Legacy voids (data migration).**
- Transactions that already have status `void` have no ledger entries, so their postings cannot be rebuilt beyond the transaction row.
- **Do not fabricate entries.** The migration does not touch them, apart from writing one audit row `ledger.legacy_voids_identified` with `{count, transaction_ids}` and `branch_id` null.
- The transaction row, its `[Void]` note and its `transaction.voided` audit row stay as the record.
- A one-off command `php artisan ledger:verify` lists legacy voids and checks `current_balance == ledgerBalance()` for every account. It exits non-zero on any difference. Run it before and after deploy.

**R7 Period lock model.**
- A month is locked for branch B when an active row (`unlocked_at` null) exists with `branch_id` = B or null.
- `PeriodLock::isLocked` and `ReportService::provisional` read active rows only (scope `active()`).
- Only one active row per (`branch_id`, `period`); the store action checks this inside a DB transaction. A global lock alongside active branch locks is allowed; unlocking the global lock leaves them in place.
- Inactive branches can be locked.

**R8 Every money or entry-data path honours the lock (server side, `LedgerService::assertOpen` or `PeriodLock::isLocked`)**
| Path | Date(s) checked |
|---|---|
| income / expense create | `txn_date`, account branch |
| transfer, allocation, top-up, fund return, opening allocation | `txn_date`, **both** branches |
| capital | `txn_date`, Main branch |
| edit (`PUT /transactions/{id}`) | original `txn_date` |
| void | original `txn_date` (contra is on that date) |
| reverse | reversal date, every branch with an entry |

- **Gap to close:** `POST /transactions/{id}/reverse` accepts any `txn_date`. Add `after_or_equal:<original txn_date>` and `before_or_equal:today`.
- Reversing an entry whose original month is locked is allowed when the reversal date is open. The original's status changes to `reversed`, but the locked month's figures do not change (reports count `posted` and `reversed` alike).
- Not covered (no money effect): renaming categories or accounts changes labels on reprinted locked reports.

**R9 Audit log scoping.**
- Super Admin in All mode: every row; `branches[]` narrows the rows.
- Super Admin in branch mode, and every other user: `branch_id` = active branch only.
- `audit_logs` has no `BranchScoped` trait, so the controller applies this filter itself (CLAUDE.md: check access yourself).

**R10 Author tag** (users-and-roles finding 3):
- "inactive" when `users.status` != active;
- otherwise "no longer in this branch" when the author is not Super Admin and has no `branch_user` row for the audit row's `branch_id`;
- no tag on rows with a null branch.
- `audit_logs.user_id` is never changed.

**R11 Values shown.**
- `old_values`/`new_values` are shown as stored.
- Keys matching `/password|token|secret/i` are shown as `••••` on screen and in Excel.
- Excel has the reports header lines (company, title "Audit log", branches, period, "Printed by … (Asia/Colombo)"), columns as on S1 plus Old values and New values as JSON text, and formula-injection prefixing.

**R12 Dates.**
- Audit From/To are Asia/Colombo days, converted to a UTC `[from 00:00, to+1 00:00)` range on `created_at` with plain `where` and `Y-m-d H:i:s` strings. This works on both SQLite and MySQL. `whereDate` would cut on UTC days.
- Period locks use `period` strings.

## 7. Acceptance criteria
Setup: branches MAIN, KDY, GAL. KDY Cash balance 100,000.00 on 2026-11-01. Users:
- SA: Super Admin.
- ACC: Accountant in KDY.
- VW: Viewer in KDY.
- CSH: Cashier in KDY.
- BA: Branch Admin in GAL.

**AG-063 void**
1. Given ACC records expense E 5,000.00 on 2026-11-10 from KDY Cash (balance 95,000.00), when ACC voids E with reason "Duplicate", then E.status = `void`, E has 2 ledger entries (the original credit 5,000.00 with unchanged id and values, and a contra debit 5,000.00 dated 2026-11-10 with `contra_of_id` = original id), KDY Cash `current_balance` = 100,000.00 = `ledgerBalance()`, and the `transaction.voided` audit row has `reason`, `self: true` and `contra_entry_ids`.
2. Given any sequence of create/void/reverse, then the count of `ledger_entries` never decreases, and calling `update()` or `delete()` on a `LedgerEntry` or `AuditLog` model throws.
3. Given income I 3,000.00 into KDY Cash, when KDY Cash later holds 1,000.00 and ACC voids I, then 422 "Insufficient balance…" and no row changes.
4. Given E already void, when voided again, then 422 "Only posted entries can be voided." and no new entries.
5. Given a branch transfer T, when `POST /transactions/{T}/void`, then 422 "Only income and expense entries can be voided. Use reverse for transfers."
6. Given E voided (AC 1), when income-expense, branch-comparison, transfers and cash-book run for November and December, then every figure equals the same reports on a dataset where E was never entered, and the KDY Cash cash book has no 2026-11-10 line for E.
7. Given E voided, when `GET /accounts/{KDY Cash}/statement?from=2026-11-01&to=2026-11-30`, then no E lines appear and opening/closing = 100,000.00. With `include_voided=1`, two lines appear (out 5,000.00, then in 5,000.00 "Void of <ref>: Duplicate"), both with `status: void`, and closing is still 100,000.00.
8. Given legacy void transactions (status void, 0 entries) before the migration, when it runs, then no ledger entries are created for them, all balances are unchanged, one `ledger.legacy_voids_identified` audit row lists their ids, and `ledger:verify` exits 0.
9. Given ACC edits income I and sends an expense category, an inactive category or a GAL-only category, then 422 and the category is unchanged.

**AG-091 period lock**
10. Given SA, when `POST /period-locks {period:"2026-10", branch_id: KDY}`, then 201, the S2 grid shows KDY October "Locked by SA <date>", and the `period.locked` audit row has branch KDY.
11. Given KDY October is locked, when locked again, then 422 "October 2026 is already locked for KDY.". Given a global October lock, when KDY October is locked, then 422 "… already locked for all branches."
12. Given ACC, BA or a Branch Admin of KDY, when calling any `/period-locks` route, then 403 "Super Admin only.", and the menu item is hidden.
13. Given SA, when locking `2099-01` (any month after the current one), then 422 "Future months cannot be locked."
14. Given KDY October is locked, when SA unlocks it without a reason or with fewer than 10 characters, then 422. With the reason "Late supplier bill", then 200, the row keeps `locked_by`, gets `unlocked_at`/`unlocked_by`/`unlock_reason`, and `period.unlocked` is audited with that reason. Locking again creates a new row; the history shows both.
15. Given a global October lock and a KDY October lock, when SA unlocks the KDY lock, then 422 "…Unlock all branches first.". When SA unlocks the global lock, then KDY stays locked and GAL becomes open.
16. Given KDY October is locked (branch or global), then each of these dated 2026-10-xx is refused with 422 "The period 2026-10 is locked." and changes nothing: create income, create expense, transfer KDY->GAL, transfer GAL->KDY (receiving branch locked), top-up MAIN->KDY, edit of an October KDY entry, void of an October KDY entry, reversal dated in October of any KDY entry.
17. Given an October KDY expense and KDY October locked, when it is reversed with `txn_date` 2026-11-05, then 201, and the October income-expense report for KDY shows the same totals as before the reversal.
18. Given an expense dated 2026-11-10, when it is reversed with `txn_date` 2026-11-09 or a date after today, then 422 and nothing changes.
19. Given October locked for KDY only and a report in scope KDY+GAL, then "PROVISIONAL - period not locked" shows. After the GAL lock it does not. After the KDY unlock it shows again.

**AG-090 audit log**
20. Given CSH (no `audit.view`), when `GET /audit-logs`, then 403, and the menu is hidden.
21. Given rows in KDY, GAL and with a null branch, when ACC or VW (X-Branch-Id KDY) lists the log, then only KDY rows are returned. Sending `branches[]` returns 403.
22. Given SA in All mode, then rows of every branch and null-branch rows (`auth.login`, global `period.*`, multi-branch `report.exported`) are returned. `branches[]=GAL&branches[]=none` returns only GAL and null-branch rows. SA with X-Branch-Id KDY sees only KDY rows.
23. Given the filters event group "Voids and reversals" + Self only, then only `transaction.voided`/`transaction.reversed` rows with `new_values.self = true` are returned. "Users" returns only `user.*`, and "Roles" only `role.*`.
24. Given a row created at 2026-11-10 23:00 Asia/Colombo (17:30 UTC), when filtered From = To = 2026-11-10, then it is included. With 2026-11-11, it is excluded.
25. Given a `user.role_assigned` row (Cashier -> Accountant), when the detail panel opens, then it shows old and new role names and permissions with "Added:" listing the new keys. A `transaction.updated` row shows each changed field's old and new value.
26. Given author X was deactivated (or removed from KDY), then X's KDY rows show X's name with "inactive" (or "no longer in this branch") in the table, the actors list and the Excel file.
27. Given filters matching 120 rows, when Excel is exported, then the file has 120 data rows matching the screen order, the header lines from R11, and an `audit.exported` row is written. With > 5,000 matches, the response is 422 "Too many rows (N). Narrow the filters." and no file.
28. Given any audit row, then no API route can update or delete it (PUT/PATCH/DELETE on `/audit-logs/*` -> 404/405), and no value under a key matching `password|token|secret` is shown in clear.

## 8. Open questions (assumption in bold)
- Q1 Should `period.lock` exist as a catalogue key? **No: use `super` middleware.** Adding it would auto-grant it to Branch Admin (`*`). If the product wants it delegable later, add it and exclude it from the `*` propagation in the same change.
- Q2 (accountant) Reports finding 12 suggested a contra "on the void date". **Assume the original date** (R1). A void-date contra would make the entry count between its date and the void date (that is a reversal), and it would break today's unfiltered opening sums. Please confirm.
- Q3 Should legacy voids be rebuilt as an original + contra pair from the transaction row? **No (R6).** The pair nets to zero, adds nothing to balances and would create ledger rows that were never posted.
- Q4 Must months be locked in order, or unlocked only from the latest? **No order rule**, because it is not in the backlog.
- Q5 `auth.login` rows have no branch, so Branch Admins cannot see their staff's logins. **Accept for now (Super Admin only).**
- Q6 Is the Excel export of the audit log covered by `audit.view` alone (the Viewer can export)? **Yes.** The Viewer is the owner/external-accountant role.

## Accounting review
Reviewed against the code on `feature/controls` (`LedgerService`, `PeriodLock`, `TransactionController`, `TransferController`, `ReportService::provisional`, `AuditLog`, `PermissionSeeder`, routes). The direction is right: keeping originals and posting contras fixes reports finding 12, and the R8 lock table is close to complete. Findings:

**Answers to the open questions**
- **Q2: date the contra on the original date. Confirmed.** This supersedes the "on the void date" wording in reports finding 12. A void means "recorded in error, never happened". The books should then read as if the entry was never made, on every date. A void-date contra would show a false expense in month 1 and a false recovery in month 2, and the balances in between would be wrong. That is a reversal, not a void. Small-business practice, and what an auditor expects, is this: errors found while the month is open are corrected inside that month; once a month is closed (locked, figures signed), the correction is posted in the current open period and the closed month is left alone (a reversal plus a new entry, with an explanation). R1 + R8 already do this, provided the lock is cumulative (finding 1). Without that, an original-date contra can still change a later month that is already locked.
- **Q1: Super Admin only (`super` middleware), no catalogue key. Agreed.** Lock and unlock are the owner's closing control. Branch Admins can record, void and manage staff, so they must not also be able to reopen their own books. If delegation is ever needed, add `period.lock` and keep it out of the `*` propagation (as the BA notes), and still keep unlock Super Admin only.
- **Ordering of locks (Q4): a rule is needed (MUST, finding 1).** Monthly balances accumulate. Without a rule, an open October behind a locked November lets any October posting (create, edit, void) change November's locked closing balances.
- **Legacy voids (Q3/R6): no journal correction.** The old `void` deleted the entries and recalculated `current_balance` from the ledger. Balances are therefore internally consistent, and the result is the same "as if never entered" effect as an original-date contra. Voids always passed `assertOpen`, and the code has no unlock route, so no locked month was changed. The only manual work is documentary (finding 6). Do not rebuild the entries (agree with Q3).

**Findings**
1. **MUST - Locks are cumulative per branch ("books closed through").** Change `PeriodLock::isLocked(B, date)` to: an active lock exists with `branch_id` in (B, null) and `period >= month(date)`. `ReportService::provisional` uses the same rule. Effects:
   - Locking November closes every earlier month for that branch, including months with no lock row and dates before the branch's first entry (`capital`/`allocate` accept any past date).
   - S2 shows such cells as "Closed (through Nov 2026)", with no action.
   - Unlocking month M is refused while a later active lock covers the same scope: "Unlock November 2026 for KDY first." For a branch lock, a later global lock also counts.
   - Unlocking a global lock is allowed while later branch locks exist. Those branches stay closed through their own later lock.
   - This replaces the "no order rule" assumption in Q4 and §1 out-of-scope.
2. **MUST - Immutability at database level, not only model events.** `LedgerEntry`/`AuditLog` `updating`/`deleting` events do not fire on query-builder writes. Today's `$txn->entries()->delete()` is exactly such a write, so AC 2 as written would not have caught the current bug.
   - In the same migration, add `BEFORE UPDATE` and `BEFORE DELETE` triggers on `audit_logs` and `ledger_entries` that abort the statement. MySQL: `SIGNAL SQLSTATE '45000'`; SQLite: `RAISE(ABORT, ...)`. Both engines support this, so the tests cover it.
   - With the `transaction_id` FK set to restrict, `transactions` rows that have entries cannot be deleted either.
3. **MUST - Audit export and actors use exactly the list's scope (R9).** "Same filters" is not enough: the export must re-apply the branch scope on the server. An Accountant or Viewer exporting in KDY must never receive GAL or null-branch rows, whatever parameters are sent.
4. **MUST - Reversal date rules (R8 gap) apply to every reverse path.** `TransferController::reverse` today accepts any `txn_date`, including dates before the original and future dates. Add `after_or_equal:<original txn_date>|before_or_equal:today` as R8 says, and check `assertOpen` for every branch that has an entry (this is already done per entry, keep it).
5. **MUST - The void audit row is complete without the ledger.** `transaction.voided` must also record `amount`, `account_id`, `txn_date` and `ref_no` in `old_values`/`new_values`, so the Voids filter and the Excel file show what was voided without joining to `ledger_entries`.
6. **SHOULD - Legacy voids: documentary check only.**
   - `ledger:verify` lists each legacy void with ref, date, amount, account, voided-by and voided-at (from its `transaction.voided` row). It flags any legacy void that has no `transaction.voided` audit row.
   - Before go-live, the Super Admin reviews that list. Where a printed report for that month was signed before the void date, it is re-printed and the old copy marked superseded.
   - No ledger correction.
7. **SHOULD - Detective control on self-voids (segregation of duties).**
   - Accountant (record + void + audit.view) and Branch Admin (`*`) can each record and void their own entries. That is acceptable in a small business only if someone else reviews it. Audit visibility is branch-wide, not "own rows only", which is correct.
   - The reviewers are the Viewer (owner / external accountant) and the Super Admin.
   - Add to F4 (month-end) a step before locking: Super Admin runs Audit log -> "Voids and reversals" + Self only for the month and branch.
   - S2's Lock confirm shows the count of self-voids/self-reversals in that month for the branch(es), e.g. "3 self-voids this month - review the audit log first." The count is information only and does not block.
8. **SHOULD - Reversing Super-Admin-only postings.** `POST /transactions/{id}/reverse` needs only `transfer.reverse`, so an Accountant in MAIN can reverse a `capital`, `opening_allocation` or `fund_allocation` that only a Super Admin may create. Reversal of these types should be Super Admin only. Reversing income/expense should also need `{type}.void`, the same right as voiding it. This can be a small separate story if the developer prefers, but it should be fixed before go-live.
9. **SHOULD - Fix `(float)` in `LedgerService::reverse` in this feature.** Use `Money::cmp($e->debit, 0) > 0`, and narrow `entry()` to `string $debit = '0', string $credit = '0'`.
   - The `(float)` is not a wrong result today: a two-decimal string compared with 0 is exact.
   - It does break the "no floats" rule, and this feature rewrites the neighbouring `void` code in the same file, so it costs one line.
   - It is the only `(float)` in `app/`.
10. **SHOULD - Void is for errors only.** The void dialog says: "Use Void only for an entry recorded in error. For a real refund or return, record a new entry on the day it happens." Real events must not be backdated through voids.
11. **SHOULD - Export capacity and evidence.** The Excel header shows the filters used and "Rows: N" (so a printout is complete on its face). If an auditor's full-year request goes over the 5,000 cap, they narrow it by month; that is acceptable.
12. **SHOULD - Masking is defensive and fine.** No current `AuditLog::record` call stores secrets (`user.password_set` stores none). Apply the mask recursively to nested keys and to the S1 Summary column, and keep it on the server (the API returns `••••`, not the value).
13. **SHOULD - One active lock per (branch, period) under concurrency.** A check inside a transaction does not stop two simultaneous inserts. Add a unique index on a generated column, for example `active_key = IF(unlocked_at IS NULL, CONCAT(COALESCE(branch_id,0),':',period), NULL)`, or take a lock on the branch row(s) before checking.
14. **SHOULD - `capital` and `allocate` dates:** add `before_or_equal:today` like the other create paths, so a future-dated posting cannot move today's balances.
15. Fine as specified: double entry of contras (net zero per transaction), the reports basis (R2), statement opening/closing invariance (R3), the R5 category check, the R10 author tags, the R12 timezone handling, the audit scoping for non-Super Admins (R9), Viewer export (Q6), and the null-branch login rows being Super Admin only (Q5).

**Acceptance criteria (added)**
29. Given KDY November locked and October open, when ACC creates, edits or voids a KDY entry dated 2026-10-xx, then 422 "The period 2026-10 is locked." and nothing changes. S2 shows KDY October "Closed (through Nov 2026)" with no action. A report for KDY October has no PROVISIONAL line.
30. Given KDY October and KDY November locked, when SA unlocks October, then 422 "Unlock November 2026 for KDY first.". Given a global November lock, unlocking the KDY October lock is also refused.
31. Given a capital or allocation dated before MAIN's first locked month (or any earlier date), when posted while a later MAIN lock is active, then 422 and nothing changes. Given a date after today, then 422.
32. Given rows in `ledger_entries` and `audit_logs`, when a raw `DB::table(...)->update(...)` or `->delete()` runs on either table (SQLite in tests), then it throws and the row count and values are unchanged.
33. Given ACC with X-Branch-Id KDY, when `POST /audit-logs/export` is sent with any parameters (including `branches[]`), then the file contains only KDY rows (or the call is refused with 403 for `branches[]`), and the count equals the list's total for the same filters.
34. Given E voided (AC 1), then its `transaction.voided` row holds `ref_no`, `amount` 5000.00, `account_id`, `txn_date` 2026-11-10, `reason`, `self` and `contra_entry_ids`, and the Excel row shows them.
35. Given legacy voids, when `ledger:verify` runs, then each one is listed with ref, date, amount, account, voided-by and voided-at, and any legacy void without a `transaction.voided` row is flagged. The exit code is 0 when balances match.
36. Given ACC in MAIN with `transfer.reverse`, when ACC reverses a `capital`, `opening_allocation` or `fund_allocation`, then 403. When a user with `transfer.reverse` but without `expense.void` reverses an expense, then 403.
37. Given KDY October has 2 voids by their own authors, when SA opens the Lock confirm for KDY October, then it shows "2 self-voids this month - review the audit log first." and the lock still works.
38. Given two concurrent `POST /period-locks {period:"2026-10", branch_id:null}`, then exactly one active row exists afterwards.
39. Given an audit row whose `new_values` contains a nested key `token` or `password`, then the list, the detail and the Excel file show `••••`, and the API response never contains the value.
