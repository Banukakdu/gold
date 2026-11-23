# Spec: Bank reconciliation statements list (for checkers)

## 1. Goal and scope
**AG-033c** (follow-up to AG-033; not yet a row in `docs/Account-gold_Product_Backlog.xlsx`, which has AG-033 only): "As a checker (Viewer, owner, Branch Admin), I want to see every completed and undone bank reconciliation statement of my branch so that I choose what I check, not the preparer."

Source: AG-033b Q4 and its Accounting review finding 5; AG-033 Accounting review Q4. The AG-033 Q4 control is an independent "Checked by" signature on the statement reprint. Today a `report.view`-only user can reach a reprint only through a link from the preparer, because the statement history (S1 History, `/accounts/:id/reconcile`) needs `account.reconcile`. So the preparer decides which statements the checker sees. An undone or "Changed since completion" statement can go unseen.

This story adds a **read-only list** of `bank_statements` rows under Reports. Each row opens the existing S3 reprint (`/reports/bank-reconciliation?statement_id=…`).

- **It posts nothing and changes nothing.** It never writes `ledger_entries`, `accounts.current_balance`, `bank_reconciliation_marks`, `bank_statements` or `attachments`. Figures come from the stored statement columns. "Changed since completion" and late entries use the existing `BankReconciliation` rules (AG-033 R8).
- **No dependency on AG-033b (PR #25).** The bank statement copy column and filter switch on when the `attachments.bank_statement_id` column exists (section 5, R6). This story can merge before or after AG-033b.

Out of scope: ticking, completing, undoing or attaching from this list (those stay on S1 with `account.reconcile`). Also out of scope: draft (not completed) reconciliations; a list of bank accounts that were never reconciled or are overdue (Q3); recording a "checked by" sign-off in the system (Q4); wallet accounts (AG-033 Q1); scheduled email of this list (`ReportSchedule::REPORTS` unchanged); the AG-033 sign-off polish items (a) to (d) on the reprint.

## 2. Personas and permissions
No new permission key. Real keys from `PermissionSeeder`: `report.view`, `report.export`, `account.reconcile`, `audit.view`.

| Need | Key |
|---|---|
| Open the list, filters, open a row (S3 reprint) | `report.view` (the same as the reprint and the S3 report, so a row never opens a 403) |
| Export the list (PDF / Excel) | `report.view` + `report.export` |
| Download a bank statement copy from the reprint (after AG-033b) | unchanged, AG-033b section 2 |

| Template | `report.view` | `report.export` | Sees the list | Exports |
|---|---|---|---|---|
| Branch Admin (`*`) | yes | yes | own branch | yes |
| Accountant | yes | yes | own branch | yes |
| Viewer | yes | no | own branch | no (screen only) |
| Cashier | no | no | no (403, menu card hidden) | no |
| Super Admin | all keys | all keys | any branch, All mode across branches | yes |

A custom role with `account.reconcile` but no `report.view` keeps S1 History only and does not get this list (Q1). Roles stay as they are: nobody may grant a key they do not hold.

## 3. User flows
- **F1 Viewer checks month-end (VW, KDY).** Reports > "Bank reconciliation statements". The list opens with statement dates in the last 12 months, newest first. VW sees KDY Bank 31 Oct 2026 "Completed" with the tag "Changed since completion" and "Late entries 1". VW clicks the row and the S3 reprint opens with the late entry listed. VW prints it with the browser (VW has no `report.export`), checks it against the paper bank statement, and signs "Checked by". VW also sees KDY Bank 30 Nov 2026 "Undone" (by, at, reason) and asks the Branch Admin why it was undone.
- **F2 Filter for problems (BA, KDY).** Filters: Status = Completed, Changed since completion = Yes, then Apply. Only completed statements whose stored figures differ from the figures now are listed. After AG-033b: Bank statement copy = None lists completed statements without an active copy.
- **F3 Super Admin, All branches.** In All mode, the Branches multi-select (reports R1) defaults to every branch. Rows are grouped by branch code (MAIN, then by code) and show a Branch column. The Account filter lists the bank accounts of the selected branches, labelled "KDY · KDY Bank (11002)". Clicking a GAL row opens the GAL reprint (the reprint already accepts any branch in All mode).
- **F4 Super Admin, branch mode.** With X-Branch-Id KDY, the Super Admin sees KDY only, like a branch user.
- **F5 Export (ACC).** "Export PDF" / "Export Excel" export the filtered list (every matching row, not only the page) with the reports header. `report.exported` is audited.
- **F6 Branch switch.** Switching the branch reloads the list for the new branch and clears the Account filter. A user without `report.view` in the new branch leaves `/reports` (existing shell rule).

## 4. Screens
### S6 Bank reconciliation statements `/reports/reconciliation-statements`
Guard `permissionGuard('report.view')`. New card in the Reports hub, after "Unreconciled bank entries": title "Bank reconciliation statements", question "Which bank reconciliations were completed or undone, and has anything changed since?"

**Filters** (top bar; Apply button; the values are kept in the URL query so a filtered list can be bookmarked):

| Field | Type | Required | Validation / default |
|---|---|---|---|
| Branches | multi-select | All mode only | reports R1; default every branch. Hidden in branch mode |
| Accounts | multi-select | no | bank accounts in the branch scope, inactive ones included (label "(inactive)"); default all |
| Statement date from | date | no | default today minus 12 months, Y-m-d; must be on or before To |
| Statement date to | date | no | default today; may not be after today |
| Status | select | yes | All (default) / Completed / Undone |
| Changed since completion | select | yes | Any (default) / Yes / No. Undone statements are never "changed" (AG-033 R8), so Yes lists completed rows only |
| Bank statement copy | select | yes | Any (default) / Attached / None. **Shown only when `copies_supported` is true** |

"Clear filters" resets the defaults.

**Table** (desktop; sorted by statement date, newest first, then branch code, account code, completed at newest first; 50 per page):

| Column | Content |
|---|---|
| Branch | code; All mode only |
| Account | "KDY Bank (11002)"; "(inactive)" when the account is inactive |
| Statement date | date-format setting |
| Statement balance | money, right-aligned; negative shown with "Overdrawn" (AG-033 finding 3) |
| Lines | `lines_count`, with tooltip "N recorded by the preparer" (`self_count`) |
| Completed by / at | name with the controls R10 tag (deactivated / removed from branch), date and time (Asia/Colombo) |
| Status | tag "Completed" (green) or "Undone" (grey) with a second line "by <name> on <date>: <reason>" (reason cut at 80 characters, full text in a tooltip) |
| Changed | warning tag "Changed since completion" when true; empty otherwise |
| Late entries | number (0 shown as "0"); blank for undone statements |
| Bank statement copy | only when `copies_supported`: "N file(s)", plus "(M removed)"; warning tag "No copy" for a completed statement with 0 active files; blank for an undone statement with 0 files |
| (action) | "Open statement" link to `/reports/bank-reconciliation?statement_id=<id>`. The whole row is clickable too |

Above the table: "N statements" and the basis line "Completed and undone bank reconciliation statements by statement date. Figures as stored at completion; 'Changed since completion' and late entries are worked out now." Export buttons (PDF, Excel) only when `auth.can('report.export')`.

**States**
- Loading: skeleton rows.
- Empty with default filters: "No bank reconciliation statements in the last 12 months." Empty with other filters: "No statements match these filters." plus "Clear filters".
- Errors: 422 shows the field message under the field. 403 or 404 shows the API `message` in `.alert`. Network error: "Could not load the statements. Try again." with Retry.

**390px layout.** Filters fold into a "Filters (n)" button that opens a full-width sheet with Apply / Clear at the bottom, each at least 44px tall. Each row becomes a card:
- line 1: Account (with branch code in All mode) and Statement balance, right-aligned;
- line 2: Statement date and the Status tag;
- line 3: the tags "Changed since completion", "Late entries N" and "No copy" / "N file(s)" (wrapping);
- line 4: "Completed by <name>, <date time>", and for an undone row "Undone by … : <reason>" (wraps).

The whole card is the tap target for "Open statement" and is at least 44px tall. Pagination becomes "Load more". Export buttons are full width. No horizontal page scroll.

### S3 reprint (existing, unchanged)
Opened from S6. The browser Back button returns to S6 with its filters (from the URL query). No change to its content or PDF.

## 5. API
**New:** `GET /v1/reports/reconciliation-statements`, middleware `perm:report.view`, inside the `branch` group next to `reports/bank-reconciliation`. Controller `ReportController::reconciliationStatements` calls the new `BankReconciliation::statementList(ReportParams-like input)`.

Request (query):

| Param | Rule |
|---|---|
| `branches[]` | reports R1 (`ReportService::branchScope`): branch mode = absent or the active branch, otherwise 403; All mode = any branch ids, default all |
| `account_ids[]` | integers, distinct; each must be a `type = bank` account in the branch scope, otherwise 422 "Unknown bank account." |
| `from`, `to` | `date_format:Y-m-d`; defaults today-12 months / today (`BusinessDate::today()`); `to` <= today ("The To date cannot be in the future."); `from` <= `to` ("From must be on or before To.") |
| `status` | `in:all,completed,undone`, default `all` |
| `changed` | `in:any,yes,no`, default `any` |
| `copy` | `in:any,attached,none`, default `any`. When copies are not supported and the value is not `any`: 422 "Bank statement copies are not available yet." |
| `page`, `per_page` | `per_page` 1-100, default 50 |

Response 200:
```
{ meta: {…same as other reports: branches, generated_at, generated_by…},
  copies_supported: bool,
  accounts: [{id, code, name, branch:{id,code}, status}],      // bank accounts in scope, for the filter
  total, page, per_page,
  data: [{ id, branch:{id,code}, account:{id,code,name,status},
           statement_date, statement_balance, lines_count, self_count,
           status, completed_by:{id,name,tag}, completed_at,
           undone_by:{id,name,tag}|null, undone_at|null, undo_reason|null,
           changed_since_completion: bool, late_entries_count: int|null,
           attachments_count: int|null, attachments_removed_count: int|null }] }
```
Money as strings via `Money::fromCents`. The `attachments_*` fields are null when `copies_supported` is false. `late_entries_count` is null for undone rows.

Errors: 401; 403 "Missing permission: report.view"; 403 for a foreign branch in `branches[]` (reports R1); 422 as above, and 422 "Too many statements match: narrow the filters (at most 2,000)." when more than 2,000 rows match the SQL filters (R4).

**Export (reuse):** `POST /v1/reports/reconciliation-statements/export` through the existing `reports/{name}/export` route (`perm:report.view,report.export`). Body = the same filters + `format` (`pdf|xlsx`), with no paging.
- `ReportService::NAMES` gains `reconciliation-statements`.
- `ReportController::export` routes its params to `BankReconciliation`, as it does for `bank-reconciliation`.
- `ReportExporter` gains the title "Bank reconciliation statements", a PDF blade `reports.pdf.reconciliation-statements` (landscape, the same columns as S6, undo reason in full), and one Excel sheet with dates as dates, the balance as a number and a "Changed since completion" column Yes/No.
- File name `reconciliation-statements_<from>_<to>.<ext>`. Always synchronous: it is added to the `in_array($name, ['cash-flow', 'bank-reconciliation'])` exclusion, because at most 2,000 rows are possible. The PDF cap is `PDF_EXPORT_CAP` (1,000), with the existing over-cap message.
- Audit `report.exported` as for the other reports.

**Unchanged:** `GET /v1/reports/bank-reconciliation?statement_id=` (the reprint) and `GET /v1/accounts/{account}/reconciliation`.

## 6. Business rules
- **R1 Branch isolation.** `BankStatement` is `BranchScoped`. In branch mode the query uses the scope (active branch only). In All mode it uses `withoutGlobalScopes()` only after `branchScope()` has resolved the allowed set, and then filters `bank_statements.branch_id IN set` explicitly. The `accounts` list and the `account_ids[]` check use the same set. A forged account id from another branch gets 422, never data. The other side of a branch transfer is never shown (statements belong to one account).
- **R2 Every statement is listed.** Both `completed` and `undone` rows are listed. No row is hidden for being superseded, for belonging to an inactive account, or because its preparer was deactivated or removed from the branch. Names get the controls R10 tag (`BankReconciliation::people`, called per branch in All mode). This is the control the list adds: the preparer cannot choose which statements the checker sees.
- **R3 Figures.**
  - Statement balance, lines, self count, completed and undone fields: stored columns only.
  - `changed_since_completion`: the existing rule. Completed rows only (`withLateLines` to find candidates in one query, then `changed()` for those only).
  - `late_entries_count`: the number of lines that `lateEntries($s)` returns for a completed statement. It includes voids after completion, so it matches the late entries list on the reprint the checker opens. It can therefore be higher than the S1 banner count, which leaves voids out (AG-033 review finding 6). Both counts are labelled "late entries"; the tooltip on S6 says "Entries dated on or before the statement date that were recorded or voided after completion (as listed on the statement)". Compute it in one grouped query over the page's (or export's) statement ids, not one query per row.
