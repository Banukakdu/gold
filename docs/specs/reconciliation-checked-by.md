# Spec: Record "Checked by" on a bank reconciliation statement

## 1. Goal and scope
**AG-033d** (follow-up to AG-033; not yet a row in `docs/Account-gold_Product_Backlog.xlsx`, which has AG-033 only, Q8): "As a checker (Branch Admin, owner, Viewer / Auditor), I want to record in the system that I checked a completed bank reconciliation statement against the bank's own record, so that the independent check is visible, dated and cannot be done by the preparer."

Source: AG-033 Accounting review Q4 (the compensating control for self-reconciliation is an independent "Checked by"), AG-033b Accounting review finding 1 (the uploaded copy is a filing aid, so the checker compares with the bank's own statement or internet banking), AG-033c Q4 and accepted risk 3 (the accountant recommended this story next). Today "Checked by" and "Approved by" are blank lines on the printed statement (AG-033 finding 4) and an empty "Checked (initials, date)" column on the printed AG-033c list. Nothing is recorded in the system.

This story adds a **check** record on a `completed` `bank_statements` row: who, when, what they compared it with, a confirmation and a note. It shows the check on the AG-033c list (S6), the reprint (S3: screen, PDF, Excel), S1 History and the Lock confirm, and sends AG-094 bell notifications.

- **It posts nothing.** Recording, re-checking, withdrawing or cancelling a check never writes `ledger_entries`, `accounts.current_balance`, `transactions`, `bank_reconciliation_marks`, `attachments` or the stored figures and status of `bank_statements`. A check never changes "Changed since completion" (AG-033 R8).
- **No dependency on AG-033b (PR #25).** The "uploaded by the checker" tag (R2) switches on when `attachments.bank_statement_id` exists (the AG-033c `copies_supported` test). No code imports AG-033b classes.
- **No "Approved" step** (decision D1 below).

Out of scope: a checker outcome other than "Checked" (no "Queried" / "Not agreed" status, Q5); checking draft reconciliations; checking the plain As-at report (without `statement_id`); requiring a check before Complete, before Undo or before a period lock (D4); a notification when a check later needs a re-check (Q6); scheduled email; wallet and cash accounts; the AG-033 sign-off polish items (a) to (d).

### Decisions
- **D1 No in-system "Approved" step.** The project document has an approval step only where money moves or a balance changes before posting: transfers above the threshold (A10, 7.2, `transfer.approve`) and expenses above the branch limit (7.3, `expense.approve`). A reconciliation posts nothing. Month-end close (7.5) is: (1) the branch reconciles bank accounts, (2) the Super Admin reviews, (3) the Super Admin locks the month. The Super Admin's lock is the approval of the closed month, and the Lock confirm now shows which statements are not checked (D4). A second sign-off would add a key, a state machine and a segregation rule for no control the lock does not already give. So: one in-system "Checked" step. The blank "Approved by" line is removed from the PDF (Q2).
- **D2 Segregation (R2).** The checker must not be a **preparer**: not the user who completed the statement (`completed_by`) and not a user who ticked any line frozen in it (`bank_reconciliation_marks.marked_by` with `statement_id` = the statement). Ticking is preparing. Blocked for everyone, Super Admin included.
  - **Recorded entries in it: allowed, flagged.** In a small branch the Branch Admin records some entries; blocking would leave many statements uncheckable. The check stores `checker_recorded_count` and the reprint says so, the same detective style as `self_count` (AG-033 finding 4).
  - **Uploaded the copy (AG-033b): allowed, and it is the preferred case.** AG-033b release note: "Where possible the Branch Admin or owner downloads the statement from the bank and attaches it." A copy fetched by the checker is better evidence than one supplied by the preparer (`by_preparer`). The reprint tags it "uploaded by the checker".
- **D3 New key `account.reconcile_check`**, not `report.view` (section 2).
- **D4 Lock confirm: information, never a block.** Same as AG-033 Q8 and AG-033b finding 4. Locking is what stops late entries, so making the lock wait for a checker would leave the month open longer. Also, a branch whose only other user is away could not be locked at all. The Super Admin, who locks, sees the unchecked and re-check-needed statements and decides.
- **D5 Undo cancels; late entries flag a re-check (R5, R6).** Undo supersedes the statement, so its check is cancelled. A late entry after the check leaves the statement balance and the expected bank balance as they were (AG-033 R3 identity). What the checker agreed to the bank is still true, but the list of uncleared items has changed, and that list is what the checker looked at. So the check stays and is marked "Re-check needed". It is not withdrawn automatically.

## 2. Personas and permissions
**New key `account.reconcile_check`** in `PermissionSeeder::PERMISSIONS`, right after `account.reconcile`. Meaning: may record a check on a completed bank reconciliation statement of the role's branch, and withdraw their own check. The Roles screen shows it as "reconcile_check" in the account group (`role-editor.component.ts` shows the part after the dot).

Why not `report.view`: `report.view` is a read key. The Accountant template holds it, and the Accountant is the usual preparer. Using it would make every preparer a checker by default, and a Branch Admin could not choose who checks without also removing report access. A check is a sign-off that others rely on, so it gets its own key, chosen per role. The checker also needs `report.view` to open the list and the reprint, so every check route needs both keys.

| Template | `account.reconcile_check` | How |
|---|---|---|
| Branch Admin (`*`) | yes | added to `*` templates by `PermissionSeeder::run`. Existing databases: the migration adds it to the `*` templates and to every branch role that holds every other key except the MAIN-only `transfer.approve` (the unchanged Branch Admin copies), with a `role.updated` audit row each, `source` "migration reconciliation_checks (AG-033d)". A copy the Super Admin narrowed is left as it is |
| Viewer | yes | added to `TEMPLATES['Viewer']` (new installs). The Viewer / Auditor is the independent checker named in AG-033 Q4 and AG-033c. Recording a check changes no accounting record, so the Viewer stays read-only for the books (Q1). Existing databases: the Super Admin adds it to the Viewer template and the branch Viewer roles on the Roles screen (release note), as AG-033 Q6 |
| Accountant | no | the preparer role. A branch with two accountants may give the key to a custom role (Q3) |
| Cashier | no | |
| Super Admin | yes (all keys) | any branch, All mode included. Same segregation rule (R2) |

Other keys used:

| Need | Key |
|---|---|
| Record a check, re-check | `report.view` + `account.reconcile_check` in the statement's branch |
| Withdraw a check | the same keys, and the user is the checker; or Super Admin (R7) |
| See check status on S6, S3, S1 History | the existing guards (`report.view`; S1 `account.reconcile`) |
| Export S6 / S3 with check columns | `report.view` + `report.export` (unchanged) |
| Lock confirm lines | Super Admin (unchanged) |

Nobody may grant `account.reconcile_check` without holding it (existing role rules). An Accountant cannot give it to themselves. A full Branch Admin copy holds it after the migration, so the Branch Admin can grant it to the branch Viewer role.

## 3. User flows
- **F1 Viewer checks month-end (VW, KDY, has the key).** Reports > Bank reconciliation statements. Filter Checked = Not checked. VW opens KDY Bank 31 Oct 2026 (completed by ACC). The reprint shows the figures, the late entries and the check block "Not checked in the system." VW opens internet banking, compares the closing balance 479,500.00 and the uncleared items, then clicks **Record check**. In the dialog VW chooses "Internet banking", ticks the confirmation, and types a note (required here, because the statement has late entries): "Late cheque 25 Oct agreed to chq book; not yet presented." VW saves. Toast "Check recorded." The block now reads "Checked by VW on 2026-12-10 14:05 against internet banking." ACC's bell shows "KDY Bank statement of 31 Oct 2026 was checked".
- **F2 Preparer cannot check.** BA completed KDY Bank 30 Nov 2026. On its reprint the Record check button is disabled with the text "You completed this statement. Someone else must check it."
- **F3 Late entry after the check.** On 2026-12-10 ACC records a cheque dated 2026-11-20 from KDY Bank (November open). S6 shows the 30 Nov statement as "Re-check needed (1 since check)". The reprint lists the cheque under late entries with the tag "after check". VW opens it, checks the new item, and clicks **Re-check** (same dialog, note required). The old check becomes "Superseded" and the new one is active.
- **F4 Statement undone after the check.** BA undoes KDY Bank 30 Nov 2026 ("Wrong closing balance typed"). Its check becomes "Cancelled: statement undone". VW's bell shows "Your check of KDY Bank 30 Nov 2026 no longer applies: the statement was undone." The replacement statement starts as Not checked.
- **F5 Withdraw.** VW realises they compared with the wrong month. Reprint > "Withdraw check" > reason (10-500) > confirm. The check stays in the history as "Withdrawn by VW on …: <reason>". The statement is Not checked again, and ACC is notified.
- **F6 Super Admin, All branches.** S6 in All mode, Checked = Not checked, lists unchecked statements of every branch with the Branch column. The Super Admin may check a GAL statement they did not prepare. The check and its audit rows belong to GAL. With X-Branch-Id KDY, only KDY statements are shown.
- **F7 Month-end lock (Super Admin).** Period locks > Lock November for KDY. The confirm adds "KDY: 1 completed bank reconciliation not checked (KDY Bank 30 Nov 2026)." and, when it applies, "KDY: 1 checked bank reconciliation needs a re-check (…)". Information only; the lock succeeds.
- **F8 Preparer follows up (ACC).** S1 History shows a Checked column: "Checked by VW, 2026-12-10", "Re-check needed" or "Not checked".

## 4. Screens
### S3 Reprint `/reports/bank-reconciliation?statement_id=…` (existing; screen, PDF, Excel)
New block **"Check"**. It goes after "Balance per bank statement / Difference" and after the AG-033b "Bank statement copy" block when that block exists, and before the late entries.
- **Active check:** "Checked by <name + controls R10 tag> on <YYYY-MM-DD HH:mm> against <the bank's statement | internet banking>." The time is Asia/Colombo, written without a zone label on the screen, PDF and Excel alike (as "Prepared by" and AC 24; the export header already says "(Asia/Colombo)"). Then "Note: <note>" when a note was given. Tags, each when it applies:
  - " - checked after close" (`after_close`, R8);
  - " - the checker recorded N of the M entries on this statement" (`checker_recorded_count` > 0; "the only entry on this statement" when N = M = 1, "all M entries on this statement" when N = M), followed by ", K of them uncleared" when `checker_uncleared_count` > 0;
  - " - changed since completion when checked" (`changed_at_check`).
- **Re-check needed** (warning): "Re-check needed: N entries were recorded or voided after the check." (one: "1 entry was …") In the late entries list, each line with `ledger_entries.id` > the check's `max_entry_id` gets the tag "after check".
- **Earlier checks** (history, oldest first): "Superseded: checked by <name> on <date>", "Withdrawn: checked by <name> on <date>; withdrawn by <name> on <date>: <reason>", "Cancelled: checked by <name> on <date>; statement undone by <name> on <date>".
- **None:** "Not checked in the system."
- **AG-033b copy line** (when `copies_supported`): a file whose uploader is the active check's checker gets " - uploaded by the checker".
- **PDF signature block.** It keeps "Prepared by <name> on <date>" and "<self_count> of <lines_count> lines were recorded by the preparer". The blank "Checked by" line is replaced by "Checked by <name> on <date time> (recorded in Account-gold, check #<id>)". When there is no active check, a blank "Checked by ____ Date ____" line stays with "Not checked in the system". When a re-check is needed, the line says "Re-check needed" next to the check. The "Approved by" line is removed (D1).
- **Excel:** the same lines in the header block above the table, including the signature line "Checked by … (recorded in Account-gold, check #<id>)" (with " - Re-check needed") and the "[after check]" tag on late-entry lines.
- **Actions** (screen only, from `statement.check_actions`):
  - **Record check** when `can_check`. It is labelled **Re-check** when `check_status = recheck`.
  - **Withdraw check** when `can_withdraw`.
  - When the user holds the keys but `blocked_reason` is set, the button is disabled and the reason is shown under it. Examples: "You completed this statement. Someone else must check it." or "This statement is already checked."
  - Without the key, no button.
- **Undone statement:** no actions. The block shows its checks as history, and the AG-033 superseded banner is unchanged.

**Record check dialog**

| Field | Type | Required | Validation |
|---|---|---|---|
| Compared with | radio | yes | `bank_statement` "The bank's statement (paper or PDF received from the bank)" / `internet_banking` "Internet banking". Hint: "Not the uploaded copy alone. Use the bank's balance at the end of <statement date>, not today's balance." (Accounting review finding 1) |
| Confirmation | checkbox | yes, must be ticked | "I agreed the statement balance (<balance>) to the bank's own record of the balance at the end of <statement date>, and I checked that the uncleared items on this statement are genuine (they appear on the bank's record after <statement date>, or I saw the cheque book or deposit record)." (Accounting review finding 1) |
| Note | textarea | required (10-500) when the statement is changed since completion, has late entries, or this is a re-check; otherwise optional (max 500) | trimmed. Hint when required: "Explain the late entries you checked." |

The dialog shows the account, the statement date, the statement balance (with "Overdrawn" when negative) and the late-entries count. Save shows the toast "Check recorded." On a 403 or 422 the API `message` is shown in the dialog `.alert`, and the dialog stays open.

**Withdraw dialog:** Reason (textarea, required, 10-500). Text: "Withdraw your check of KDY Bank, 31 Oct 2026? The check stays in the history." Toast "Check withdrawn."

### S6 Bank reconciliation statements `/reports/reconciliation-statements` (AG-033c)
- New filter **Checked** (select, required, default Any): Any / Checked / Re-check needed / Not checked. Checked, Re-check needed and Not checked list completed rows only. Undone rows are listed only with Any, the same as the AG-033c Late entries filter.
- New column **Checked**, after Late entries:
  - "Checked by <name>, <date>" (green tag);
  - "Re-check needed (N since check)" (warning tag, with the checker in a second line);
  - "Not checked" (warning tag) for a completed row with no active check;
  - for an undone row: "Check cancelled" when it had a check, blank otherwise.
- **Exports** (PDF and Excel). The empty PDF column "Checked (initials, date)" and the footer block "Checked by / Name / Signature / Date" are removed. The PDF gets a filled "Checked" column with the same text as the screen. Excel gets the columns "Check status" (Checked / Re-check needed / Not checked / Check cancelled / blank), "Checked by", "Checked at" (date-time cell) and "Compared with". The "filters used" line includes Checked.
- **390px:** the card gets the Checked tag in line 3, next to "Changed since completion" and "Late entries N".

### S1 Bank reconciliation History `/accounts/:id/reconcile` (AG-033, read-only change)
New column **Checked** after Status, with the S6 texts. On the 390px History card it is a line "Checked: …".

### S2 Period locks, Lock confirm (existing, Super Admin)
Two information lines per branch. They cover completed statements of the branch's bank accounts whose statement date is in the months the lock newly closes (`closes_from` to the period, as AG-033b finding 4):
- "<code>: N completed bank reconciliation(s) not checked (<account> <date>, …)";
- "<code>: N checked bank reconciliation(s) need a re-check (<account> <date>, …)".

The lines are omitted at 0, list at most 5 statements and then add "and N more". They never block.

The `period.locked` audit row records what the Super Admin was shown: new values gain `bank_reconciliation_checks: [{branch_id, not_checked: [statement_id, …], recheck: [statement_id, …]}]` (empty arrays when none), computed in the lock transaction with the same rule as the confirm lines (Accounting review finding 3). The lock itself is unchanged.

### S5 Audit log (existing)
New events in the existing "Bank reconciliation" group (`reconciliation.*`): "Bank statement checked", "Bank statement check withdrawn", "Bank statement check cancelled". Subject: "<account code> <account name>, statement <date>".

### 390px layout
- The Record check and Withdraw dialogs become full-screen sheets with a sticky header (title and a 44px close button). The radios and the confirmation checkbox have tap targets of at least 44x44px. Save sits in a sticky bottom bar, full width, at least 44px tall.
- The S3 Check block stacks: line 1 "Checked by <name>"; line 2 date time and compared with; line 3 note (wraps); line 4 the tags (wrap). Record check / Re-check / Withdraw are full-width buttons under the block.
- No horizontal page scroll at 360px or 390px.

### States
- Loading: the S3 block shows a skeleton line. The buttons are disabled while saving.
- Errors: the API `message` in `.alert`. A network error shows "Could not save the check. Try again." and keeps the dialog values.

## 5. API
All under `auth:sanctum` + `branch`. The `{account}` binding is `BranchScoped`. `{statement}` and `{check}` use scoped binding (`->scopeBindings()`). Another branch's statement, a statement not on `{account}`, or a check not on `{statement}` returns 404. The routes are outside the `perm:account.reconcile` group.

| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `POST /v1/accounts/{account}/reconciliation/statements/{statement}/checks` | `perm:report.view,account.reconcile_check` | `compared_with` (req., `in:bank_statement,internet_banking`), `confirmed` (req., `accepted`), `note` (string, trimmed, max 500; required min 10 per R3) | 201 `Check` (+ `superseded: Check` on a re-check). Errors R3 |
| `POST …/statements/{statement}/checks/{check}/withdraw` | `perm:report.view,account.reconcile_check` | `reason` (req., trimmed, 10-500) | 200 `Check`. Errors R7 |
| `GET /v1/reports/bank-reconciliation?statement_id=` (existing) and its export | unchanged | unchanged | `statement` gains `check_status` (`checked\|recheck\|not_checked\|cancelled\|null`), `checks:[Check]` (all, oldest first), `late_since_check` (int\|null), `check_actions:{can_check, can_withdraw, blocked_reason\|null}`. Each late entry gains `after_check` (bool) |
| `GET /v1/reports/reconciliation-statements` (AG-033c) and its export | unchanged | + `checked` (`in:any,checked,recheck,not_checked`, default `any`) | each row gains `check_status` and `check:{id, checked_by:{id,name,tag}, checked_at, compared_with, after_close, late_since_check}\|null` (the active check) |
| `GET /v1/accounts/{account}/reconciliation` (AG-033) | unchanged | unchanged | each `statements[]` item gains `check_status` and `check` (same shape) |
| `GET /v1/period-locks/review` (existing) | `super` | unchanged | + `bank_reconciliation_checks:[{branch:{id,code}, not_checked:[{statement_id, account:{id,code,name}, statement_date}], recheck:[…]}]` |

`Check` = `{id, bank_statement_id, account_id, branch:{id,code}, checked_by:{id,name,tag}, checked_at, compared_with, note, confirmed: true, changed_at_check, late_count_at_check, checker_recorded_count, checker_uncleared_count, entries_count, after_close: 'close'|null, status: 'active'|'superseded'|'withdrawn'|'cancelled', ended_by:{id,name,tag}|null, ended_at, end_reason, superseded_by_id}`. `check_status` is null for an undone statement that never had a check.

There is no DELETE route and no route to edit a check. Money is a decimal string from `Money::fromCents`.

**Schema** (migration `2026_12_07_000000_reconciliation_checks.php`, ADD only; dated after AG-033b's `2026_12_06_…`, so either PR can merge first):
- `bank_statement_checks`: `id`, `branch_id` FK, `bank_statement_id` FK **restrict**, `account_id` FK, `checked_by` FK users, `checked_at` datetime, `compared_with` string(20), `note` text null, `max_entry_id` bigint (the account's highest `ledger_entries.id` at the check), `changed_at_check` bool, `late_count_at_check` int, `checker_recorded_count` int, `checker_uncleared_count` int, `entries_count` int, `after_close` string(10) null, `status` string(12) default `active`, `ended_by` FK users null, `ended_at` null, `end_reason` text null, `superseded_by_id` FK self null, timestamps.
- One active check per statement through `active_key` (a generated column on MySQL and a partial unique index on SQLite, as `bank_statements`). Indexes (`bank_statement_id`, `status`) and (`branch_id`, `status`).
- Triggers (after `MigrationPreflight::assertCanCreateTriggers()`): `bank_statement_checks_no_delete` aborts every DELETE. `bank_statement_checks_no_update` aborts any UPDATE except (a) the one-time move from `active` to `superseded`/`withdrawn`/`cancelled`, which sets `ended_by` and `ended_at` (and `end_reason`, required for `withdrawn`/`cancelled`) and changes nothing else, and (b) the one-time link of a `superseded` check whose `superseded_by_id` is still null. A re-check ends the old check, inserts the new one, then links the old one (the one-active-check index allows no other order); V11 checks the link. MySQL `SIGNAL`, SQLite `RAISE(ABORT)`. The model guards match.
- Model `BankStatementCheck` uses `BranchScoped`. `BankStatement` gains `checks()` and `activeCheck()`.
- Permission `account.reconcile_check`, inserted where missing (the AG-033 `addPermission` / `grantFullCopies` pattern: `*` templates, then full branch copies, each with an audited `role.updated` row). On a fresh install the seeder adds it, with the Viewer template.
- Rollback is refused while any `bank_statement_checks` row exists. Otherwise it removes the key and its grants, with a `role.updated` row for each removed grant (as AG-033).

## 6. Business rules
- **R1 Branch isolation.**
  - The check's `branch_id` is the statement's `branch_id`, never the header's. Audit rows and notification branch tags use the same branch.
  - A branch user sees and checks only the active branch's statements. A forged id from another branch returns 404.
  - Super Admin in All mode: every branch. With X-Branch-Id set: that branch only.
  - S6 and the Lock confirm follow the AG-033c R1 and AG-033 S4 scoping. `withoutGlobalScopes()` is used only after the branch set is resolved.
  - A deactivated user, or a user removed from the branch, is refused by `SetBranchContext` (403) before this code runs.
- **R2 Segregation (D2).** The user is a **preparer** of statement S when `S.completed_by` = user, or when a `bank_reconciliation_marks` row with `statement_id` = S has `marked_by` = user.
  - A preparer gets 403 "You completed this statement. Someone else must check it." or 403 "You ticked lines in this statement. Someone else must check it."
  - This applies to the Super Admin too. A re-check follows the same rule.
  - `checker_recorded_count` = the number of entries on the statement whose transaction `created_by` is the checker. "Entries on the statement" means the frozen lines of S, plus the uncleared lines and the cleared-but-booked-later lines listed on its reprint at the time of the check. `entries_count` is the number of those entries (M). `checker_uncleared_count` = how many of the checker's N entries are uncleared items (uncleared receipts or payments on the reprint at the time of the check), because an uncleared item is where an unsupported entry would sit and the checker would be vouching for their own entry (Accounting review finding 2). All three are stored at the check, so the counts stay as they were even if entries are added later.
- **R3 Record a check.** It runs in one DB transaction. It locks the account row, then the `bank_statements` row (`lockForUpdate`, the same order as Undo, so a check and an undo cannot race). The checks run in this order, and the first failure wins:
  1. The statement status is not `completed`: 422 "Only a completed statement can be checked."
  2. Preparer: 403 (R2).
  3. An active check exists and `late_since_check` = 0: 422 "This statement is already checked." (The unique `active_key` also gives this message under a race.)
  4. `compared_with` missing or invalid: 422 "Choose what you compared the statement with." `confirmed` not accepted: 422 "Confirm that you compared the statement with the bank's own record."
  5. The note is required (the statement is changed since completion, or `lateCount` > 0, or an active check exists) and has fewer than 10 characters: 422 "Explain the late entries in the note (at least 10 characters)." Over 500: 422 "The note may be at most 500 characters."
  
  Effect:
  - Insert the check with `max_entry_id` = the account's current highest ledger line id, `changed_at_check` = `changed(S)`, `late_count_at_check` = `lateCount(S)`, the R2 counts, and `after_close` (R8).
  - On a re-check, the old check gets `status = superseded`, `ended_by` = the user, `ended_at`, and `superseded_by_id`.
  - Write the audit row (R9). Dispatch `ReconciliationChecked` after commit.
- **R4 Check status of a statement.**
  - `cancelled` when the statement is undone and had a check. Null when it is undone without one.
  - `not_checked` when the statement is completed and has no active check.
  - `recheck` when it has an active check and `late_since_check` > 0.
  - `checked` otherwise.
  - `late_since_check` = the count of `ledger_entries` lines on the statement's account with `entry_date <= S` (`whereDate`) and `id > check.max_entry_id`. This is the AG-033 R8 late rule with the check's id instead of the statement's, so void contras count. It is computed in one grouped query per page or export, not per row.
  - The S6 `checked` filter is computed after the AG-033c SQL cap, like `changed` and `late` (AG-033c R4).
- **R5 Late entries after a check (D5).** The check is not ended automatically. The statement shows "Re-check needed". A re-check (R3) supersedes it. The check row is never changed by a posting. Postings never read or lock checks, so `LedgerService` is unchanged.
- **R6 Undo.** `BankReconciliation::undo` sets the active check, in the same transaction, to `status = cancelled`, `ended_by` = the undoer, `ended_at`, `end_reason` = "Statement undone: <undo reason>". It writes `reconciliation.check_cancelled` and dispatches `ReconciliationCheckCancelled` after commit.
  - Undo is not blocked by a check. The AG-033 R7 rules (latest only, Super Admin in a locked month) are unchanged.
  - A statement completed again for the same date is a new row and starts as `not_checked`.
- **R7 Withdraw.**
  - Allowed for the checker of an active check (they still need both keys in the branch) and for a Super Admin. Anyone else gets 403 "Only the checker or a Super Admin can withdraw this check." (decided before the reason is validated).
  - Reason: trimmed, 10-500 characters; 422 "Give the reason for withdrawing the check (at least 10 characters)." or "The reason may be at most 500 characters.".
  - A check that is not active: 422 "This check is no longer active."
  - When the statement date is locked for its branch (`PeriodLock::isLocked($branchId, S, share: true)`, cumulative) or in a closed fiscal year (`YearEnd::closedYearOf`): 422 "The period 2026-10 is locked." or the project's fiscal-year message "FY <label> is closed." (`YearEnd::closedOrLockedMessage`, e.g. "FY 2025-26 is closed."). The reprint returns `check_actions.can_withdraw = false` with that text in `withdraw_blocked_reason`, shown under a disabled Withdraw button; the 422 inside the transaction stays the real guard. This applies to the Super Admin too, as AG-033b R4. The remedy is an audited Super Admin unlock, then withdraw, then lock again.
  - Effect: `status = withdrawn`, the `ended_*` fields set. Audit, then notify after commit.
- **R8 Locked month or closed year.** Recording a check or a re-check is allowed. It adds evidence and changes no figure. The month-end order may be "lock, then check", and statements may also be completed after the lock (AG-033 Q3). `after_close = 'close'` is set from the same locking read and shown as "checked after close". Withdrawal is refused (R7). Undo of a locked statement is already Super Admin only.
- **R9 Audit** (`AuditLog::record`, auditable = the **BankStatement**, `branch_id` = the statement's branch; add a `BankStatement` case to `AuditLogController::subject` if it is not there yet; AG-033b adds the same case):
  - `reconciliation.checked`: new values `{check_id, statement_id, account_id, statement_date, statement_balance, compared_with, confirmed: true, note, changed_at_check, late_count_at_check, checker_recorded_count, checker_uncleared_count, entries_count, after_close, supersedes_id|null}`. A re-check writes this one row. The superseded check's id is in `supersedes_id`, and the old values hold the superseded check `{check_id, status: 'active', checked_by, checked_at, late_since_check}` (null on a first check) (Accounting review finding 4).
  - `reconciliation.check_withdrawn`: old values `{check_id, status: 'active', checked_by, checked_at}`; new values `{status: 'withdrawn', reason, self}`, where `self` means the withdrawer is the checker.
  - `reconciliation.check_cancelled`: old values `{check_id, status: 'active', checked_by, checked_at}`; new values `{status: 'cancelled', undo_reason}`.
  - Refused attempts (403/422) and views write no row, as AG-033b.
- **R10 Notifications (AG-094).** Written by `SendInAppNotifications` (synchronous, errors swallowed, notifications R3). Only `active` users are notified, never the actor, with one row per user (R2 dedupe). Branch tag = the statement's branch. `url` = `/reports/bank-reconciliation?statement_id=<id>`. The note and the reason are **never** copied into the notification (notifications R1). No email.

  | Event | kind | Recipients | Title / body |
  |---|---|---|---|
  | `ReconciliationChecked` | `reconciliation.checked` | `completed_by` | "<account> statement of <date> was checked" / "Checked by <name> against <the bank's statement \| internet banking>." (re-check: "was checked again") |
  | `ReconciliationCheckWithdrawn` | `reconciliation.check_withdrawn` | `completed_by`, and the checker when a Super Admin withdrew | "Check withdrawn on <account> statement of <date>" / "Withdrawn by <name>." |
  | `ReconciliationCheckCancelled` | `reconciliation.check_cancelled` | the checker | "Your check of <account> <date> no longer applies" / "The statement was undone by <name>." |

  A recipient without `report.view` gets the row. The link opens the reprint's normal 403 state. No template has `account.reconcile` without `report.view`.
- **R11 `ledger:verify` V11.** It checks that:
  - every active check is on a `completed` statement of its own `branch_id` and `account_id`;
  - each statement has at most one active check;
  - no check's `checked_by` is a preparer of its statement (R2);
  - every `cancelled` check's statement is `undone`;
  - every ended check (`superseded`, `withdrawn`, `cancelled`) has `ended_by` and `ended_at`, and every `superseded` check's `superseded_by_id` is a check on the same statement with a later `checked_at` (Accounting review finding 4).
  
  Any failure exits 1 with the offenders.
- **R12 Edge cases.**
  - A one-person branch (the preparer is the only user): only another user with the key, such as the Super Admin, can check. The statement stays "Not checked", visible on S6 and the Lock confirm (Q4).
  - Two users checking at once: one gets 201, the other 422 "This statement is already checked."
  - The checker is later deactivated or removed from the branch: the check stays active, and the name gets the controls R10 tag. A Super Admin may withdraw it. A deactivated user's check is still a valid record of what was done.
  - An inactive bank account or inactive branch: checks are allowed (nothing is posted).
  - An older statement may be checked after a later one. Each statement is checked on its own. Checking one implies nothing about the others.
  - A check on a statement that is "Changed since completion" at the time is allowed, with a note. It is flagged `changed_at_check`.
  - A late entry recorded and then voided after the check: `late_since_check` is 2, so a re-check is needed, even though "Changed since completion" may be false again (the same trace rule as AG-033c R3).

## 7. Acceptance criteria
Setup: as AG-033c section 7 (today 2026-12-10). ST1 (KDY Bank 2026-10-31, completed and all lines ticked by ACC, late entries 1), ST2 (2026-11-30, undone by BA), ST3 (2026-11-30, completed and all lines ticked by BA), ST4 (GAL Bank 2026-10-31, completed by GACC). The roles below were made on a fresh install:
- VW: Viewer in KDY, with `account.reconcile_check`.
- BA: Branch Admin in KDY.
- ACC: Accountant in KDY, without the key.
- CSH: Cashier in KDY.
- GVW: Viewer in GAL.
- SA: Super Admin.
- VW2: in KDY, with `report.view` and without the key.

E3 (the 500.00 bank charge in ST1) was recorded by BA.

**Permission**
1. Given a fresh install, then `account.reconcile_check` is in the catalogue, and the Branch Admin and Viewer templates have it; Accountant and Cashier do not. Given an existing database, when the migration runs, then the key exists and every `*` template has it. Every branch role holding every other key except `transfer.approve` has it, with a `role.updated` audit row whose `source` is "migration reconciliation_checks (AG-033d)". The Viewer template, branch Viewer roles and Accountant roles are unchanged.
2. Given ACC, when ACC posts a check on ST3, then 403 "Missing permission: account.reconcile_check", and the reprint shows no Record check button. Given CSH, then 403 "Missing permission: report.view". Given VW2, then 403 "Missing permission: account.reconcile_check".
3. Given ACC with `role.manage` in a custom role without the key, when ACC tries to add `account.reconcile_check` to a role, then the existing "cannot grant a permission you do not hold" refusal. Given BA, when BA adds it to the KDY Viewer role, then 200 and a `role.updated` row.

**Recording**
4. Given VW, when VW posts `{compared_with: "internet_banking", confirmed: true, note: "Late cheque 25 Oct agreed to chq book"}` on ST1, then 201. The check has `checked_by` VW, `status` active, `changed_at_check` true, `late_count_at_check` 1, `checker_recorded_count` 0, `after_close` null, and `branch_id` KDY. One `reconciliation.checked` audit row (auditable ST1, branch KDY) has the R9 values. ACC has one unread `reconciliation.checked` notification whose body has no note text.
5. Given ST1, when VW posts without a note or with "ok", then 422 "Explain the late entries in the note (at least 10 characters).". Given ST3 (no late entries), when VW posts without a note, then 201.
6. Given `confirmed` false or missing, then 422 "Confirm that you compared the statement with the bank's own record.". Given `compared_with: "copy"`, then 422 "Choose what you compared the statement with.". No check row exists afterwards.
7. Given BA, when BA posts a check on ST3, then 403 "You completed this statement. Someone else must check it.", and the S3 button is disabled with that text.
8. Given a KDY Bank 2 statement completed by ACC in which BA ticked one frozen line, when BA posts a check, then 403 "You ticked lines in this statement. Someone else must check it.".
9. Given BA, when BA checks ST1 (BA recorded E3 but ticked nothing), then 201 with `checker_recorded_count` 1 and `checker_uncleared_count` 0 (E3 is ticked). The reprint (screen, PDF, Excel) shows " - the checker recorded 1 of the <entries_count> entries on this statement". Given BA recorded an uncleared bank payment on a KDY Bank 2 statement completed by ACC, when BA checks it, then `checker_uncleared_count` is 1 and the tag ends with ", 1 of them uncleared".
10. Given AG-033b merged and `oct.pdf` uploaded to ST1 by BA, when BA checks ST1, then 201, and the reprint copy line ends with " - uploaded by the checker". Given `main` without AG-033b, then checks work and no copy line is shown.
11. Given ST2 (undone), when VW posts a check, then 422 "Only a completed statement can be checked.".
12. Given VW's active check on ST3 and no late entries since, when SA posts a check on ST3, then 422 "This statement is already checked.". Given two simultaneous checks on ST1, then exactly one active check exists, and the other request gets 422.

**Branch isolation**
13. Given VW (X-Branch-Id KDY), when posting a check on ST4 (GAL), then 404. Given SA in All mode, then 201 and the check, the audit row and GACC's notification have branch GAL. Given SA with X-Branch-Id KDY, then ST4 returns 404.
14. Given a statement completed by SA, when SA posts a check on it, then 403 "You completed this statement. Someone else must check it.".

**Re-check, undo, withdraw**
15. Given VW checked ST3, when ACC records an expense of 2,000.00 dated 2026-11-20 from KDY Bank (not ticked), then the check stays `active`. S6 shows ST3 "Re-check needed (1 since check)". The reprint shows "Re-check needed: 1 entry was recorded or voided after the check." with the line tagged "after check". `check_status` is `recheck`. When VW re-checks with a note, then 201: the old check is `superseded` with `superseded_by_id` set, and the audit row has `supersedes_id` and the old check in its old values. When ACC then voids that expense, then `late_since_check` for the new check is 1 (the contra), and a re-check is needed again.
16. Given VW checked ST1 while ST1 had 1 late entry from before the check, then `check_status` is `checked` (not `recheck`).
17. Given VW checked ST3, when BA undoes ST3 with "Wrong closing balance typed", then 200. The check is `cancelled` with `ended_by` BA and `end_reason` "Statement undone: Wrong closing balance typed". One `reconciliation.check_cancelled` row exists. VW has a `reconciliation.check_cancelled` notification. S6 shows ST3 as Undone with "Check cancelled". A new statement completed for 2026-11-30 shows "Not checked".
18. Given VW's active check on ST1, when VW withdraws with "Compared with the September statement by mistake", then 200 and `status` is withdrawn. A `reconciliation.check_withdrawn` row has `self` true. ACC is notified. S6 shows ST1 "Not checked", and the reprint lists the withdrawn check in the history. A second withdraw returns 422 "This check is no longer active.". A reason under 10 characters returns 422.
19. Given VW's active check on ST1, when BA withdraws it, then 403 "Only the checker or a Super Admin can withdraw this check.". When SA withdraws it, then 200, and both ACC and VW are notified.
20. Given KDY October locked (or only KDY November locked, under the cumulative rule), when VW checks ST1, then 201 with `after_close` "close", and the reprint shows " - checked after close". When VW or SA withdraws it, then 422 "The period 2026-10 is locked.". The same applies when 2026-10-31 is in a closed fiscal year, with the fiscal-year message "FY <label> is closed." (e.g. "FY 2025-26 is closed."). In both cases the reprint shows Withdraw disabled with the same message (`withdraw_blocked_reason`). When SA unlocks October with a reason, VW withdraws, and SA locks again, then the audit log holds `period.unlocked`, `reconciliation.check_withdrawn` and `period.locked` in that order (Accounting review finding 5).
21. Given a raw query that deletes a check row, changes its `checked_by`, `note` or `max_entry_id`, or changes an ended check again, then it throws and nothing changes, on SQLite and in the `api-mysql` job. A `bank_statements` row with checks cannot be deleted (FK restrict). There is no DELETE route.

**List, reprint, history, lock**
22. Given VW checked ST1 and nothing else is checked, when VW opens S6 with default filters, then ST1 shows "Checked by VW, 2026-12-10", ST3 shows "Not checked", and ST2 shows blank. With Checked = Not checked, only ST3 is listed. With Checked = Checked, only ST1. With Checked = Re-check needed, no rows and "No statements match these filters.".
23. Given ACC exports S6 to PDF and Excel, then the PDF has a filled "Checked" column and no "Checked (initials, date)" column and no "Checked by / Name / Signature / Date" footer. Excel has the Check status, Checked by, Checked at (date-time cell) and Compared with columns, and the filters line names Checked. A note starting with "=" is written as text on the S3 Excel.
24. Given ST1 checked by VW, when the S3 reprint is opened, exported to PDF and exported to Excel, then each shows "Checked by VW on 2026-12-10 <HH:mm> against internet banking." and the note. The PDF line reads "Checked by VW on … (recorded in Account-gold, check #<id>)", and there is no "Approved by" line. Given ST3 not checked, then "Not checked in the system." is shown, and the PDF keeps a blank "Checked by" line.
25. Given ST1 checked, when ACC opens S1 History for KDY Bank, then the ST1 row shows "Checked by VW, 2026-12-10" and ST3 shows "Not checked".
26. Given ST3 not checked, and ST1 checked with a later late entry (re-check needed), when SA opens the Lock confirm for KDY November, then it shows "KDY: 1 completed bank reconciliation not checked (KDY Bank 30 Nov 2026)" and "KDY: 1 checked bank reconciliation needs a re-check (KDY Bank 31 Oct 2026)", and the lock succeeds. The `period.locked` audit row has `bank_reconciliation_checks` `[{branch_id: KDY, not_checked: [ST3], recheck: [ST1]}]`.

**Notifications, audit, no postings**
27. Given ACC is deactivated, when VW checks ST1, then no notification is written for ACC, and the check is still 201. Given ACC (active) clicks the `reconciliation.checked` row, then the row is marked read and `/reports/bank-reconciliation?statement_id=<ST1>` opens in KDY. Given SA in All mode is a recipient of a GAL row (AC 13 withdrawn by another SA), then opening it switches to GAL (notifications S3).
28. Given any check, re-check, withdraw or cancel, then `ledger_entries`, `accounts.current_balance`, `bank_reconciliation_marks`, `attachments` and every `bank_statements` column are unchanged, `changed_since_completion` is unchanged, and `ledger:verify` exits 0. Given a check row edited in the database so that its `checked_by` is the statement's `completed_by`, then V11 fails, lists the offender and exits 1.
29. Given audit rows of the three new events, when the Audit log is filtered by group "Bank reconciliation", then they are returned with the labels "Bank statement checked", "Bank statement check withdrawn", "Bank statement check cancelled" and the subject "11002 KDY Bank, statement 2026-10-31".

**Layout**
30. Given a 390px viewport, when VW opens the ST1 reprint and records a check, then the dialog is a full-screen sheet. The radios and the confirmation have tap targets of at least 44x44px, Save is in a sticky bottom bar at least 44px tall, the Check block stacks and wraps, the S6 card shows the Checked tag, and there is no horizontal page scroll at 360px or 390px.

**Dialog wording (Accounting review finding 1)**
31. Given VW opens Record check on ST1, then the Compared with hint reads "Not the uploaded copy alone. Use the bank's balance at the end of 2026-10-31, not today's balance." and the confirmation reads "I agreed the statement balance (479,500.00) to the bank's own record of the balance at the end of 2026-10-31, and I checked that the uncleared items on this statement are genuine (…)". The `reconciliation.checked` audit row is unchanged (`confirmed: true`).

## 8. Open questions (assumption in bold; work proceeds on it)
- **TODO (open, AG-033b / PR #25): the "uploaded by the checker" tag (R2, S3 copy line, AC 10) is not wired yet.** This branch returns `checker_attachment_ids` on the reprint when `attachments.bank_statement_id` exists, but no screen, PDF or Excel output renders it. Whichever of PR #25 and this PR merges second must: render " - uploaded by the checker" on the copy line on all three outputs; add `whereNull('removed_at')` to the `checker_attachment_ids` query; keep one copy of the duplicated helpers (`Account::statements()`, the AuditLogController BankStatement subject); keep both rollback lines in the migration tests (12_07, then 12_06, then 12_01). AC 10 is signed off only then.
- **Q1 (product owner)** The project document calls the Viewer / Auditor "read-only". May the Viewer template record a check? **Yes. A check is a sign-off that changes no accounting record, and the Viewer / Auditor is the independent checker named in AG-033 Q4.** If not, drop it from `TEMPLATES['Viewer']`, and only Branch Admins and the Super Admin check by default.
- **Q2 (product owner)** Is a paper "Approved by" line still wanted on the PDF? **No (D1): the Super Admin's month lock is the approval, and the Lock confirm shows unchecked statements.** Re-adding a blank line is a one-line blade change.
- **Q3 (product owner)** Should the Accountant template get the key so that one accountant can check another's statement? **No. Branches with two accountants give it through a custom role.** Segregation (R2) would still stop self-checks.
- **Q4 (accountant, decided: agreed)** A one-person branch cannot check its own statements. Should the preparer be allowed to self-check with a flag? **No. Self-checking is no check. The statement stays "Not checked" until the Super Admin or another key holder checks it.**
- **Q5 (product owner; accountant agrees)** Should the checker be able to record "Queried / not agreed" instead of "Checked"? **No. The checker raises it with the preparer outside the system, and the statement stays "Not checked" until it is corrected (undo and complete again) or checked.**
- **Q6 (accountant, decided: agreed)** Should a posting that makes a check "Re-check needed" notify the checker or the preparer? **No. That would add a hook in every posting path. It is visible on S6 (filter), on the reprint and on the Lock confirm.**
- **Q7 (accountant, decided: agreed)** Re-check or automatic invalidation after late entries? **Re-check needed, with the check kept (D5): the agreed balance is unchanged by a late entry, and only the list of uncleared items changed.**
- **Q8 (product owner)** A backlog row is needed. **The product owner adds AG-033d to the backlog sheet. This spec does not edit the xlsx.**

**Release-note draft.** "Checked by (AG-033d): a user with the new permission 'account.reconcile_check' can record the check of a completed bank reconciliation statement. Open it from Reports > Bank reconciliation statements and click Record check. The person who completed the statement or ticked its lines cannot check it. Compare the balance at the statement date (not today's balance) with the bank's own statement or internet banking, not only with the uploaded copy. Branch Admin roles that hold every permission received the key automatically (see the audit log, Roles). Grant it to the Viewer template and the branch Viewer roles (or another checker role) on the Roles screen. Entries recorded after a check mark the statement 'Re-check needed'. Undoing a statement cancels its check."

## Accounting review
Reviewed against CLAUDE.md, `bank-reconciliation.md` (R3, R6-R8, R10, Accounting review Q4 and finding 4), `reconciliation-statements-list.md` (R2, R3, Q4, accepted risk 3), `controls.md` (R7 locks, audit), `bank-statement-attachment.md` on `origin/feature/bank-statement-attachment` (R4, finding 1, Q3, finding 4) and `PermissionSeeder` (templates). The story posts nothing: double entry, balances, `LedgerService`, inter-branch elimination and report totals are not affected (section 1, R5, AC 28). Nothing is deleted (triggers, FK restrict, no DELETE route, AC 21).

**Verdict: approved for development, subject to MUST finding 1. The text for every finding is already in the body above.**

### Points asked about
- **Segregation (D2, R2): agreed.** Preparer = `completed_by` or any `marked_by` on the frozen lines, blocked for everyone including the Super Admin; a re-check follows the same rule. This is the AG-033 Q4 compensating control done properly. Allowing a checker who recorded entries, with a stored count, is right for small branches (detective, like `self_count`); finding 2 sharpens it. A checker who uploaded the copy is the preferred case and the tag is right (AG-033b finding 1).
- **Key `account.reconcile_check` and templates: agreed.** Not `report.view` (it would make every preparer a checker). Branch Admin and Viewer yes, Accountant and Cashier no, Super Admin yes with R2 applied. Migration: `*` templates and unchanged Branch Admin copies only, audited (as AG-033 Q6 and AG-064). Nobody can grant it without holding it, so an Accountant cannot make themselves a checker. Q1 (Viewer may check): agreed, a check changes no accounting record.
- **Undo cancels (R6): agreed.** The undone statement is superseded, so its check cannot carry over; the checker is notified and the replacement starts "Not checked" and shows on S6 and the Lock confirm. Undo is not blocked by a check, which is right (AG-033 R7 already protects locked months).
- **Late entries mark "Re-check needed" (R4, R5, Q7): agreed.** A late entry dated on or before S leaves the agreed bank balance unchanged (AG-033 R3 identity) and only adds an uncleared item, so the check stays true for what it covered. Counting by line id above `max_entry_id` (voids included) is the same trace rule as AG-033 R8 and AG-033c R3. `max_entry_id` is read under the account row lock, so a concurrent posting cannot slip under it.
- **Withdraw (R7): agreed.** Only the checker or a Super Admin, reason required, kept in the history, preparer notified. Refused in a locked month or closed year for everyone, as my AG-033b Q3 decision: the sign-off the Super Admin relied on at lock must not change without the audited unlock; recording a check after close is allowed because it only adds evidence (R8, tagged "checked after close"). Finding 5 adds the remedy test.
- **Lock confirm information only (D4): agreed**, as AG-033 Q8 and AG-033b finding 4. Making the lock wait for a checker would keep the month open to postings longer, which is the weaker control. Finding 3 records what the Super Admin was shown.
- **Dropping "Approved by" (D1, Q2): agreed.** The project document has approvals only where money moves before posting; for month-end close the Super Admin's review and lock is the approval. A blank line nobody signs is worse than none.
- **Q4 no self-check in a one-person branch: agreed.** A self-check is no check; the Super Admin (owner) is the checker there, and "Not checked" stays visible.
- **Q5 no "Queried" outcome: agreed.** "Not checked" is the conservative state and stays on S6 and the Lock confirm until the preparer corrects (undo and complete again) or someone checks. The checker should raise a difference with the Super Admin as well as the preparer (release note may say so).
- **Q6 no notification on re-check needed: agreed.** The poster already gets the AG-033 late-entry warning at posting time, and S6 (filter), the reprint and the Lock confirm show it.
- **Q7 re-check, not automatic invalidation: agreed** (see above).

### Findings
1. **MUST - What the checker confirms.** The old wording ("compared ... the uncleared items ... with the bank's own record") lets a non-accountant compare with today's internet-banking balance, and uncleared items by definition are not on the bank's record at S. The check is only worth something if the balance at S is agreed to the bank (the AG-033 residual risk is a wrongly typed statement balance) and the uncleared items are vouched to later bank activity or source records. Rule: the dialog hint and the confirmation text in S3 "Record check dialog"; release note. AC 31.
2. **SHOULD - Uncleared items recorded by the checker.** A total "recorded N of M" mixes cleared lines (proved by the bank) with uncleared ones (where an unsupported entry would sit). Store and show `checker_uncleared_count` ("..., K of them uncleared"). R2, schema, `Check`, S3 tags, R9, AC 9.
3. **SHOULD - Lock audit records the unchecked statements.** The `period.locked` row stores `bank_reconciliation_checks` (not-checked and re-check statement ids per branch), so the audit trail shows the Super Admin locked knowing. S2, AC 26.
4. **SHOULD - Re-check audit and V11.** The `reconciliation.checked` row of a re-check carries the superseded check in its old values; V11 also checks `ended_by`/`ended_at` on ended checks and that `superseded_by_id` points to a later check on the same statement. R9, R11, AC 15.
5. **SHOULD - Withdraw in a locked month: remedy test.** Unlock (audited), withdraw, relock, with the three audit rows in order, as AG-033b AC 24. AC 20.

### Accepted risks
1. A Branch Admin holds `user.create` and `role.manage`, so a Branch Admin who completes a statement could set up a second login and check it under that name. This is outside this story; the `user.created` and `role.updated` audit rows, the access alerts and the Super Admin's review at lock are the detective controls. Owners should prefer the Viewer / Auditor or themselves as checker.
2. On existing databases only Branch Admin copies and the Super Admin get the key; the Viewer roles need the Super Admin to grant it (release note), as AG-033 Q6.
