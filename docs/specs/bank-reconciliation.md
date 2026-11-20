# Spec: Bank reconciliation (mark bank entries as reconciled)

## 1. Goal and scope
Backlog **AG-033** (E4 Accounts (Cash & Bank), Could, R3, 5 points): "As an Accountant, I want to mark bank entries as reconciled so that I know what matches the bank statement." AC: reconcile checkbox per entry with reconciled date; report of unreconciled entries. Project document: the Accountant "reconciles accounts"; the catalogue names `account.reconcile`; month-end step 7.5.1 "Each branch runs the Daily Cash Book / account statements and reconciles bank accounts."

**What is reconciled: a ledger line, not a transaction.** One `ledger_entries` row on an account of `type = bank`. A transaction can have several lines (a KDY Bank -> GAL Bank branch transfer has a credit on KDY Bank and a debit on GAL Bank, plus inter-branch lines). Each bank line is matched against its own bank's statement by its own branch. Only `origin = posting` lines and void contras ever sit on a money account (reclass, back-fill and year-end lines never do, double-entry R1/R6, settings R8), so in practice the lines are income, expense, capital, allocation, top-up, fund return, transfer and reversal lines.

**It posts nothing.** Marking, un-marking, completing or undoing a reconciliation never writes `ledger_entries`, never changes `accounts.current_balance`, `transactions.*`, or any figure in the existing reports, dashboard, trial balance or statements. `ledger_entries` stays immutable (controls R1 triggers), so the reconciled state lives in two new tables (section 5).