- **R4 Filtering and volume.** Branch, account, date range (`whereDate` on `statement_date`, inclusive) and status filter in SQL. `copy` filters in SQL with `EXISTS` on active attachments. `changed` is computed (R3), so the server loads every row that matches the SQL filters (at most 2,000, else 422), computes `changed`, filters, sorts, then pages. The default 12-month range keeps this small: about 12 statements per bank account per year.
- **R5 Opening a row.** The link is the existing reprint with `statement_id`. Its branch check (`reportParams`) already gives 404 for a statement outside the active branch in branch mode, so a link copied from All mode into a branch session fails safely.
- **R6 Bank statement copy (AG-033b optional).**
  - `copies_supported` = `Schema::hasColumn('attachments', 'bank_statement_id')`. It is cached per process like `ReportService::$receiptColumns` and cleared by the same `forgetSchemaCache()`.
  - When it is true: `attachments_count` = active rows (`removed_at` null) with `bank_statement_id` = the statement, and `attachments_removed_count` = removed ones (the same definitions as AG-033b's `statements[]`). "No copy" applies to completed statements with 0 active files.
  - When it is false: the column and the filter are hidden, and the fields are null.
  - No code imports AG-033b classes, so this story builds and tests pass on `main` either way.
- **R7 Read only, no audit on view.** Viewing the list writes nothing; only an export writes `report.exported` (as the other reports).
- **R8 Dates.** "Statement date to" may not be in the future. A statement dated in a locked month or closed year is listed like any other (locks do not hide history).

## 7. Acceptance criteria
Setup: as AG-033 section 8 (MAIN, KDY, GAL; KDY Bank, GAL Bank, KDY Cash; SA, ACC, BA, VW, CSH, GACC). Today 2026-12-10.
- ST1: KDY Bank 2026-10-31, 479,500.00, lines 4, self 2, completed by ACC on 2026-11-03.
- On 2026-11-05 ACC records an expense of 1,000.00 from KDY Bank dated 2026-10-25 (October open).
- ST2: KDY Bank 2026-11-30, completed by ACC on 2026-12-02, undone by BA on 2026-12-03 with reason "Wrong closing balance typed".
- ST3: KDY Bank 2026-11-30, completed by BA on 2026-12-03.
- ST4: GAL Bank 2026-10-31, completed by GACC.
- ST5: KDY Bank 2025-09-30, completed (older than 12 months).

1. Given VW (KDY), when VW opens `/reports/reconciliation-statements`, then 200 and rows ST3, ST2, ST1 are listed in that order; ST4 and ST5 are not.
2. Given CSH, when CSH calls the API, then 403 "Missing permission: report.view", and the hub has no "Bank reconciliation statements" card.
3. Given VW, then ST1 shows Statement balance 479,500.00, Lines 4 (tooltip "2 recorded by the preparer"), Completed by ACC, Status Completed, the tag "Changed since completion", and Late entries 1.
4. Given VW, then ST2 shows Status Undone with "by BA on 2026-12-03: Wrong closing balance typed", no Changed tag and a blank Late entries; ST3 shows Completed, no tag, Late entries 0.
5. Given VW, when VW clicks ST1, then `/reports/bank-reconciliation?statement_id=<ST1>` opens with the late entry of 1,000.00 listed, and Back returns to S6 with the same filters.
6. Given the 1,000.00 expense of 2026-10-25 is then voided (on 2026-12-10, contra dated 2026-10-25), when VW reloads, then ST1 shows Late entries 2 (recorded + voided, as listed on the reprint) and no "Changed since completion" tag (its figures at 31 Oct equal the stored ones again), and ST3 shows Late entries 1 and the tag "Changed since completion".
7. Given filters Status = Completed and Changed = Yes, then only ST1 is listed. With Changed = No, ST3 only. With Status = Undone, ST2 only. With Status = Undone and Changed = Yes, no rows and "No statements match these filters.".
8. Given `from=2025-09-01&to=2025-09-30`, then only ST5 is listed. `to=2026-12-11` returns 422 "The To date cannot be in the future."; `from=2026-12-01&to=2026-11-01` returns 422 "From must be on or before To.".
9. Given ACC (KDY) with `account_ids[]=<GAL Bank>`, then 422 "Unknown bank account."; with `branches[]=<GAL>`, then 403; the `accounts` list has KDY Bank only (not KDY Cash, not GAL Bank).
10. Given SA in All mode with no `branches[]`, then ST1 to ST4 are listed with a Branch column and `accounts` has KDY Bank and GAL Bank; clicking ST4 opens the GAL reprint. Given SA with X-Branch-Id KDY, ST4 is not listed and `/reports/bank-reconciliation?statement_id=<ST4>` returns 404.
11. Given ACC is deactivated, when VW opens the list, then ST1 still shows with "ACC" and the deactivated tag.
12. Given KDY Bank is set inactive, then ST1 to ST3 are still listed with "(inactive)" and the account is still in the Accounts filter.
13. Given `main` without AG-033b, then `copies_supported` is false, the Bank statement copy column and filter are not shown, `attachments_count` is null, and `copy=none` returns 422 "Bank statement copies are not available yet.".
14. Given AG-033b merged, ST1 with 1 active and 1 removed file and ST3 with none, then `copies_supported` is true; ST1 shows "1 file (1 removed)"; ST3 shows "No copy"; ST2 (undone, 0 files) shows nothing; `copy=none` lists ST3 only; `copy=attached` lists ST1 only.
15. Given ACC (`report.export`), when the list is exported to PDF and Excel with default filters, then the files have the reports header, rows ST3, ST2, ST1 with the same values as the screen (the full undo reason included), and `report.exported` is audited. Given VW, export returns 403 and the buttons are hidden.
16. Given 2,001 KDY statements in range (test factory), then the API returns 422 "Too many statements match: narrow the filters (at most 2,000)."; with 60 statements, `per_page=50` gives 50 rows, `total` 60, and page 2 gives 10.
17. Given any call to the list or the export, then `ledger_entries`, `accounts.current_balance`, `bank_reconciliation_marks` and `bank_statements` are unchanged, and no audit row except `report.exported` is written.
18. Given a 390px viewport with 20 rows, then there is no horizontal page scroll, each row is a card at least 44px tall that opens the reprint, the filters open in a full-width sheet, and the tags wrap.
19. Given VW in KDY switches to a branch where VW has no role with `report.view`, then the app leaves `/reports`; switching to another branch with `report.view` reloads the list for that branch with the Account filter cleared.

## 8. Open questions (assumption in bold; work proceeds on it)
- **Q1 (product owner)** Should a role with `account.reconcile` but without `report.view` see this list? **No: the list needs `report.view`, the same as the reprint it opens. Such a role keeps S1 History.** No template has that combination.
- **Q2 (accountant)** Should "Late entries" count voids after completion (they are listed on the reprint) or match the S1 banner, which leaves them out? **Count them, to match the reprint the checker signs (R3).** The tooltip explains this.
- **Q3 (accountant / product owner)** Should the list also show bank accounts with no completed statement for a month (never reconciled or overdue)? **Out of scope.** The S3 report already says "never reconciled" or "last reconciled to", and the Lock confirm lists them.
- **Q4 (product owner)** Should the checker record "Checked by" in the system (who, when, a note) instead of only on paper? **Out of scope: a separate story.** It would be the natural next step for this list (a "Checked" column).
- **Q5** What is the default range? **The last 12 months by statement date, all statuses.**
- **Q6** Is a backlog row needed? **Yes. The product owner adds AG-033c to the backlog sheet; this spec does not edit the xlsx.**
