# Spec: Bank reconciliation status per account (overdue reconciliations)

## 1. Goal and scope
**AG-033e** (follow-up to AG-033; not yet a row in `docs/Account-gold_Product_Backlog.xlsx`, Q9): "As an Accountant or checker, I want to see for every bank account when it was last reconciled, how many month-ends it is overdue and whether its last statement is checked, so that month-end close (project document 7.5.1) does not miss an account."

Source: the accountant's request; AG-033c Q3 and accepted risk 2 ("the full overdue list stays out of scope"). AG-033c R9 already prints one sentence on S6: "Not reconciled to 30 Nov 2026: GAL Bank (12002), last statement 31 Oct 2026; ...". That sentence answers "is anything missing?". It does not say how far behind each account is, whether the bank statement is simply not due yet, or whether the last statement was checked (AG-033d).

This story adds **one read-only report**, "Bank reconciliation status", with one row per bank account. It answers "how far behind is each bank account, and is its last statement checked?".

- **It posts nothing and writes nothing.** No `LedgerService` call. It never writes `ledger_entries`, `accounts.current_balance`, `bank_reconciliation_marks`, `bank_statements`, `bank_statement_checks` or settings. The only row it writes is `report.exported` on an export.
- **It depends on AG-033d (PR #28) and AG-033c (PR #27).** It reads the check status of AG-033d. It merges after both.
- **No duplicate of the AG-033c line.** The S6 coverage sentence keeps its wording and exports. S6 gets one link next to it, "Status per account", that opens this report. Both use the same in-scope rule (R3), so on the same day the accounts S6 names are exactly the accounts this report shows as Due or Overdue (AC 14).
- **One rule change on S6 (Accounting review finding 1).** A closed account with a nil balance is no longer dropped until its final month is reconciled. AG-033c R9 and R3 here change together in this story (S6 section below, AC 21).

**In scope:** the new report (screen, API, PDF and Excel export), a Reports hub card, the S6 link, the closed-account change to the AG-033c R9 in-scope rule (finding 1), and a fixed grace period of 10 days.

**Out of scope, and why:**
- **Configurable grace days.** The grace period is a constant in this story (Q1). A Settings field means a new setting key, validation, a `settings.updated` audit and tests. It can follow in a later story without changing anything here.
- **Frequency per account** (monthly or quarterly). Every bank account is expected monthly (Q2).
- **Dashboard tile** (Q3), **bell reminder** (Q4) and **scheduled email** (Q5). Each one needs its own recipients, scheduling or dedupe rules. This report is the base they would all link to. `ReportSchedule::REPORTS` is unchanged.
- **Lock confirm.** No change. It already shows "KDY Bank: last reconciled to 30 Sep 2026" (AG-033 S4) and the unchecked and re-check lines (AG-033d D4).
- **Statement-level detail.** "Changed since completion", late entries and earlier unchecked statements stay on S6. This report shows the latest completed statement only.
- **An "as at" date in the past.** The report is always as at today.
- **Wallet and cash accounts** (AG-033 Q1). A responsible-person field on accounts (Q7).

## 2. Personas and permissions
**No new key.** These are the real keys from `PermissionSeeder`. The report uses the same keys as S6, so a row link never leads to a 403.

| Need | Key |
|---|---|
| Open the report, use the filters | `report.view` |
| Export PDF / Excel | `report.view` + `report.export` |
| "Reconcile" link on a row | `account.reconcile` (the link is hidden without it) |

| Template | `report.view` | `report.export` | `account.reconcile` | Sees | Exports |
|---|---|---|---|---|---|
| Branch Admin (`*`) | yes | yes | yes | own branch | yes |
| Accountant | yes | yes | yes | own branch | yes |
| Viewer | yes | no | no | own branch | no (prints from the browser) |
| Cashier | no | no | no | no (403, no hub card) | no |
| Super Admin | all | all | all | any branch, All mode across branches | yes |

Roles are unchanged. Nobody may grant a key they do not hold.

## 3. User flows
- **F1 Accountant at month-end (ACC, KDY, 15 Dec 2026).** ACC opens Reports > "Bank reconciliation status". The summary line reads "Overdue 2 · Due 0 · Up to date 1 · Not required 2". The rows are sorted with the worst first:
  - KDY Bank 5 shows "Overdue 3 months" (last statement 15 Sep 2026).
  - KDY Bank 2 shows "Never reconciled · overdue 1 month".
  - KDY Bank shows "Up to date" (last statement 30 Nov 2026 by BA) with the check tag "Not checked".
  - ACC clicks "Reconcile" on KDY Bank 5, and `/accounts/:id/reconcile` (S1) opens.
- **F2 Viewer follows up (VW, KDY).** VW sees the same rows, without "Reconcile" links and without export buttons. VW clicks "Statements" on KDY Bank, and S6 opens filtered to that account. There VW records the check (AG-033d).
- **F3 Within the grace period (10 Dec 2026).** The November bank statement may not have arrived yet. GAL Bank (last 31 Oct 2026) shows "Due by 10 Dec 2026", not "Overdue". On 11 Dec 2026 it shows "Overdue 1 month".
- **F4 Super Admin, All branches.** The Branches multi-select defaults to every branch (reports R1). Rows show a Branch column. MAIN Main Account appears, for example "Never reconciled · overdue 4 months". A row link opens S1 or S6 for that account's branch, as S6 rows already do in All mode.
- **F5 Super Admin, branch mode.** With X-Branch-Id KDY, the Super Admin sees KDY only.
- **F6 From S6.** Next to the coverage sentence, the link "Status per account" opens this report with the same Branches selection.
- **F7 Export (ACC).** "Export PDF" or "Export Excel" exports every row that matches the filters, with the reports header. `report.exported` is audited.
- **F8 Branch switch.** Switching branch reloads the report for the new branch. A user without `report.view` there leaves `/reports` (existing shell rule).

## 4. Screens
### S7 Bank reconciliation status `/reports/reconciliation-status`
Guard: `permissionGuard('report.view')`.

Hub card, right after "Bank reconciliation statements":
- title "Bank reconciliation status";
- question "Which bank accounts are behind with reconciliation, and is the last statement checked?".

**Filters.** There is an Apply button. The values are kept in the URL query.

| Field | Type | Required | Validation / default |
|---|---|---|---|
| Branches | multi-select | All mode only | reports R1. Default: every branch. Hidden in branch mode |
| Status | select | yes | All (default) / Overdue / Due / Up to date / Not required |

"Clear filters" resets the defaults.

**Above the table:**
- **Basis line:** "As at 15 Dec 2026. A month's bank reconciliation is due within 10 days after month-end. Overdue counts the month-ends after the last completed statement, up to 30 Nov 2026. Undone statements do not count."
- **Summary line:** "Overdue N · Due N · Up to date N · Not required N". It counts every row in the branch scope. The Status filter does not change it.
- **Check line** (Accounting review finding 3): "Latest statement not checked N · Re-check needed N", counted over the same rows (latest completed statement only, R7), shown in amber when either is above 0. The Status filter does not change it.
- **Export buttons:** shown only when `auth.can('report.export')`.

**Table** (desktop; the sort order is in R6; no paging):

| Column | Content |
|---|---|
| Branch | Branch code. All mode only |
| Account | "KDY Bank (11002)", plus "(inactive)" when `status = closed` (the S6 label) |
| Status | Tags:<br>- red "Overdue N month(s)";<br>- red "Never reconciled · overdue N month(s)";<br>- amber "Due by 10 Dec 2026", or "Never reconciled · due by 10 Dec 2026";<br>- green "Up to date";<br>- grey "Not required: no entries up to 30 Nov 2026" or "Not required: inactive, nil balance, reconciled to its last month (30 Jun 2026)" (the date is F, R2) |
| Months overdue | A number. 0 is shown as "0". Blank when the status is Not required |
| Last reconciled | The statement date of the latest completed statement, in the date-format setting, with a second line "by <name + controls R10 tag>". "Never" when there is none |
| Last check | For the latest completed statement (AG-033d texts):<br>- green "Checked by VW, 2026-12-10";<br>- amber "Re-check needed (N since check)";<br>- amber "Not checked";<br>- blank when there is no completed statement |
| (actions) | "Statements" links to `/reports/reconciliation-statements?account_ids=<id>` (in All mode, also `branches=<branch id>`). "Reconcile" links to `/accounts/<id>/reconcile`, only with `account.reconcile`; in All mode it is shown only for MAIN accounts, because S1 is a branch-mode screen (Q8) |

**States**
- Loading: skeleton rows.
- Empty because there is no bank account in scope: "No bank accounts in this branch."
- Empty because of the Status filter: "No bank accounts with this status." and "Clear filters".
- Errors: 403 shows the API `message` in `.alert`. A 422 shows the message under the Status field. A network error shows "Could not load the reconciliation status. Try again." with Retry.

**390px layout.**
- Filters fold into a "Filters (n)" button. It opens a full-width sheet with Apply and Clear at the bottom, each at least 44px tall.
- The summary line wraps.
- Each row becomes a card:
  - line 1: Account (with the branch code in All mode) and the Status tag, right-aligned, wrapping under the account when it does not fit;
  - line 2: "Last reconciled 30 Nov 2026 by BA" or "Never reconciled";
  - line 3: the Last check tag;
  - line 4: "Statements" and "Reconcile" as buttons, each at least 44px tall.
- Export buttons are full width. There is no horizontal page scroll at 360px or 390px.

### S6 Bank reconciliation statements (AG-033c, one change)
After the coverage sentence, add the link "Status per account". It goes to `/reports/reconciliation-status` and carries the current `branches` in All mode. It is shown whether or not the sentence names any account.

**AG-033c R9 in-scope rule (Accounting review finding 1).** `BankReconciliation::coverage` uses the R3 rule below with `reconciled_to` as the cut-off: an account that is not active and has a 0.00 book balance at the cut-off is left out only when its latest completed statement is on or after F (the month-end of its last line dated on or before the cut-off). Otherwise it is named as today. The sentence wording and the S6 exports are unchanged. AG-033c AC 20's last case changes: the new inactive account with lines and no statement is now named "never reconciled" until a statement to the month-end of its last line is completed; update `test_ac20_coverage_line`. A note under AG-033c R9 records the amendment.

## 5. API
**New:** `GET /v1/reports/reconciliation-status`
- Middleware `perm:report.view`, inside the `branch` group next to `reports/reconciliation-statements`.
- `ReportController::reconciliationStatus` calls the new `BankReconciliation::statusParams(array $input)` and `BankReconciliation::status(ReportParams $p)`.
- `ReportParams` gets `from = to = as_at = BusinessDate::today()`.

Request (query):

| Param | Rule |
|---|---|
| `branches[]` | `ReportService::branchScope`. In branch mode: absent or the active branch, otherwise 403 "You do not have access to branch GAL.". In All mode: any branch ids, default all. An unknown id gives 422 "Unknown branch." |
| `status` | `in:all,overdue,due,up_to_date,not_required`, default `all`. Otherwise 422 "Choose a valid status." |

Any other parameter (for example `as_at`, `from` or `to`) is ignored.

Response 200:
```
{ meta: {…as other reports: company, currency, from = to = as_at, branches, all_branches, subset…},
  provisional: false,            // always: a status as at today, not period figures
  as_at: 'Y-m-d', grace_days: 10,
  due_month_end: 'Y-m-d',        // L, R2
  overdue_through: 'Y-m-d',      // E, R2
  basis: string,                 // the S7 basis line
  filters: { status },           // the Status filter applied
  summary: { overdue, due, up_to_date, not_required,
             not_checked, recheck },                // whole scope, ignores `status`; check counts per R7
  data: [{ account:{id,code,name,status}, branch:{id,code,is_main},   // is_main: "Reconcile" in All mode (Q8)
           status: 'overdue'|'due'|'up_to_date'|'not_required',
           not_required_reason: 'no_entries'|'closed_nil'|null,
           never_reconciled: bool,
           months_overdue: int|null,          // null when not_required
           final_month_end: 'Y-m-d'|null,     // F (R2), only when status = closed
           due_by: 'Y-m-d'|null,              // only when status = due
           last_statement: { id, statement_date, completed_by:{id,name,tag}, completed_at } | null,
           check_status: 'checked'|'recheck'|'not_checked'|null,
           check: { id, checked_by:{id,name,tag}, checked_at, late_since_check } | null,
           recheck_needed: bool }] }
```
There are no money fields.

Errors:
- 401.
- 403 "Missing permission: report.view".
- 403 for a foreign branch (above).
- 422 for an unknown branch or an invalid status (above).

**Export (reuse):** `POST /v1/reports/reconciliation-status/export` through the existing `reports/{name}/export` route (`perm:report.view,report.export`). The body is the same filters plus `format` (`pdf|xlsx`).
- `ReportService::NAMES` gains `reconciliation-status`.
- `ReportController::export` routes it to `BankReconciliation::statusParams`.
- `ReportExporter` gains the title "Bank reconciliation status". The export is always synchronous: add the name to the existing `in_array($name, ['cash-flow', 'bank-reconciliation', 'reconciliation-statements'])` exclusion, because there is one row per bank account.
- **PDF:** blade `reports.pdf.reconciliation-status`, landscape, with the S7 columns except the actions.
- **Excel:** one sheet with the columns Branch, Account code, Account, Account status, Status (the S7 tag text), Months overdue (number), Due by (date cell), Last statement date (date cell), Last completed by, Check status (Checked / Re-check needed / Not checked / blank), Checked by, Checked at (date-time cell), Re-check needed (Yes/No).
- **Header** (PDF and Excel): the reports header (company, title, branches, period "As at <as_at>", printed by/at), the basis line, the summary line, the check line and "Filters: Status: <label>". There is no "Amounts in LKR" line and no PROVISIONAL marker.
- Free text (account names, user names) goes through the existing Excel formula-injection prefix.
- File name: `reconciliation-status_<as_at>.<ext>`.
- Audit `report.exported` (name, format, rows), as for the other reports.

**Unchanged:**
- `GET /v1/reports/reconciliation-statements`, including `coverage`;
- `GET /v1/period-locks/review`;
- `ReportSchedule::REPORTS`, so a schedule with `reconciliation-status` is refused by the existing validation.

## 6. Business rules
- **R1 Branch isolation.**
  - `Account` is `BranchScoped`. In branch mode the query uses the scope, plus an explicit `branch_id IN (active branch)`.
  - In All mode, `withoutGlobalScopes()` is used only after `branchScope()` has resolved the allowed set. Then `accounts.branch_id IN set` is applied explicitly. This is the same as AG-033c R1 and `listAccounts`.
  - Statements, checks and `people()` are read only for accounts in that set (`people()` is called per branch).
  - A branch user can never see another branch's account, even through the other side of a transfer.
- **R2 Dates.**
  - T = `BusinessDate::today()` (Asia/Colombo).
  - G = `BankReconciliation::RECONCILE_GRACE_DAYS` = 10.
  - **L (due month-end)** = `reconciledTo(T)`: the last month-end on or before T. This is the same cut-off S6 uses with its default To = today.
  - **E (overdue through)** = `reconciledTo(T − (G + 1) days)`. A month-end M becomes overdue on day M + G + 1. November 2026 is due by 10 Dec 2026 and overdue from 11 Dec 2026.
    - T = 2026-12-10 gives E = 2026-10-31.
    - T = 2026-12-11 gives E = 2026-11-30.
  - **due_by** = L + G days.
  - **F (final month-end)**, for an account with `status = closed` only: the month-end on or after its last `ledger_entries` line dated on or before L (voided lines and contras included). F ≤ L always. It is null for other accounts.
- **R3 In scope (the same rule as AG-033c R9, cut-off L).** Every `type = bank` account in the branch set is a row, whatever its status. A row is **required** when it has at least one `ledger_entries` line dated on or before L (`whereDate`), and it is either `active` or has a non-zero book balance at L (`bookCents`). Otherwise the row is `not_required`:
  - `no_entries`: no line dated on or before L. This covers a new account with no opening funds yet, and an account whose first entry is after L.
  - `closed_nil`: `status = closed`, the book balance at L is 0.00, **and** the latest completed statement is on or after F (the final month is reconciled; Accounting review finding 1). A closed nil-balance account whose final month is not reconciled stays required, so the movements that emptied it are agreed to the bank before it leaves the report and S6.
  - An account with entries but no recent activity is still required. The bank still issues a statement, and charges or interest may be on it.
- **R4 Months overdue (required rows).**
  - `last` = the latest `completed` statement date of the account (`lastReconciledDates`). Undone statements never count.
  - `start` = `last`, or, when the account was never reconciled, the day before its first ledger line (`MIN(entry_date)`, voided lines included).
  - `stop` = E, or for a closed account with a 0.00 book balance at L, the earlier of E and F (no month after the final one is expected).
  - `months_overdue` = the number of month-ends M with `start` < M ≤ `stop`. It is 0 when `stop` ≤ `start`.
  - A statement dated mid-month covers only the month-ends up to its date. For example, `last` 2026-09-15 with E 2026-11-30 gives 3 (30 Sep, 31 Oct, 30 Nov).
- **R5 Status** (first match wins):
  1. `not_required` (R3);
  2. `up_to_date` when `last` ≥ L (a closed nil-balance row with `last` ≥ F is already `not_required`);
  3. `overdue` when `months_overdue` ≥ 1;
  4. `due` otherwise, with `due_by` set.

  `never_reconciled` = `last` is null. Statuses 3 and 4 are exactly the accounts S6's default coverage sentence names.
- **R6 Sort order.**
  1. Status: overdue (by `months_overdue`, highest first), then due, then up to date, then not required.
  2. MAIN first, then branch code.
  3. Account code, then name.
- **R7 Last check.**
  - Taken from the latest completed statement only: `ReconciliationChecks::statusMap` and `lateSinceMap`, batched over the rows, never one query per row.
  - `recheck_needed` = (`check_status` = `recheck`).
  - A "Re-check needed" or "Not checked" status does not change the row's Status. The two are shown separately.
  - Older unchecked statements of the same account are not shown here. They are on S6 with Checked = Not checked.
- **R8 Volume.** The report has one row per bank account (about 5 per branch), so there is no paging and no cap. It runs a fixed number of queries whatever the number of accounts:
  - bank accounts;
  - last completed statement per account;
  - first line date, last line date on or before L, and book balance at L per account (one grouped query);
  - statements with their checks.

  The PDF cap (`PDF_EXPORT_CAP`, 1,000) applies as for other reports.
- **R9 Read only, no audit on view.** Viewing writes nothing. An export writes only `report.exported`. Nothing is posted, so `ledger:verify` is unaffected.
- **R10 Edge cases.**
  - **Closed account with a balance at L** (it was emptied and closed after L; closing needs a zero balance, `AccountController` "Only accounts with a zero balance can be closed."): required at this L like an active account. From the next month-end its balance is 0.00, and it stays required until it is reconciled to F, the month in which it was emptied (R3).
  - **Closed account reopened** (`status` back to active): it is required again under R3.
  - **Latest statement undone:** `last` falls back to the previous completed statement. The row can turn from Up to date to Overdue. The Last check shows that earlier statement's check.
  - **Statement completed after the month was locked** (AG-033 Q3): it counts normally. Locks never hide anything here.
  - **Deactivated or removed preparer or checker:** the name gets the controls R10 tag.
  - **T is itself a month-end** (31 Dec): L = 31 Dec. Every account not yet reconciled to that day shows "Due by 10 Jan 2027", which is the same as S6 that day (Q10).
  - **Super Admin in All mode** sees MAIN Main Account (a `type = bank` account) like any other bank account. "Never reconciled" is expected until it is reconciled (AG-033c accepted risk 1).

## 7. Acceptance criteria
**Setup** (as AG-033c and AG-033d, section 7). Unless an AC says otherwise:
- `BusinessDate` is frozen at 2026-12-15, so L = E = 2026-11-30.
- KDY Bank: ST1 (2026-10-31, completed by ACC, checked by VW on 2026-12-10) and ST3 (2026-11-30, completed by BA, not checked).
- GAL Bank: ST4 (2026-10-31, completed by GACC, not checked).
- MAIN Main Account: only line is a capital line dated 2026-08-01; no statement.
- KDY Bank 2 (11003): first line 2026-11-05; no statement.
- KDY Bank 3 (11004): active; no lines.
- KDY Bank 4 (11005): lines in June 2026, nil balance since 2026-06-30, statement 2026-06-30 completed and checked by VW, `status = closed`.
- KDY Bank 5 (11006): active, with lines; last completed statement 2026-09-15, completed by ACC, not checked.
- KDY Cash: no change (a cash account).
- Users: ACC (Accountant KDY), VW (Viewer KDY), CSH (Cashier KDY), BA (Branch Admin KDY), GACC (Accountant GAL), SA.

1. Given ACC (KDY), when ACC opens `/reports/reconciliation-status`, then the response is 200 with `as_at` 2026-12-15, `grace_days` 10, `due_month_end` 2026-11-30 and `overdue_through` 2026-11-30. The rows, in this order, are:
   - KDY Bank 5: overdue, 3;
   - KDY Bank 2: overdue, 1, `never_reconciled` true;
   - KDY Bank: up_to_date, 0;
   - KDY Bank 3: not_required, `no_entries`;
   - KDY Bank 4: not_required, `closed_nil`.

   KDY Cash, GAL Bank and MAIN Main Account are not listed. The summary reads "Overdue 2 · Due 0 · Up to date 1 · Not required 2". The check line reads "Latest statement not checked 2 · Re-check needed 0" (KDY Bank ST3 and KDY Bank 5's statement of 2026-09-15; KDY Bank 4's statement of 2026-06-30 is checked by VW in the setup, so not_required rows add nothing here).
2. Given AC 1, then the screen shows:
   - KDY Bank 5: "Overdue 3 months", Last reconciled "15 Sep 2026";
   - KDY Bank 2: "Never reconciled · overdue 1 month", Last reconciled "Never", Last check blank;
   - KDY Bank: "Up to date", Last reconciled "30 Nov 2026" by BA, Last check "Not checked";
   - KDY Bank 3: "Not required: no entries up to 30 Nov 2026";
   - KDY Bank 4: "KDY Bank 4 (11005) (inactive)" with "Not required: inactive, nil balance, reconciled to its last month (30 Jun 2026)" and a blank Months overdue.
3. Given `BusinessDate` 2026-12-10 (E = 2026-10-31), then:
   - in All mode for SA, GAL Bank is due with `due_by` 2026-12-10 and the tag "Due by 10 Dec 2026";
   - for ACC, KDY Bank 2 is due ("Never reconciled · due by 10 Dec 2026", months 0) and KDY Bank 5 is overdue 2.

   Given 2026-12-11, then GAL Bank is "Overdue 1 month".
4. Given `BusinessDate` 2026-12-31, then L = 2026-12-31, and KDY Bank is due with "Due by 10 Jan 2027".
5. Given BA undoes ST3, when ACC reloads, then KDY Bank is "Overdue 1 month", Last reconciled is 31 Oct 2026 by ACC, and Last check is "Checked by VW, 2026-12-10" (ST1).
6. Given VW checks ST3, and then ACC records an expense of 2,000.00 from KDY Bank dated 2026-11-20 (not ticked), then KDY Bank stays "Up to date", `check_status` is `recheck`, `recheck_needed` is true, and Last check shows "Re-check needed (1 since check)".
7. Given KDY Bank 4 is reopened (`status = active`) with a nil balance, then it is overdue with months 5 (Jul to Nov). Given KDY Bank 3 receives an allocation dated 2026-12-02, then on 2026-12-15 it is still not_required `no_entries`. On 2027-01-11 it is "Never reconciled · overdue 1 month".
8. Given `status=overdue`, then only KDY Bank 5 and KDY Bank 2 are listed, and `summary` is the same as in AC 1. Given `status=late`, then the response is 422 "Choose a valid status.". Given `status=due` on 2026-12-15, then there are no rows and the screen shows "No bank accounts with this status.".
9. Given SA in All mode with no `branches[]`, then MAIN Main Account is listed first among the overdue rows: overdue 4 (Aug to Nov), `never_reconciled` true. GAL Bank is overdue 1, and the Branch column is shown. With `branches[]=<GAL>`, only GAL Bank is listed. Given SA with X-Branch-Id KDY, then only KDY accounts are listed.
10. Given ACC (KDY) with `branches[]=<GAL>`, then the response is 403 "You do not have access to branch GAL.". Given GACC (GAL), then only GAL Bank is listed.
11. Given CSH, when CSH calls the API, then the response is 403 "Missing permission: report.view", and the hub has no "Bank reconciliation status" card.
12. Given VW, then the report loads, no "Reconcile" link and no export buttons are shown, and "Statements" on KDY Bank opens S6 with `account_ids=<KDY Bank>`, listing ST3, ST2 and ST1. Given ACC, then "Reconcile" on KDY Bank 5 opens `/accounts/<id>/reconcile`.
13. Given ACC is deactivated, when VW opens the report, then KDY Bank 5's Last reconciled shows "by ACC" with the deactivated tag, and the row is unchanged otherwise.
14. Given the same day and branch scope, then the accounts named in S6's default coverage sentence are exactly the rows with status due or overdue here (checked on 2026-12-10, 2026-12-15 and 2026-12-31, in branch and All mode). S6 shows the link "Status per account", which opens S7 with the same branches. The S6 coverage text and S6 exports are unchanged.
15. Given ACC exports to PDF and to Excel with default filters, then:
    - both files have the reports header with "As at 15 Dec 2026", the basis line, the summary line and "Filters: Status: All", with no "Amounts in LKR" line and no PROVISIONAL marker;
    - the rows and texts are the same as on the screen, in the same order;
    - Excel has Months overdue as a number, Due by, Last statement date and Checked at as date cells, and Re-check needed as Yes/No;
    - the file name is `reconciliation-status_2026-12-15.<ext>`;
    - one `report.exported` row is written;
    - an account named "=SUM(A1)" is written to Excel as text.

    Given VW, the export returns 403.
16. Given any call to the report or its export, then `ledger_entries`, `accounts.current_balance`, `bank_reconciliation_marks`, `bank_statements`, `bank_statement_checks` and `settings` are unchanged, no audit row except `report.exported` is written, and `ledger:verify` exits 0.
17. Given 20 branches with 5 bank accounts each, when SA loads All mode, then the number of SQL queries is the same as for 1 branch with 1 account (query-count test), and every account has a row.
18. Given a 390px viewport with 12 rows, then:
    - there is no horizontal page scroll at 360px or 390px;
    - each row is a card with the Status tag, the last reconciled line and the Last check tag;
    - the action buttons and the filter sheet's Apply / Clear are at least 44px tall;
    - the summary and the tags wrap.
19. Given VW in KDY switches to GAL, where VW has `report.view`, then the report reloads with GAL accounts. Given a branch where VW has no `report.view`, then the app leaves `/reports`.
20. Given a schedule create request with report `reconciliation-status`, then the existing validation refuses it (the report is not in `ReportSchedule::REPORTS`).
21. (Accounting review finding 1) Given KDY Bank 6 (11007): last completed statement 2026-10-31; on 2026-11-20 its whole balance is transferred to KDY Bank and the account is closed (`status = closed`, 0.00). Then:
    - on 2026-12-15 it is overdue 1 (November), `final_month_end` 2026-11-30, and S6's default coverage sentence names it ("last statement 31 Oct 2026");
    - on 2026-12-05 it is "Due by 10 Dec 2026";
    - after a statement dated 2026-11-30 (balance 0.00) is completed, it is not_required `closed_nil` with "reconciled to its last month (30 Nov 2026)", and S6 no longer names it; on 2027-02-15 it is still not_required;
    - if that statement is then undone, on 2027-01-15 it is overdue 1, not 2 (November only, because `stop` = F = 2026-11-30).
    Given the same account but emptied on 2026-12-03 instead (balance at 2026-11-30 non-zero), then on 2026-12-15 it is required as an active account would be, and on 2027-01-15 (L = 2026-12-31, balance 0.00, F = 2026-12-31) it is still required until reconciled to 2026-12-31.

## 8. Open questions (assumption in bold; work proceeds on it)
- **Q1 (product owner)** Should the grace days be configurable? **No, not in this story. The grace period is a fixed 10 days, `BankReconciliation::RECONCILE_GRACE_DAYS`, shown in the basis line.** It could later be a company-wide Settings field (Super Admin, 0 to 31, audited by `settings.updated`) that changes only G.
- **Q2 (accountant / product owner)** Do some bank accounts need reconciling only quarterly? **No. Every bank account is expected monthly (project document 7.5.1).** A frequency per account would be a separate story.
- **Q3 (product owner)** Should there be a dashboard tile ("Bank reconciliations overdue: N")? **Out. It is the natural next story, as a count from R5 that links to S7, for holders of `report.dashboard` + `report.view`.**
- **Q4 (product owner)** Should there be a bell reminder when an account becomes overdue? **Out. It needs a daily command, recipients (holders of `account.reconcile` in the branch?) and dedupe per account and month.**
- **Q5 (product owner)** Should this report be added to scheduled reports (for example a monthly email on the 11th)? **Out. `ReportSchedule::REPORTS` is unchanged (AC 20).**
- **Q6 (accountant, decided)** Must a closed account with a nil balance at L still have its final month (the month it was emptied) reconciled? ~~This story reuses the AG-033c R9 rule.~~ **Accountant: yes. R3 and AG-033c R9 change together in this story (Accounting review finding 1, AC 21).**
- **Q7 (product owner)** Who is the "owner" of an account? **There is no owner field. The branch owns the account, and the row shows the branch and the user who completed the last statement.** A responsible-person field on accounts is out of scope.
- **Q8** In All mode, can "Reconcile" open S1 for a branch account? **No. S1 is a branch-mode screen, so the link is shown only for MAIN accounts in All mode. The Super Admin switches to the branch to reconcile.** "Statements" works in every mode.
- **Q9 (product owner)** A backlog row is needed. **The product owner adds AG-033e to the backlog sheet. This spec does not edit the xlsx.**
- **Q10 (accountant, decided: accepted)** On the last day of a month, every account becomes "Due" for that same day. **Accepted, to match S6's coverage cut-off for To = today.** The alternative (L = the last month-end strictly before today) would make S6 and S7 differ on that one day.
- **Q11 (product owner)** The account status is `closed`, but S6 labels it "(inactive)". **S7 uses "(inactive)" too, so the two screens match.** Renaming the label on both screens is a separate change.

## Accounting review
Reviewed against CLAUDE.md, `reconciliation-statements-list.md` (R9, AC 20, sign-off and accepted risks), `reconciliation-checked-by.md` (D2, R4, R5), `bank-reconciliation.md`, `controls.md` (R10), `PermissionSeeder::TEMPLATES`, and the code on 3e67b55: `BankReconciliation::reconciledTo`, `coverage`, `lastReconciledDates`, `ReconciliationChecks::statusMap`, `lateSinceMap`, and the zero-balance rule for closing an account (`AccountController`, "Only accounts with a zero balance can be closed."). The report posts nothing, has no money fields and adds no permission. Double entry, balances, period locks, inter-branch elimination and report totals are not affected.

**Verdict: approved for development, subject to MUST finding 1. The text for all findings is already folded into the body (section 1, S7, S6, section 5, R2, R3, R4, R5, R8, R10, AC setup, AC 1, 2, 21, Q6, Q10).**

### Answers to the BA's questions
- **Q6: yes, the final month must be reconciled.** The month in which a bank account is emptied and closed is the month with the most risk: the closing transfer or cheque, the last bank charges and interest, and any amounts still in transit. If the account drops out when its book balance reaches 0.00, then someone can move the balance out, close the account, and the movements of that last month (and of any earlier unreconciled months) are never agreed to the bank. Under the current AG-033c R9 rule this already happens a month after closing: an account emptied in December and closed is, on 15 January (L = 31 Dec, nil, closed), "not required", although December was never reconciled. The rule is now: a closed nil-balance account leaves the report and S6 only once its latest completed statement is on or after F, the month-end of its last line. S6 and S7 change together, so they never disagree (finding 1).
- **Q10: acceptable.** On the last day of a month the statement for that day cannot exist yet, so "Due by 10 <next month>" is the honest answer. It is amber (information), not red, it does not count as overdue, and it matches S6's coverage sentence on that day. No change.

### Other points checked
- **Grace period, 10 days fixed: fine.** Bank statements and internet banking extracts are available within the first few working days in Sri Lanka, and 10 calendar days fits a normal month-end close. A constant shown in the basis line is better than a setting nobody audits. Configurable grace (Q1) can follow.
- **Months-overdue counting: correct.** Counting month-ends after the latest completed statement up to E gives the number of unreconciled month-ends. A mid-month statement covers only up to its date (R4 example). Undone statements never count. For a never-reconciled account, starting the day before the first line means the month of the first line is the first one due, which is right. Counting from the latest statement only is acceptable: a bank reconciliation agrees the cumulative balance, so a reconciliation to 30 Nov also covers an earlier skipped month (AG-033c accepted risk 2 still stands for evidence per month).
- **Account cases: fine apart from finding 1.** New with no lines: not required, but still listed as a grey row, so it is visible. Dormant with entries: required, correct, because the bank still charges and credits interest and an inactive account is where an unnoticed movement sits. Reopened: required again, counted from its last statement; "overdue 5 months" for KDY Bank 4 overstates the work (one reconciliation to 30 Nov clears it), but reopening a closed bank account is an event a reviewer should see, so accepted. Closed with a balance at L: required (R10 text clarified). Never reconciled: correctly red once the first month-end passes the grace period. MAIN Main Account "never reconciled" in All mode is the intended control (AG-033c accepted risk 1).
- **Match with AG-033c R9: yes, with the finding 1 change applied to both.** E, L and the in-scope rule are the same as S6's coverage for To = today, and AC 14 tests it on three days in both modes.
- **AG-033d check status: correct use.** The check is the compensating control for self-reconciliation, so it is right to show it next to the status and not to fold it into the status (an unchecked statement is still a reconciliation). Using the latest completed statement only is acceptable, because a check of the latest statement covers the cumulative balance and S6 keeps the "Checked = Not checked" filter for older statements. The summary should also count them (finding 3).
- **Permissions: fine.** `report.view` to see it and `report.view` + `report.export` to export, the same as S6, so no row link gives a 403. The report shows no amounts. Viewer sees and follows up through S6 (where it can record the check), Cashier has no access, and "Reconcile" needs `account.reconcile`. No template change.
- **Out of scope: fine.** Quarterly frequency, configurable grace, past "as at" date (the export is the dated evidence), wallet and cash accounts, and the responsible-person field can all wait. See finding 4 on reminders.

### Findings
1. **MUST - A closed account with a nil balance stays required until its final month is reconciled (R3, AG-033c R9, Q6).**
   - Rule: `closed_nil` (not required) needs `status = closed`, a 0.00 book balance at L, **and** a latest completed statement on or after F, where F = the month-end on or after the account's last line dated on or before L. Until then the row is required, and `months_overdue` counts month-ends up to the earlier of E and F.
   - The same rule applies in `BankReconciliation::coverage` (S6), so AC 14 still holds. AG-033c AC 20's last case and `test_ac20_coverage_line` change accordingly.
   - F comes from the same grouped query as the first line date and the balance (R8), so the query count does not grow.
   - Acceptance criteria: AC 21, AC 2 (KDY Bank 4 text), AC 14.
2. **SHOULD - Clarify the "closed with a balance at L" case (R10).** Closing needs a zero balance, so this is only an account emptied and closed after L. The old text ("until a statement on or after L") would have let it drop out the next month. The R10 text now points to R3 and F. AC 21 second part.
3. **SHOULD - Count unchecked latest statements in the summary.** Add the check line "Latest statement not checked N · Re-check needed N" (screen, PDF, Excel, `summary.not_checked`, `summary.recheck`), so a checker sees at a glance how much is waiting. Status is still not changed by the check. AC 1.
4. **SHOULD (product owner) - A reminder is the next story.** A report is a detective control only if someone opens it. I recommend the bell reminder (Q4) or the monthly email on the 11th (Q5) next, to holders of `account.reconcile` and of `account.reconcile_check` in the branch, deduplicated per account and month. Not blocking for this story.

Fine as specified: read only with `report.exported` as the only write (R9, AC 16), branch isolation (R1), the sort order, the batched check status (R7, AC 17), the export header without "Amounts in LKR" or PROVISIONAL, and the formula-injection prefix.

## Accountant sign-off
Checked on head 1b66fd5 (build e06ef04, fixes 1b66fd5) against the Accounting review above, by reading `git diff feature/reconciliation-checked-by...HEAD`, the code and the tests. I did not run the tests myself; the developer reports `php artisan test` and MySQL green, and the QA report has all 21 ACs passing.

**Findings**
1. **MUST, closed nil-balance account until its final month is reconciled: DONE.**
   - There is one rule, `BankReconciliation::notRequired()`. It returns `closed_nil` only when the account is not active, its book balance at the cut-off is 0, and its latest completed statement is on or after `monthEnd(last line on or before the cut-off)` (F).
   - S7 `status()` and S6 `coverage()` both call it, with the same `lineSummary()` grouped query. So S6 and S7 cannot drift apart.
   - `months_overdue` stops at `min(E, F)` for a closed nil account.
   - Tests:
     - `test_ac21_closed_account_until_its_final_month_is_reconciled`: overdue 1 on 15 Dec; due on 5 Dec; `closed_nil` after the 30 Nov statement; still not required on 15 Feb; after undo, overdue 1 and not 2 on 15 Jan.
     - `test_ac21_closed_after_the_cut_off_stays_required_until_reconciled_to_its_final_month`.
     - `test_ac14_same_accounts_as_the_s6_coverage_sentence` now includes KDY Bank 6, on three dates, in branch and All mode.
   - AG-033c `test_ac20_coverage_line` was updated, and the amendment note is under AG-033c R9 (`reconciliation-statements-list.md`).
2. **SHOULD, R10 "closed with a balance at L": DONE.** It is required like an active account. After L moves on it stays required until it is reconciled to F (second AC 21 test: overdue 2 on 15 Jan; a 30 Nov statement at 0.00 is refused; the 31 Dec statement makes it `closed_nil`).
3. **SHOULD, check line: DONE.**
   - `summary.not_checked` and `summary.recheck` are counted over all rows in scope from the latest completed statement, and the Status filter does not change them.
   - The text "Latest statement not checked N · Re-check needed N" is on screen (amber when above 0), in the PDF (amber) and in the Excel header.
   - Tests: `test_ac1_rows_order_summary_and_check_counts`, `test_ac15_pdf_export`, `test_ac15_excel_export`.
   - The check does not change the row's Status (`test_ac6_recheck_does_not_change_the_status`).
4. **SHOULD (product owner), reminder story: NOT DONE, out of scope as agreed.** This is still my recommendation for the next story (bell or the monthly email on the 11th). It does not block this one.

**Other points verified**
- **Months-overdue counting is correct.** `monthEndsBetween()` counts the month-ends M with start < M <= stop, where start is the last completed statement, or the day before the first line for a never-reconciled account. A mid-month statement counts correctly (2026-09-15 to 2026-11-30 gives 3), and so does a first line on the 1st (MAIN gives 4, Aug to Nov). Leap February also counts correctly (`test_r4_month_counting_helpers`). `lastReconciledDates` / `latestCompleted` read completed statements only, so undone statements never count (`test_ac5_latest_statement_undone`).
- **The 10-day grace is correct.** `RECONCILE_GRACE_DAYS = 10`, E = `reconciledTo(T - 11 days)` and `due_by` = L + 10. On 10 Dec, GAL Bank is due by 10 Dec; on 11 Dec it is overdue 1 (`test_ac3_grace_period`). On 31 Dec it is due by 10 Jan 2027 (`test_ac4_last_day_of_the_month`). The basis line states the rule.
- **A closed nil account cannot stay "due" forever.** It can only be due while E < F, which means F = L, so `due_by` is correct.
- **The account cases match R3/R10 and the tests:**
  - no entries, and first entry after L (AC 7): not required, `no_entries`;
  - dormant with entries: required;
  - reopened: overdue 5 (AC 7);
  - never reconciled: AC 1, 3, 9;
  - MAIN in All mode: AC 9.
- **Minor, no action.** `notRequired()` tests `status !== 'active'` where the spec says `= closed`. Accounts have only these two statuses, so the result is the same.
- **Read only is confirmed.** No `LedgerService` call and no write in `status()`, `statusParams()` or the export path, apart from the existing `report.exported` audit. `test_ac16_read_only` compares `ledger_entries`, marks, statements, checks, settings, transactions and every `current_balance` before and after, counts exactly 2 `report.exported` rows for 2 exports, and runs `ledger:verify`.
- **Permissions and roles are unchanged.** The report uses `report.view`, and the export needs `report.view` + `report.export`. Cashier gets 403 (AC 11), Viewer gets no export (AC 15), and branch isolation holds (AC 10).
- **The report has no money fields.** There is no "Amounts in LKR" line and no PROVISIONAL marker.

**Verdict: SIGNED OFF.** The MUST finding is done. SHOULD findings 2 and 3 are done. SHOULD 4 is carried to the product owner as the next story.