**Two words used below.**
- **Cleared date**: the date the item appears on the bank statement (the backlog's "reconciled date"). Entered by the user. Different from the line's `entry_date` (book date) and from `marked_at` (system time of the tick).
- **Statement**: a completed reconciliation of one bank account up to a **statement date** S with the **statement balance** typed from the bank statement. Completing one freezes the marks it used.

**Out of scope:** importing bank statement files (CSV/OFX) and auto-matching; posting bank charges or interest from the reconcile screen (they are recorded with the normal expense/income form; the screen only links to it); reconciling cash and wallet accounts (Q1); notifications (AG-094); cash counts; multi-currency.

## 2. Personas and permissions
**New key `account.reconcile`** in `PermissionSeeder::PERMISSIONS`, right after `account.manage` (name taken from the project document catalogue). Meaning: may tick and untick bank lines, complete a statement and undo the latest statement, for bank accounts of the branch the role belongs to.

| Template | Has it | How |
|---|---|---|
| Branch Admin (`*`) | yes | added to `*` templates by `PermissionSeeder::run`; the migration does the same on existing databases (as `transfer.acknowledge`, transfer-receipt section 2) |
| Accountant | yes | added to `TEMPLATES['Accountant']` (new installs). Existing databases: the Super Admin adds it to each branch's Accountant role on the Roles screen (users-and-roles finding 7: new keys never reach other templates or branch copies). Release note says so (Q6) |
| Cashier, Viewer | no | |
| Super Admin | yes (all keys) | any branch, All mode included |

Other keys used:

| Need | Key |
|---|---|
| Reconcile screen, marks, complete, undo | `account.reconcile` (the screen also needs `account.view`, held by every template that has `account.reconcile`) |
| "Cleared" column on the account statement | `account.view` (unchanged guard) |
| Unreconciled entries report, statement reprint | `report.view`; export `report.view` + `report.export` |
| Undo a statement whose statement date is in a locked month or a closed fiscal year | Super Admin only (R7) |

Nobody may grant `account.reconcile` without holding it (existing role rules). No segregation block: the user who recorded an entry may reconcile it; the action is flagged `self` (R10, Q4).

## 3. User flows
- **F1 Month-end reconciliation (KDY Accountant, branch KDY).**
  1. Accounts > KDY Bank > "Reconcile" (or menu Accounts > Bank reconciliation, then pick KDY Bank).
  2. Enter Statement date 2026-10-31 and Statement balance 479,500.00 from the bank statement.
  3. The table lists every unreconciled KDY Bank line dated up to 31 Oct. Tick each line found on the bank statement; its Cleared on date defaults to the statement date and can be changed to the date on the bank statement.
  4. The bank shows a 500.00 charge that is not in the books. Click "Record a bank entry" (opens `/transactions/new/expense` with KDY Bank picked, in a new tab), record it dated 2026-10-31, back on the screen click "Refresh", tick it.
  5. The summary shows Difference 0.00. "Save" keeps the ticks (draft); "Complete reconciliation" saves and completes. Toast "KDY Bank reconciled to 31 Oct 2026."
  6. "Print" on the history row gives the bank reconciliation statement PDF (S3) to sign.
- **F2 Draft across days.** The Accountant ticks half the lines, clicks Save, closes the browser. Next day the ticks are still there (any user with the key sees them). Nothing is final until Complete.
- **F3 Untick (mistake).** A tick that is not part of a completed statement is removed by unticking and saving. A tick inside a completed statement cannot be removed (checkbox disabled, tooltip "Reconciled in the statement of 31 Oct 2026. Undo that statement first.").
- **F4 Undo the latest statement (Branch Admin, KDY).** Reconcile screen > History > latest row > "Undo" > reason (10-500) > confirm. The statement becomes "Undone", its ticks stay but become editable again. Only the latest completed statement of the account can be undone. If its statement date is in a locked month (or closed year), only a Super Admin can undo it (R7).
- **F5 Super Admin, All branches.** The account picker lists bank accounts of every branch, grouped by branch code. Everything else as F1.
- **F6 Review (Viewer, KDY).** Reports > Unreconciled bank entries, As at 2026-10-31: per bank account the uncleared receipts and payments with age, and the expected bank balance. Export to PDF/Excel needs `report.export` (the Viewer template has none, so screen only).
- **F7 Void blocked.** An Accountant tries to void expense E1 whose KDY Bank line is ticked: 422 (R5). If E1 really went through the bank, the correction is a reversal (a new real movement); if the tick was a mistake, untick first.
- **F8 Month-end lock (Super Admin).** Period locks > Lock October for KDY: the review shows "KDY Bank: last reconciled to 30 Sep 2026" (information, never blocking, Q8).

## 4. Screens
**S1 Bank reconciliation** `/accounts/:id/reconcile`; guard `permissionGuard('account.reconcile')`; menu item "Bank reconciliation" under Accounts when `auth.can('account.reconcile')`, which opens `/accounts/reconcile` (picker only). Reloads on branch switch (back to the picker when the account is not in the new branch).
- **Account picker** (select): `type = bank` accounts in scope, active and closed (closed tagged); All mode grouped by branch code. Accounts list (`account-list.component.ts`) gains a row action "Reconcile" for bank accounts when the user has the key.
- **Header fields**

| Field | Type | Required | Validation |
|---|---|---|---|
| Statement date | date | for Complete | `YYYY-MM-DD`, <= today, > last completed statement date of the account. Default: last day of the previous month, or today if that is not after the last statement |
| Statement balance | money, signed (overdraft negative) | for Complete | `^-?\d{1,13}(\.\d{1,2})?$` |

- **Summary strip** (recomputed in cents on every tick, client side, and server side on Save/Complete): Last statement (date, balance; "None" -> 0.00) · Receipts ticked (+) · Payments ticked (-) · **Reconciled balance** = last statement balance + ticked receipts - ticked payments (only ticks with Cleared on <= Statement date) · Statement balance · **Difference** = Statement balance - Reconciled balance (green "0.00", else red with the amount) · Book balance at statement date (ledger, `entry_date <= S`, voids net to zero).
- **Table** (lines of this account: transaction status not `void`, not frozen in a completed statement, `entry_date <= Statement date`; checkbox "Show later entries" adds lines dated after S, needed for items the bank cleared before they were booked): ☐ · Date · Ref (link `/transactions/:id`, new tab) · Type · Description / payee · Reference (cheque/slip no, `transactions.reference`) · In · Out · Cleared on (date input, enabled when ticked; default Statement date, or today when empty; must be > last statement date and <= Statement date to count) · Marked by (name, tag "You recorded this" when `self`).
  Row tags: "Reversed" (status `reversed`) and "Reversal of <ref>" so a matched pair is visible. Sorting: Date, then id.
- **Late entries banner** (warning, when `late_entries_count > 0`): "N entries dated on or before 30 Sep 2026 were added after that statement was completed. They are listed and can be ticked with a later cleared date." (R8, Q5).
- **Actions:** "Tick all shown" / "Untick all" (client only; sets Cleared on = Statement date), "Refresh", "Record a bank entry" (links to the income/expense form with the account picked, if the user has `income.create` or `expense.create`), **Save** (sends the changed ticks), **Complete reconciliation** (enabled when Statement date and balance are valid and Difference = 0.00; saves then completes; confirm "Complete the reconciliation of KDY Bank to 31 Oct 2026? Lines cleared up to that date will be frozen."). Leaving with unsaved ticks asks "Discard unsaved ticks?".
- **History** (below, collapsible): Statement date · Statement balance · Lines · Completed by / at · Status (Completed / Undone, with by/at/reason) · "Changed since completion" tag (R8) · Print (PDF of S3 with `report.export`) · Undo (latest completed only, R7). Undo dialog: Reason (required, 10-500).
- **Empty states:** no bank accounts: "No bank accounts in this branch." No lines: "Nothing to reconcile up to <date>."
- **Errors:** API `message` in `.alert`; on 422 from Save/Complete the table reloads and keeps the user's unsaved ticks where still valid.

**S2 Account statement** `/accounts/:id/statement` (existing). For a bank account only, new column **Cleared** after Out: the active mark's cleared date, or "—". Read-only. No change for other accounts.

**S3 Report "Unreconciled bank entries"** `/reports/bank-reconciliation`; card in the Reports hub ("Which bank entries have not cleared yet?"); guard `permissionGuard('report.view')`.
- Filters: As at (date, required, default today, <= today), Branches (reports R1), Accounts (multi-select of bank accounts in the branch scope, default all), Older than (days, optional integer 0-3650).
- Per account section: header "KDY · KDY Bank (11002) — last reconciled to 31 Oct 2026 (statement balance 479,500.00)" or "never reconciled".
  Table: Date · Ref · Type · Description / payee · Reference · In · Out · Age (days from Date to As at).
  Totals block: Book balance at As at · less uncleared receipts · add uncleared payments · **= Expected bank balance**.
- With a statement (opened from S1 Print or History, `statement_id`): one account, As at = S, plus lines "Balance per bank statement", "Difference" (0.00 when unchanged) and the tag "Changed since completion" when the recomputed figures differ from those stored (R8). PDF ends with Prepared by / Checked by / Approved by.
- Header and footer as other reports (reports finding 4). No PROVISIONAL marker: this is a status as at a date, not period figures. Basis line: "Bank lines dated up to the As at date that have no cleared date on or before it; voided entries excluded."
- Empty: "All bank entries up to <date> are reconciled."

**S4 Period locks review** (existing, Super Admin): information line per bank account in the lock's scope whose last completed statement date is before the end of the month being locked: "<code> <account>: last reconciled to <date | never>". Never blocking.

**S5 Audit log** (existing): new event group "Bank reconciliation" (`reconciliation.*`).

**390px layout.** S1: header fields stack full width; the summary strip becomes a 2-column grid of tiles with Difference full width at the top, sticky under the app bar while scrolling. Table rows become cards: line 1 checkbox (44x44 tap target) + Date + amount right-aligned (green In / plain Out with "-"); line 2 Ref · Type; line 3 Description / payee (wraps) · Reference; line 4 Cleared on date input full width (shown when ticked). Save and Complete sit in a sticky bottom bar, side by side, each at least 44px tall. History rows become cards with Print / Undo as full-width buttons. S3 sections become stacked cards per line (Date + amount, Ref · Type, Description, Age) with the totals block at the end of each account. Dialogs full width with a 16px side gutter. No horizontal page scroll.

## 5. API
All under `auth:sanctum` + `branch`. `{account}` is route-bound through `BranchScoped` (another branch's account -> 404 in branch mode; any account for a Super Admin in All mode). A non-bank account -> 422 "Only bank accounts can be reconciled."

| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `GET /v1/accounts/{account}/reconciliation` | `perm:account.reconcile` | `statement_date` (optional, Y-m-d), `show_later` (0/1) | `{account:{id,code,name,branch:{id,code},status}, last_statement:{id, statement_date, statement_balance}\|null, book_balance_at (at `statement_date`, null without it), lines:[{entry_id, transaction_id, date, ref_no, type, status, reversal_of_ref\|null, description, reference, in, out, mark:{cleared_date, marked_by:{id,name,tag}, marked_at}\|null, self}], late_entries_count, statements:[{id, statement_date, statement_balance, lines_count, status, completed_by, completed_at, undone_by, undone_at, undo_reason, changed_since_completion, can_undo}]}` |
| `POST /v1/accounts/{account}/reconciliation/marks` | `perm:account.reconcile` | `mark: [{entry_id, cleared_date}]` (max 500), `unmark: [entry_id]` (max 500); at least one item | 200 `{marked, unmarked}`. All or nothing. Errors R4 |
| `POST /v1/accounts/{account}/reconciliation/complete` | `perm:account.reconcile` | `statement_date` (Y-m-d, req.), `statement_balance` (signed money string, req.) | 201 statement row. Errors R6 |
| `POST /v1/accounts/{account}/reconciliation/statements/{statement}/undo` | `perm:account.reconcile` | `reason` (req., 10-500, trimmed) | 200 row. Errors R7. `{statement}` must belong to `{account}` (404) |
| `GET /v1/reports/bank-reconciliation` | `perm:report.view` | `as_at` (Y-m-d, req., <= today), `branches[]` (reports R1), `account_ids[]`, `older_than` (int 0-3650), `statement_id` (optional; then `as_at` is ignored and set to its S) | `meta + {accounts:[{account, last_statement, book_balance, uncleared_receipts, uncleared_payments, expected_bank_balance, rows:[{entry_id, transaction_id, date, ref_no, type, description, reference, in, out, age_days}]}], statement:{statement_date, statement_balance, stored:{…}, difference, changed_since_completion}\|null}`. 404 for a `statement_id` not in scope |
| `POST /v1/reports/bank-reconciliation/export` | `perm:report.view,report.export` | the same + `format` (pdf\|xlsx) | file `bank-reconciliation_<as_at>.<ext>`; `ReportService::NAMES` gains `bank-reconciliation`; caps `EXPORT_CAP` / `PDF_EXPORT_CAP`; audit `report.exported` |
| `GET /v1/accounts/{id}/statement` (existing) | unchanged | unchanged | for a bank account each line gains `cleared_date` (string\|null) |
| `POST /v1/transactions/{id}/void` (existing) | unchanged | unchanged | new 422 (R5) |
| `GET /v1/period-locks/review` (existing) | `super` | unchanged | + `bank_reconciliation:[{branch:{id,code}, account:{id,code,name}, last_statement_date\|null}]` (S4) |

Money in requests and responses is a decimal string; stored and computed as integer cents (`App\Support\Money`), never floats.

**Schema** (one migration `2026_12_01_000000_bank_reconciliation.php`):
- `bank_statements`: `id`, `branch_id` FK, `account_id` FK, `statement_date` date, `statement_balance_cents` bigint (signed), stored figures at completion `reconciled_cents`, `book_balance_cents`, `uncleared_receipts_cents`, `uncleared_payments_cents` (bigint), `lines_count` int, `status` (`completed`\|`undone`), `completed_by` FK users, `completed_at`, `undone_by` FK null, `undone_at` null, `undo_reason` text null, timestamps. One active statement per (account, date) via `active_key` (generated column on MySQL, partial unique index on SQLite, as `period_locks`). Index (`account_id`, `status`, `statement_date`).
- `bank_reconciliation_marks`: `id`, `branch_id` FK, `account_id` FK, `ledger_entry_id` FK restrict, `cleared_date` date, `statement_id` FK null (set when a statement completes; a mark is **frozen** when this points to a `completed` statement), `marked_by`, `marked_at`, `unmarked_by` null, `unmarked_at` null, timestamps. One active mark per line: `active_key` = `ledger_entry_id` while `unmarked_at` is null (unique). Index (`account_id`, `unmarked_at`, `cleared_date`).
- Rows are never deleted; an untick sets `unmarked_at/by`; a later tick of the same line adds a new row. Both models use `BranchScoped`.
- Permission `account.reconcile` inserted where missing and given to the `*` templates. Rollback refuses while any `bank_statements` or `bank_reconciliation_marks` row exists; otherwise removes the key and its grants.

## 6. Business rules
- **R1 Eligible lines.** A line can be ticked when: its account is the route account and `type = bank`; its transaction status is `posted` or `reversed` (a reversal transaction is `posted`); it has no active mark. Lines of `void` transactions (the original and its contra) are never listed and never markable; they net to zero on the same date (controls R1). Pending/rejected requests have no lines (AG-051/AG-064) and never appear.
- **R2 Reconciled balance.** For account A and date D: `reconciled(A, D)` = SUM(debit - credit) over lines with an active mark and `cleared_date <= D`. The last completed statement's balance equals `reconciled(A, S)` by R6. First reconciliation starts from 0.00, so the line that put the opening money in (capital, allocation, top-up) is ticked like any other.
- **R3 Unreconciled as at D** (S3): lines of A with `entry_date <= D` (`whereDate`), transaction not `void`, and no active mark with `cleared_date <= D`. Uncleared receipts = their debits; uncleared payments = their credits. Expected bank balance = book balance at D - uncleared receipts + uncleared payments. For D = a statement date with no late entries this equals `reconciled(A, D)` = the statement balance.
- **R4 Marks endpoint** (one DB transaction; lock the account row `lockForUpdate` first, it is read only, `current_balance` is never written). Checked per item, in order; the first failure returns 422 naming the ref and nothing is saved:
  1. `entry_id` is a line of this account, else 422 "Line <id> is not on this account.";
  2. its transaction is `void`: "Voided entries cannot be reconciled (<ref>).";
  3. `cleared_date` strict `YYYY-MM-DD` (as `BusinessDate::normalize`), <= today: "The cleared date cannot be in the future.";
  4. `cleared_date` > the last completed statement date: "The cleared date must be after 31 Oct 2026, the last completed statement.";
  5. mark: already actively marked -> "<ref> is already reconciled." (also the unique `active_key` under a race); unmark: no active mark -> "<ref> is not reconciled."; frozen -> "<ref> is in the completed statement of 31 Oct 2026. Undo that statement first.";
  6. the same `entry_id` in both lists -> 422 "A line cannot be ticked and unticked at once.".
  The cleared date has no lower bound against `entry_date` (bank charges are often booked after the bank date) (Q7).
  **Changing a cleared date:** an item in `mark` for a line that has a non-frozen active mark with a **different** date closes the old mark and adds a new one (audited under `changed`); the same date gives the "already reconciled" error of step 5.
- **R5 Void of a reconciled line is refused.** In `LedgerService::void`, after the account locks, a locking read of active marks on the transaction's lines: any found -> `LedgerException` "<ref> is reconciled on <account> (cleared <date>). Untick it first; if the money went through the bank, reverse the entry instead." Reverse is allowed: the reversal is a new movement with its own unticked lines, and the original keeps its tick (matched pair). Edits (`PUT /transactions/{id}`) change no line and are unaffected. Q9.
- **R6 Complete** (one DB transaction, account row locked): S strict date, <= today, > last completed statement date, else 422 "The statement date must be after 31 Oct 2026." / "…cannot be in the future."; balance format else 422 "Enter the statement balance (for example 125000.00 or -2500.00)."; compute `reconciled(A, S)` in cents; Difference = statement balance - reconciled; not 0 -> 422 "Difference is 1,250.00. The statement balance must equal the reconciled balance (478,250.00)." (Q2). Then insert the statement with the stored figures (R3 at S), set `statement_id` on every active mark with `cleared_date` in (last S, S], audit. Active marks with a cleared date after S stay as drafts. Completing does not depend on period locks or the fiscal year (Q3).
- **R7 Undo** only the latest `completed` statement of the account, else 422 "Only the latest statement (30 Nov 2026) can be undone."; already undone -> 422 "This statement is already undone."; reason 10-500. When S is in a locked month for the account's branch (`PeriodLock::isLocked`) or in a closed fiscal year (`YearEnd::closedYearOf`), a non-Super-Admin gets 403 "The statement date is in a locked period. Only a Super Admin can undo it." Effect: status `undone`, undone fields set; its marks keep their row and `statement_id` but are no longer frozen (they point to an undone statement); the next Complete re-points them.
- **R8 Changed since completion.** A statement is "changed" when R3 recomputed at S (book balance, uncleared receipts, uncleared payments) differs from the stored figures. Cause: a bank line dated <= S posted after completion (back-dating is allowed while the month is open) or voided after completion (only possible if unticked, i.e. it was uncleared). Such lines are "late entries" (`entry_date <= last S` and `created_at > completed_at`, or voided later). Shown on S1/S3; never blocks (Q5).
- **R9 Branch isolation.** Lines, marks and statements are read for the route account only; `LedgerEntry` has no branch scope, so every query filters `account_id` (the bound, scoped account). The report follows reports R1 and filters `accounts.branch_id` explicitly with `withoutGlobalScopes()` only after resolving the branch set. A branch user never sees another branch's account, ticks or statements, including the other side of a branch transfer.
- **R10 Audit** (`AuditLog::record`, auditable = the account, branch = account's branch):
  - `reconciliation.saved` new `{account_id, marked:[{entry_id, ref_no, entry_date, amount (signed decimal), cleared_date}], unmarked:[{entry_id, ref_no, cleared_date}], changed:[{entry_id, ref_no, old_cleared_date, cleared_date}], self_count}` (one row per Save; `self_count` = items whose transaction `created_by` is the user);
  - `reconciliation.completed` new `{statement_id, statement_date, statement_balance, reconciled, book_balance, uncleared_receipts, uncleared_payments, lines_count, self_count}`;
  - `reconciliation.undone` old `{status:'completed', completed_by, completed_at}` new `{status:'undone', statement_id, statement_date, reason}`.
  Audit log label: "Bank lines reconciled", "Bank statement completed", "Bank statement undone".
- **R11 `ledger:verify` V10:** every active mark is on a `bank` account line of its own `account_id`/`branch_id`, its transaction is not `void`, at most one active mark per line, and every `completed` statement's `reconciled_cents` equals `reconciled(A, S)` now. Any failure exits 1 with the offenders.
- **R12 Edge cases.**
  - Closed bank account: can be reconciled (ticks, complete, undo); its balance is 0.00, so the last statement shows 0.00.
  - Inactive branch: allowed (it posts nothing).
  - Deactivated user or user removed from the branch: their ticks and statements stay; names get the controls R10 tag.
  - Two users saving the same line: one wins, the other gets "<ref> is already reconciled." (unique `active_key`).
  - Reversal pair that never reached the bank: both lines are ticked with the same cleared date; they add 0.00.
  - Branch transfer KDY Bank -> GAL Bank: KDY ticks its credit line, GAL its debit line, independently.
  - Year-end close and reopen post no money lines; nothing to reconcile and nothing blocked.
  - Period locks never block ticking (the tick changes no figure). They only protect undo (R7).

## 7. Worked example (QA and accountant check)
KDY Bank (11002), no earlier statement. Lines (book):

| Date | Ref | Line | In | Out |
|---|---|---|---|---|
| 2026-10-01 | TOP (top-up MAIN -> KDY Bank) | Dr | 500,000.00 | |
| 2026-10-05 | E1 expense, cheque 001234 | Cr | | 60,000.00 |
| 2026-10-20 | I1 income deposit | Dr | 40,000.00 | |
| 2026-10-30 | E2 expense, cheque 001235 | Cr | | 25,000.00 |
| 2026-10-31 | I2 income deposit | Dr | 15,000.00 | |

Bank statement 31 Oct shows TOP, E1, I1 and a 500.00 bank charge; closing 479,500.00.
1. Book balance 31 Oct = 470,000.00. Tick TOP, E1, I1 (cleared 2026-10-31): reconciled = 480,000.00; Difference = -500.00; Complete refused.
2. Record expense E3 "Bank charges" 500.00 dated 2026-10-31 from KDY Bank, tick it: reconciled 479,500.00, Difference 0.00. Complete: statement S1 (2026-10-31, 479,500.00), stored book 469,500.00, uncleared receipts 15,000.00 (I2), uncleared payments 25,000.00 (E2), lines 4.
3. S3 as at 2026-10-31: Book 469,500.00 - 15,000.00 + 25,000.00 = **479,500.00** = statement balance.
4. November bank statement (30 Nov): I2 cleared 2026-11-02, E2 cleared 2026-11-03, closing 469,500.00. Tick both: reconciled = 479,500.00 + 15,000.00 - 25,000.00 = 469,500.00; Complete S2 (2026-11-30).
5. Voiding E1 now: 422 (R5). Voiding an unticked November expense: allowed, it disappears from S1 and S3.

## 8. Acceptance criteria
Setup: branches MAIN, KDY, GAL. KDY Bank (bank), KDY Cash (cash), GAL Bank (bank). Users: SA (Super Admin), ACC (Accountant in KDY, role holds `account.reconcile`), BA (Branch Admin in KDY), VW (Viewer in KDY), CSH (Cashier in KDY), GACC (Accountant in GAL with the key). Data as section 7 unless stated. Today 2026-11-20 unless stated.

**Permission**
1. Given a fresh install, then `account.reconcile` is in the catalogue, the Branch Admin and Accountant templates have it, Cashier and Viewer do not. Given an existing database, when the migration runs, then the key exists and every `*` template has it, and no other role gained it.
2. Given CSH or VW, when calling any `/accounts/{id}/reconciliation*` route, then 403 "Missing permission: account.reconcile", and the menu item and the "Reconcile" row action are hidden.
3. Given ACC (X-Branch-Id KDY), when calling the reconciliation routes for GAL Bank, then 404. Given SA in All mode, then GAL Bank works.
4. Given ACC, when `GET /accounts/{KDY Cash}/reconciliation`, then 422 "Only bank accounts can be reconciled."

**Ticking**
5. Given ACC, when `POST …/marks {mark:[{entry_id: TOP line, cleared_date:"2026-10-31"}]}`, then 200 `{marked:1, unmarked:0}`, one active mark exists with `marked_by` ACC, and `ledger_entries`, `accounts.current_balance` and `transactions` rows are byte-for-byte unchanged.
6. Given a mark with cleared date 2026-11-21 (after today) or "31/10/2026", then 422 and nothing is saved, including the other valid items of the same request.
7. Given a ledger line of KDY Cash or of GAL Bank sent as `entry_id` for KDY Bank, then 422 "Line <id> is not on this account."
8. Given expense E4 from KDY Bank that was voided, then its two lines are not in the S1 list, and marking either returns 422 "Voided entries cannot be reconciled (<ref>)."
9. Given TOP already ticked with 2026-10-31, when ticked again with 2026-10-31, then 422 "<ref> is already reconciled.". Given two simultaneous requests ticking the same line, then exactly one active mark exists.
10. Given TOP ticked and no statement completed, when `unmark:[TOP]`, then 200, the mark row stays with `unmarked_at`/`unmarked_by` set, and a later tick adds a new row.
11. Given TOP ticked with 2026-10-31, when `mark:[{TOP, "2026-10-30"}]`, then the old mark is closed, a new one is active with 2026-10-30, and the audit row lists it under `changed`.
12. Given any Save, then one `reconciliation.saved` audit row with branch KDY lists the marked/unmarked/changed lines, and `self_count` counts the lines whose transaction ACC created.

**Complete and undo**
13. Given step 1 of section 7, when Complete with 2026-10-31 / 479,500.00, then 422 "Difference is -500.00. The statement balance must equal the reconciled balance (480,000.00)." and no statement row exists.
14. Given step 2 of section 7, when Complete, then 201 with stored reconciled 479,500.00, book 469,500.00, uncleared receipts 15,000.00, uncleared payments 25,000.00, lines 4; the four marks have `statement_id` set; `reconciliation.completed` is audited.
15. Given S1 completed, when Complete with statement date 2026-10-31 or earlier, or 2026-11-21, then 422 with the R6 message.
16. Given S1 completed, when a mark is sent with cleared date 2026-10-31 or earlier, then 422 "The cleared date must be after 31 Oct 2026, the last completed statement."
17. Given S1 completed, when unmark of E1, then 422 "<ref> is in the completed statement of 31 Oct 2026. Undo that statement first." and the S1 checkbox for E1 is disabled with that tooltip.
18. Given an active mark with cleared date 2026-11-02 when S1 (2026-10-31) is completed, then that mark gets no `statement_id` and stays editable.
19. Given S1 and S2 completed, when ACC undoes S1, then 422 "Only the latest statement (30 Nov 2026) can be undone.". When ACC undoes S2 with reason "Wrong closing balance typed", then 200, S2 is "Undone" with by/at/reason, its marks are editable again, and `reconciliation.undone` is audited. A reason under 10 characters gives 422.
20. Given KDY November locked, when ACC or BA undoes S2 (2026-11-30), then 403 "The statement date is in a locked period. Only a Super Admin can undo it."; SA can. The same applies when November is in a closed fiscal year.
21. Given KDY October locked, then ACC can still tick lines dated in October and complete S1 dated 2026-10-31.

**Void, reverse, late entries**
22. Given E1's KDY Bank line is ticked (frozen or not), when E1 is voided, then 422 "<ref> is reconciled on KDY Bank (cleared 2026-10-31). Untick it first; if the money went through the bank, reverse the entry instead." and nothing changes.
23. Given E1 ticked, when E1 is reversed on 2026-11-05, then 201; E1's mark stays; the reversal's KDY Bank line appears unticked in S1 with tag "Reversal of <E1 ref>".
24. Given a top-up never presented to the bank and its reversal, when both lines are ticked with cleared date 2026-11-10, then the reconciled balance does not change.
25. Given S1 completed, when an expense of 1,000.00 dated 2026-10-25 is recorded from KDY Bank (October open), then S1 shows the late-entries banner with 1, the History row of S1 shows "Changed since completion", the S3 reprint of S1 shows Difference 1,000.00, and `ledger:verify` still exits 0.

**Statement, report, review**
26. Given TOP ticked with 2026-10-31, when `GET /accounts/{KDY Bank}/statement`, then TOP's line has `cleared_date` "2026-10-31" and the others null; the screen shows the Cleared column. For KDY Cash there is no Cleared column and no `cleared_date` key.
27. Given section 7 after step 2, when VW opens S3 As at 2026-10-31 for KDY, then the KDY Bank section lists I2 and E2 (ages 0 and 1), book 469,500.00, uncleared receipts 15,000.00, uncleared payments 25,000.00, expected bank balance 479,500.00, "last reconciled to 31 Oct 2026". As at 2026-11-20 after step 4, the section is empty with "All bank entries up to 20 Nov 2026 are reconciled.".
28. Given SA in All mode with `branches[]=KDY&branches[]=GAL`, then S3 has one section per bank account of both branches; ACC in KDY sending `branches[]=GAL` gets 403 (reports R1).
29. Given `older_than=20` As at 2026-11-20, then only lines dated on or before 2026-10-31 are listed.
30. Given ACC with `report.export`, when S3 is exported to PDF and Excel, then the files have the reports header lines, the rows and totals of the screen, the PDF has the signature block, and `report.exported` is audited. VW (no `report.export`) gets 403.
31. Given SA opens Period locks review for KDY October and KDY Bank's last statement is 2026-09-30, then the review lists "KDY Bank: last reconciled to 30 Sep 2026" and the lock still succeeds.
32. Given audit rows of each `reconciliation.*` event, when the Audit log is filtered by group "Bank reconciliation", then only those rows are returned, with the labels of R10.
33. Given a mark row edited in the database to point at a line of a void transaction, or a completed statement whose stored `reconciled_cents` no longer matches, when `php artisan ledger:verify`, then V10 fails, lists the offenders and exits 1.

**Layout**
34. Given a 390px wide viewport, when S1 is opened with 30 lines, then there is no horizontal page scroll, each line is a card with a checkbox tap target of at least 44x44px, the Difference tile stays visible while scrolling, and Save / Complete are in the sticky bottom bar.

## 9. Open questions for the accountant (assumption in bold, work proceeds on it)
- **Q1** Reconcile only `bank` accounts, or also `wallet` (mobile money) accounts, which also have provider statements? **Bank only, as the backlog says.** Adding wallet later is a one-line type check.
- **Q2** May a statement be completed with a non-zero difference (with a reason)? **No: Complete requires 0.00.** Missing bank charges/interest are recorded through the normal entry form first; a genuine unexplained difference stays as a draft until found.
- **Q3** Should period locks or a closed year block ticking or completing? **No: ticks post nothing.** Completed statements are their own lock (marks frozen, cleared dates after the last statement only). Only undoing a statement dated in a locked month or closed year is Super Admin only (R7).
- **Q4** Must the person who recorded a bank entry be prevented from reconciling it? **No (small branches have one accountant); flagged with `self_count` in the audit rows** and "You recorded this" on the line.
- **Q5** Should a new bank posting dated on or before the account's last completed statement date be refused? **No: allowed while the month is open, shown as a late entry and "Changed since completion" (R8).** Locking the month after reconciling prevents it.
- **Q6** On existing databases, should the migration also grant `account.reconcile` to branch Accountant roles? **No, as for `transfer.acknowledge`: only `*` templates; the release note asks the Super Admin to add it on the Roles screen.**
- **Q7** Should the cleared date be limited relative to the book date (for example not more than 31 days before it)? **No limit**; only after the last statement and not in the future.
- **Q8** Should locking a month require every bank account in scope to be reconciled through that month-end? **No: information line on the lock review only (S4).**
- **Q9** Voiding a ticked entry: refuse (untick first), or allow and untick automatically? **Refuse (R5).** A ticked entry went through the bank, so "never happened" is usually wrong; a reversal is the right correction.
