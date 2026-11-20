# Spec: Reports (Income & Expense, Branch Comparison, Transfers & Allocations, Daily Cash Book)

## 1. Goal and scope
Give Super Admins and branch users four standard reports, each viewable on screen and exportable to PDF and Excel.
Covers **AG-082** Income & Expense by Category > Sub-category (filters, % of total, drill-down), **AG-083** Branch Comparison (opening, funds received, income, expenses, transfers in/out, closing; month/quarter/year/custom), **AG-084** Transfers & Allocations (movement list with status + branch-to-branch net matrix), **AG-085** Daily Cash Book (per account per day: opening, receipts, payments, closing; print-friendly). Intent from Project Document section 8: filter by branch (or all branches for Super Admin) and date range, drill down, export with company name/logo.

Out of scope: AG-086 Cash Flow Summary, AG-087 background/queued exports and download links, AG-088 scheduled emails, AG-033 reconciliation report, audit trail report, period comparison columns, charts, changes to the dashboard. Approval/"pending"/"in transit" transfer states are not built yet (see Q2).

## 2. Personas and permissions
Keys (`PermissionSeeder::PERMISSIONS`): `report.view` (see the four reports), `report.export` (PDF/Excel). Existing `report.dashboard` is not used here.

| Persona | Has | Sees |
|---|---|---|
| Super Admin (`is_super_admin`) | everything | "All branches" mode: any set of branches; branch mode: that branch |
| Branch Admin template (`*`) | view + export | active branch only |
| Accountant template | view + export | active branch only |
| Viewer template | view only | active branch only; export buttons hidden |
| Cashier template | neither | no Reports menu (dashboard only) |
| Custom role | whatever is ticked | active branch only |

## 3. User flows
**F1 Super Admin, All branches.** Switcher = All -> Reports -> pick a report -> filter bar shows a **Branches** multi-select (default: none ticked = all branches, inactive branches included) and the period -> report loads -> "PDF" / "Excel" downloads the same data with the same filters.
**F2 Super Admin in branch mode / normal branch user.** Switcher = KDY -> Reports -> no Branches picker; every report is limited to KDY. Switching branch reloads the report.
**F3 Drill-down (AG-082).** Click a sub-category (or "(no sub-category)") amount -> opens `/transactions` pre-filtered by category, dates, branches and type (income or expense + reversal). Reversal rows show "Reversal of <ref>"; originals minus reversals = clicked amount.
**F4 Month-end (doc 7.5).** Accountant runs the Daily Cash Book per account and prints it; Super Admin reviews Branch Comparison and Transfers & Allocations for the month.

## 4. Screens
Menu "Reports" if `auth.can('report.view')`. All routes guarded `permissionGuard('report.view')`; reload on branch switch. Amounts right-aligned, `1,234.56`, negatives `-1,234.56`. Loading: "Loading...". Errors (400/403/422) show API `message` in `.alert`; field errors under the filter. Export buttons only with `report.export`; while downloading: disabled, "Preparing...".

Common filter fields:
| Field | Type | Req | Validation |
|---|---|---|---|
| From / To | date | yes | `to >= from`; span max 366 days (cash book: 31) |
| Branches | multi-select | no | All mode only; from `GET /branches` incl. inactive (tag "Inactive") |

### 4.1 Hub `/reports`
Four cards (title + one-line question from doc section 8) linking to the reports below.

### 4.2 Income & Expense `/reports/income-expense` (AG-082)
Filters: From/To (default current month), Branches, Type (All / Income / Expense, default All).
Per type (Income section, then Expense): Category | Sub-category | Amount | % of type total. Category row bold with subtotal; sub-category rows under it; amounts posted directly on a category show as sub-category "(no sub-category)". Section footer "Total income" / "Total expenses"; page footer "Net (income - expenses)" when Type = All. Sorted by amount desc, then name. Inactive categories tagged "Inactive".
Actions: sub-category amount = drill-down link (F3). Empty: "No income or expenses in this period."

### 4.3 Branch Comparison `/reports/branch-comparison` (AG-083)
Filters: Period type (Month default / Quarter / Year / Custom) with month picker (YYYY-MM), quarter (Q1-Q4 + year), year, or From/To. Client converts to `from`/`to` (calendar year). Branches.
Table: one row per branch (Main first, then by code): Branch | Opening balance | Capital introduced | Funds received | Income | Expenses | Transfers in | Transfers out | Closing balance; footer "Total". Inactive branches tagged. Note under table: "Closing = Opening + Capital introduced + Funds received + Income - Expenses + Transfers in - Transfers out. Internal transfers between accounts of the same branch are not shown."
Empty: "No branches to show."

### 4.4 Transfers & Allocations `/reports/transfers` (AG-084)
Filters: From/To (default current month), Branches, Movement type (multi: Opening allocation, Top-up, Fund return, Branch transfer; default all).
Section "Movements" (paged 50, newest first): Date | Ref no | Type | From (branch · account) | To (branch · account) | Amount | Status (Posted / Reversed / "Reversal of <ref>") | Note | Created by. Footer: "Net moved (reversals deducted)".
Section "Net position matrix": rows = sending branch, columns = receiving branch, cell = net amount sent (reversals deducted), diagonal "—", last column "Total sent", last row "Total received", extra row "Net (received - sent)". Branch user: only own row and column filled; other branches appear only as counterparties.
Empty: "No transfers or allocations in this period."

### 4.5 Daily Cash Book `/reports/cash-book` (AG-085)
Filters: From/To (default today, max 31 days), Accounts (multi-select of accounts in scope, inactive tagged; default all), Branches (All mode: required, 422 "Select at least one branch").
Per account (heading "KDY · Cash (cash)"), one block per day **with movements**: Date | Opening | Receipts | Payments | Closing, then detail lines Ref no | Type | Description / payee | Receipt | Payment. Account footer: period opening, total receipts, total payments, period closing. Days without movements skipped (next opening = last closing).
Action "Print": browser print; print CSS hides menu/filters, each account on a new page, header shows company, branch, account, range, printed at/by. Empty: "No movements in this period." (opening/closing still shown).

## 5. API
New `ReportController` + `app/Services/ReportService.php`, inside the `branch` middleware group. Amounts are strings with 2 decimals (`App\Support\Money`).

| Method | Path | Perm | Params |
|---|---|---|---|
| GET | `/v1/reports/income-expense` | report.view | `from`, `to`, `branches[]`, `type=all\|income\|expense` |
| GET | `/v1/reports/branch-comparison` | report.view | `from`, `to`, `branches[]` |
| GET | `/v1/reports/transfers` | report.view | `from`, `to`, `branches[]`, `types[]`, `page` |
| GET | `/v1/reports/cash-book` | report.view | `from`, `to`, `branches[]`, `accounts[]` |
| POST | `/v1/reports/{name}/export` | report.export | same params + `format=pdf\|xlsx`; unknown `name` -> 404 |

Responses:
- income-expense: `{from,to,branches:[{id,code,name}],income:{total,categories:[{id,name,is_active,amount,percent,subs:[{id|null,name,is_active,amount,percent}]}]},expense:{...},net}`; `percent` = string, 1 decimal, or `null`.
- branch-comparison: `{from,to,rows:[{branch:{id,code,name,status},opening,funds_received,income,expenses,transfers_in,transfers_out,closing}],total:{same keys}}`.
- transfers: `{from,to,movements:{data:[{id,ref_no,type,txn_date,from:{branch_code,account},to:{branch_code,account},amount,status,reversal_of_ref,description,created_by}],current_page,last_page,total},net_moved,matrix:{branches:[{id,code}],cells:[{from_id,to_id,amount}],sent:{id:amount},received:{id:amount},net:{id:amount}}}`.
- cash-book: `{from,to,accounts:[{account:{id,name,type,branch_code,status},opening,receipts,payments,closing,days:[{date,opening,receipts,payments,closing,lines:[{ref_no,type,description,receipt,payment}]}]}]}`.
- export: 200 file, `Content-Disposition: attachment; filename="<name>_<from>_<to>.<pdf|xlsx>"`.

Errors: 400 no branch header (normal user); 403 missing permission; 403 "You do not have access to branch <code>." when `branches[]` holds a branch other than the active one in branch mode; 422 unknown branch/account, bad dates, range too long, cash book without branches in All mode; 422 over cap: Excel "This export has N rows; the limit is 5,000. Narrow the date range or branches."; PDF "This export has N rows; the limit for PDF is 1,000. Use Excel or narrow the date range or branches."; cash book screen over 5,000 lines: 422 "This cash book has N lines, too many to show on screen (the limit is 5,000). Narrow the date range, branches or accounts, or export it to Excel." (reworded by AG-087, where Excel takes larger books in the background) Server errors (500) never show raw PHP text; the UI shows a friendly message.

Change to existing endpoint:
- **E1 `GET /transactions`**: add `branches[]` (All mode only; same 403 rule in branch mode) and `category_only=1` (exact `category_id`, no children). Web transaction list reads `from,to,type,category_id,category_only,branches` from URL query params on load (today it reads none).

Export: composer `maatwebsite/excel` ^3.1 (xlsx) and `barryvdh/laravel-dompdf` ^3 (PDF from Blade views). **Synchronous** in the request for now (no queue); cap 5,000 detail rows for Excel and 1,000 for PDF (dompdf needs about 115 MB for 1,000 cash book lines; the PDF request runs with up to 120 s and 512M as a safety margin) (I&E: category lines; comparison: branches; transfers: movements, matrix not counted; cash book: detail lines). Each export writes `AuditLog::record('report.exported', null, null, ['name','format','params','rows'])`.
PDF/Excel header: company name `Setting::get('company_name', config('app.name'))`, logo if `Setting::get('company_logo')` set (AG-092 later), title, period, branches ("All branches" or codes), generated at + by. Excel: one sheet per section (transfers: "Movements", "Matrix"); amounts numeric cells `#,##0.00`; dates as date cells; text starting with `= + - @` prefixed with `'` (formula injection).

## 6. Business rules
- **R1 Branch resolution.** Branch mode (any user): set = {active branch}; `branches[]` must be absent or equal to it. All mode (Super Admin only): `branches[]` or every branch (active + inactive). `LedgerEntry` has no branch scope and transfer `transactions.branch_id` is the *sending* branch, so ReportService uses `withoutGlobalScopes()` and filters explicitly: ledger reports on `ledger_entries.branch_id`; transfer list on `branch_id IN set OR related_branch_id IN set`.
- **R2 What counts.** Transactions with status `posted` or `reversed`, plus their `reversal` transactions (`posted`). Voided entries have no ledger entries and are excluded everywhere. Future `pending`/`rejected` never count.
- **R3 Reversals net out.** Original counts on its date in its column; the reversal counts on the reversal date in **the original's column with opposite sign** (reversal of income = negative income; reversal of a GAL->KDY transfer = -amount in the GAL->KDY cell, not +amount KDY->GAL). Same period nets to 0; across periods each period shows its part (totals may be negative).
- **R4 Income & Expense** uses only `income`, `expense` and reversals of them, by `txn_date`, grouped by `category_id` (parent = Category, child = Sub-category). Capital, allocations, returns, branch and internal transfers are never income/expense. Percent = amount / type total x 100, half-up to 1 decimal from cents; `null` ("—") when type total <= 0; percents may not add to exactly 100.0. A line shows if any counted transaction hit it in the period, even if it nets to 0.00.
- **R5 Branch Comparison** from each branch's ledger entries (`entry_date`): Opening = SUM(debit - credit) before `from`; Capital introduced = debits of `capital`; Funds received = debits of `opening_allocation`, `fund_allocation`; Income = debits of `income`; Expenses = credits of `expense`; Transfers in = debits of `branch_transfer`, `fund_return`; Transfers out = credits of `opening_allocation`, `fund_allocation`, `fund_return`, `branch_transfer`; `internal_transfer` not shown (nets 0 in branch); reversal entries per R3. Closing = Opening + Capital introduced + Funds received + Income - Expenses + Transfers in - Transfers out and **must equal** SUM(debit - credit) up to `to` (server asserts; mismatch = 500 + log). All accounts incl. inactive.
- **R6 Transfers & Allocations** lists `opening_allocation`, `fund_allocation` (label "Top-up"), `fund_return`, `branch_transfer` and their reversals; `internal_transfer`, `capital` excluded. Net moved = originals - reversals. Matrix (A,B) = originals A->B minus reversals of A->B originals.
- **R7 Cash Book** from ledger entries per account: Receipts = SUM(debit), Payments = SUM(credit) per `entry_date`; reversal entries appear gross on their date, matching `GET /accounts/{id}/statement`.
- **R8** Money in integer cents via `Money`, no floats; dates with `whereDate`. Deactivated branches, accounts, categories stay in reports. Single currency (LKR).

## 7. Worked example (test seed; QA/accountant check)
Branches MAIN (main), KDY, GAL. Accounts: MAIN Bank (Main account), KDY Cash, KDY Bank, GAL Cash. Categories: Income: Sales > Retail; Service income (no subs). Expense: Utilities > Electricity, Water; Rent.
Before October 2026: 09-01 Capital MAIN Bank 1,000,000.00; 09-02 Opening allocation MAIN->KDY Cash 200,000.00; 09-02 Opening allocation MAIN->GAL Cash 100,000.00; 09-20 Expense KDY Cash Rent 30,000.00.
October 2026: (1) 10-01 Income KDY Cash Retail 50,000.00 (2) 10-02 Income KDY Cash Service income 12,500.00 (3) 10-03 Expense KDY Cash Electricity 8,200.00 (4) 10-03 Expense GAL Cash Water 1,800.00 (5) 10-05 Income GAL Cash Retail 25,000.00 (6) 10-06 Top-up MAIN Bank->KDY Cash 100,000.00 (7) 10-07 Branch transfer KDY Cash->GAL Cash 30,000.00 (8) 10-08 Internal KDY Cash->KDY Bank 40,000.00 (9) 10-10 Fund return GAL Cash->MAIN Bank 20,000.00 (10) 10-12 Expense KDY Cash Electricity 5,000.00, reversed 10-14 (11) 10-15 Branch transfer GAL Cash->KDY Cash 10,000.00, reversed 10-16 (12) 10-20 Expense GAL Cash Rent 15,000.00, voided (13) 10-30 Income GAL Cash Retail 4,000.00, reversed 11-02.

**E-1 Income & Expense, All, 2026-10-01..31:** Income: Sales 79,000.00 (86.3%) > Retail 79,000.00 (86.3%); Service income 12,500.00 (13.7%) > (no sub-category) 12,500.00. Total income 91,500.00. Expense: Utilities 10,000.00 (100.0%) > Electricity 8,200.00 (82.0%), Water 1,800.00 (18.0%). Rent not shown. Total expenses 10,000.00. Net 81,500.00. KDY only: income 62,500.00 (Retail 80.0%, Service income 20.0%), expenses 8,200.00, net 54,300.00. GAL only: income 29,000.00, expenses 1,800.00, net 27,200.00. **GAL, 2026-11-01..30:** Retail -4,000.00, total income -4,000.00, percent "—".

**E-2 Branch Comparison, All, October:**
| Branch | Opening | Funds rec. | Income | Expenses | Trf in | Trf out | Closing |
|---|---|---|---|---|---|---|---|
| MAIN | 700,000.00 | 0.00 | 0.00 | 0.00 | 20,000.00 | 100,000.00 | 620,000.00 |
| KDY | 170,000.00 | 100,000.00 | 62,500.00 | 8,200.00 | 0.00 | 30,000.00 | 294,300.00 |
| GAL | 100,000.00 | 0.00 | 29,000.00 | 1,800.00 | 30,000.00 | 20,000.00 | 137,200.00 |
| Total | 970,000.00 | 100,000.00 | 91,500.00 | 10,000.00 | 50,000.00 | 150,000.00 | 1,051,500.00 |

**E-3 Transfers, All, October:** 5 movements: 10-06 Top-up MAIN->KDY 100,000.00 Posted; 10-07 Branch transfer KDY->GAL 30,000.00 Posted; 10-10 Fund return GAL->MAIN 20,000.00 Posted; 10-15 Branch transfer GAL->KDY 10,000.00 Reversed; 10-16 Reversal (KDY->GAL) 10,000.00 "Reversal of GAL-TRF-...". Net moved 150,000.00. Matrix: MAIN->KDY 100,000.00; KDY->GAL 30,000.00; GAL->MAIN 20,000.00; GAL->KDY 0.00; others 0.00. Sent: MAIN 100,000.00, KDY 30,000.00, GAL 20,000.00. Received: MAIN 20,000.00, KDY 100,000.00, GAL 30,000.00. Net: MAIN -80,000.00, KDY +70,000.00, GAL +10,000.00 (sum 0; equals Funds rec. + In - Out in E-2). KDY user sees 4 movements (not 10-10).

**E-4 Cash Book, KDY Cash, 10-01..10-08** (opening / receipts / payments / closing): 10-01 170,000.00 / 50,000.00 / 0.00 / 220,000.00; 10-02 220,000.00 / 12,500.00 / 0.00 / 232,500.00; 10-03 232,500.00 / 0.00 / 8,200.00 / 224,300.00; 10-06 224,300.00 / 100,000.00 / 0.00 / 324,300.00; 10-07 324,300.00 / 0.00 / 30,000.00 / 294,300.00; 10-08 294,300.00 / 0.00 / 40,000.00 / 254,300.00. Totals: receipts 162,500.00, payments 78,200.00, closing 254,300.00. **10-12..10-16:** 10-12 254,300.00 / 0.00 / 5,000.00 / 249,300.00; 10-14 249,300.00 / 5,000.00 / 0.00 / 254,300.00; 10-15 254,300.00 / 10,000.00 / 0.00 / 264,300.00; 10-16 264,300.00 / 0.00 / 10,000.00 / 254,300.00. **GAL Cash 10-20..10-20:** no day rows; opening = closing = 133,200.00.

## 8. Acceptance criteria
1. Given the E-dataset and Super Admin in All mode, When GET income-expense for October with no `branches[]`, Then the response equals E-1 (All).
2. Given the same, When `branches[]=KDY`, Then income 62,500.00, expenses 8,200.00, net 54,300.00; When `branches[]=GAL`, Then net 27,200.00.
3. Given October, When income-expense runs, Then no line includes the 100,000.00 top-up, 30,000.00/10,000.00 branch transfers, 40,000.00 internal transfer or 20,000.00 fund return.
4. Given entry (10) and its reversal, When income-expense runs for October, Then Electricity = 8,200.00; When it runs for 10-12..10-13, Then Electricity = 5,000.00; When for 10-14..10-14, Then Electricity = -5,000.00.
5. Given voided entry (12), When any report runs for October, Then Rent and 15,000.00 do not appear and the GAL Cash cash book has no 10-20 row.
6. Given entry (13), When income-expense runs for GAL, November, Then Retail = -4,000.00 and percent is null ("—").
7. Given a KDY Accountant (X-Branch-Id = KDY), When income-expense runs without `branches[]`, Then it equals the KDY figures in AC 2; When `branches[]` = GAL's id, Then 403 "You do not have access to branch GAL."
8. Given a Cashier, When calling any `/v1/reports/*`, Then 403, and the Reports menu is hidden.
9. Given a Viewer, When opening a report, Then data loads, export buttons are hidden, and POST export returns 403.
10. Given the E-1 screen, When clicking Retail 79,000.00, Then `/transactions` opens filtered (Retail, 2026-10-01..31, types income + reversal, same branches) and lists entries (1), (5), (13) totalling 79,000.00; clicking Service income "(no sub-category)" lists only entry (2).
11. Given All mode, When branch-comparison runs for October, Then rows and Total equal E-2, and each row satisfies Closing = Opening + Capital introduced + Funds received + Income - Expenses + Transfers in - Transfers out.
12. Given E-2, When each branch's closing is compared with the sum of its accounts' statement closings at 2026-10-31, Then they match (MAIN 620,000.00; KDY 254,300.00 + 40,000.00; GAL 137,200.00).
13. Given Period = Quarter Q4 2026, When applied, Then the request uses from=2026-10-01, to=2026-12-31; Year 2026 = 2026-01-01..2026-12-31; Month 2026-02 = 2026-02-01..2026-02-28.
14. Given a deactivated branch with history, When branch-comparison runs in All mode, Then it is listed with tag "Inactive" and its figures.
15. Given All mode, When transfers runs for October, Then movements, net moved and matrix equal E-3, and matrix Net sums to 0.00.
16. Given a KDY user, When transfers runs for October, Then 4 movements (10-06, 10-07, 10-15, 10-16) are listed and the matrix fills only the KDY row and column.
17. Given the dataset, When transfers runs for any period, Then internal transfer (8) and capital never appear.
18. Given KDY Cash, When cash-book runs 10-01..10-08 and 10-12..10-16, Then rows equal E-4 and each period closing equals the account statement closing for the same `to`.
19. Given a 32-day range, When cash-book runs, Then 422; Given a 367-day range on any other report, Then 422.
20. Given All mode and no branches, When cash-book runs, Then 422 "Select at least one branch".
21. Given an Accountant, When exporting each report as xlsx and pdf for October, Then a file named `<name>_2026-10-01_2026-10-31.<ext>` downloads, totals equal the screen, the PDF header shows company name, period, branches and generated-by, and an audit log row `report.exported` exists.
22. Given filters producing 5,001 detail rows, When exporting to Excel, Then 422 with the cap message and no file; Given exactly 5,000, Then the Excel file downloads. Given 1,001 detail rows, When exporting to PDF, Then 422 "This export has 1,001 rows; the limit for PDF is 1,000. Use Excel or narrow the date range or branches." and no file; Given exactly 1,000, Then the PDF downloads.
23. Given a payee "=HYPERLINK(\"x\")", When exported to Excel, Then the cell holds the literal text, not a formula.
24. Given the cash book page, When printing, Then menu and filters are hidden and each account starts on a new page.
25. Given incomes 0.10 and 0.20 in one sub-category, When the report runs, Then the total is exactly 0.30 on screen and in both exports.

## 9. Open questions (assumption in force)
- Q1 The dashboard drops `reversed` originals entirely; should it follow R3? Assumed: dashboard unchanged here; numbers differ only for cross-period reversals.
- Q2 Transfer approval / receipt acknowledgement is not built (LedgerService always posts). Assumed: status shows Posted / Reversed / Reversal only; Pending and In transit come with those stories and never count in totals.
- Q3 Cash book shows reversals gross (receipt and payment) rather than hiding the pair. Assumed gross, to match the statement and the cash count; accountant to confirm.
- Q4 Resolved by the Accounting review (finding 2): Capital has its own "Capital introduced" column; across all branches Funds received + Transfers in - Transfers out = 0.00. E-2 figures are unchanged (Capital 0.00 in October).
- Q5 Year and quarter use the calendar year until the fiscal-year setting (AG-092) exists.
- Q6 Mixed currencies: assumed all accounts are LKR; no conversion.
- Q7 Exports are synchronous with a 5,000-row cap for Excel and a 1,000-row cap for PDF (PDF rendering is memory-heavy); above it the user switches to Excel or narrows filters until AG-087 (queued exports) ships.

## Accounting review
Reviewed by the accountant against `LedgerService` as it stands (reversal = transaction type `reversal` with `reversal_of_id`, entries mirrored with debit/credit swapped; void deletes the entries and keeps the transaction row).

**Worked example check.** E-1, E-2, E-3 and E-4 recomputed from the dataset: every figure is correct (incl. percents 86.3/13.7, 82.0/18.0, 80.0/20.0; E-2 closings agree with account balances MAIN 620,000.00, KDY 254,300.00 + 40,000.00, GAL 137,200.00; matrix net sums to 0; E-4 openings carry correctly over skipped days, GAL Cash at 10-20 = 133,200.00). No in-place corrections made. R1, R2, R6, R8 and Q2, Q6, Q7 are fine as written.

### Findings
1. **MUST - Column of a reversal entry (R3/R5).** Reversal ledger entries belong to a transaction of type `reversal` and sit on the opposite side, so "debits of X / credits of X" will misclassify them (e.g. KDY's reversal of (11) is a *credit* and would land in Transfers out +10,000 instead of Transfers in -10,000; closing still reconciles, so the server assertion will not catch it). Rule to add to R5: *a reversal entry is classified by the original transaction's type (via `reversal_of_id`) and by the side of the original entry on the same account, with the amount negated.* Same rule for I&E (category from the original) and the matrix (original's from/to).
2. **MUST - Capital is not "Funds received" (Q4).** Capital is external owner funding; allocations/top-ups are internal. Mixing them means the consolidated Total row cannot show inter-branch elimination and E-3's "Net = Funds rec. + In - Out" breaks in any period with capital. Add a separate column **Capital introduced** (debits of `capital`, reversals per finding 1) before Funds received; Funds received = `opening_allocation`, `fund_allocation` only. Closing = Opening + Capital + Funds received + Income - Expenses + Transfers in - Transfers out. With all branches selected, Total (Funds received + Transfers in - Transfers out) must be 0.00. E-2 October figures are unchanged with Capital = 0.00 in every row.
3. **MUST - Dashboard must follow R3 (Q1).** Today the dashboard counts only `status = posted` income/expense, so a reversed original vanishes from its own period and the reversal is never counted. After (13) is reversed on 11-02, the dashboard shows October income 87,500.00 (GAL 25,000.00) while this report shows 91,500.00 (GAL 29,000.00), and November 0.00 instead of -4,000.00: October changes retroactively and two screens disagree on the same figure. Change the dashboard income/expense/trend queries to the R3 basis in this release (small query change; lift it from the out-of-scope list).
4. **MUST - Printed/exported report content.** Every PDF page and the cash book print: company name, report title, branch(es) (codes + names, or "All branches"), period, "Amounts in LKR", basis line ("Posted entries incl. reversals; voided entries excluded"), "Printed by <user full name> at <YYYY-MM-DD HH:mm> (Asia/Colombo)", "Page x of y". Last page: report totals and a signature block "Prepared by / Checked by / Approved by" (name, signature, date lines) on Daily Cash Book and Branch Comparison. Excel: same header lines in the top rows of every sheet, totals row, frozen header row.
5. **SHOULD - Page totals.** PDF Daily Cash Book and Transfers movement list: per-page subtotal of Receipts/Payments (Amount) with "Brought forward" at the top and "Carried forward" at the foot of each page; the final total equals the report total. Screen: footer label "Net moved for the whole period (all pages)" so it is not read as a page total.
6. **SHOULD - Signed amounts in the movements list.** Show reversal rows' Amount as negative (-10,000.00) so the Amount column foots to Net moved, in screen, PDF and Excel (Excel as a signed numeric cell).
7. **SHOULD - Provisional marker.** A void deletes the entries on the original date, so an unlocked month can still change after it is printed. When any month in the range is not locked (`PeriodLock`) for a branch in scope, show "PROVISIONAL - period not locked" in the header of screen, PDF and Excel. Month-end flow F4 should be: lock the month, then print and sign.
8. **SHOULD - Cash book reversal lines (Q3).** Agree gross presentation (matches the statement and the physical cash count). Original line shows "(reversed by <ref>)", reversal line shows "Reversal of <ref>"; voided entries never appear.
9. **SHOULD - Percent rules (R4).** Round half away from zero (so -0.05 % -> -0.1 %, symmetric with half-up for positives); category percent = category amount / type total (not the sum of rounded sub percents); lines may be negative while the total is positive.
10. **SHOULD - Subset of branches in All mode.** Note under Branch Comparison/matrix when not all branches are selected: "Transfers with branches not selected are included; inter-branch amounts will not net to zero."
11. **SHOULD - Fiscal year (Q5).** Sri Lankan businesses usually report April-March. Calendar year is acceptable until AG-092, but label the period "Year 2026 (calendar)".
12. **SHOULD (separate story, not this feature) - Void deletes ledger entries** (`LedgerService::void` -> `entries()->delete()`), which conflicts with "nothing is hard-deleted" and changes history silently. Prefer keeping the entries and posting contra entries, or at least block voids in a month already reported/locked.

### Answers to open questions
- Q1: No - the dashboard must follow R3 (finding 3).
- Q2: Agreed; Pending/In transit never count in totals or balances.
- Q3: Agreed, gross, with cross-references (finding 8).
- Q4: Capital in its own column, not in Funds received (finding 2).
- Q5: Calendar year acceptable for now, labelled (finding 11).
- Q6: Agreed (LKR only); show "Amounts in LKR" on every report.
- Q7: Agreed; the export must contain all rows (not the current screen page) and the same totals as the screen.

### Acceptance criteria to add
26. Given the E-dataset, When branch-comparison runs for 2026-10-15..2026-10-15, Then KDY Transfers in = 10,000.00 and GAL Transfers out = 10,000.00; When for 2026-10-16..2026-10-16, Then KDY Transfers in = -10,000.00, KDY Transfers out = 0.00, GAL Transfers out = -10,000.00, GAL Transfers in = 0.00.
27. Given the E-dataset, When branch-comparison runs for 2026-09-01..2026-09-30 in All mode, Then MAIN: Opening 0.00, Capital 1,000,000.00, Funds received 0.00, Transfers out 300,000.00, Closing 700,000.00; KDY: Funds received 200,000.00, Expenses 30,000.00, Closing 170,000.00; GAL: Funds received 100,000.00, Closing 100,000.00; Total Capital 1,000,000.00, Closing 970,000.00, and Total (Funds received + Transfers in - Transfers out) = 0.00.
28. Given the E-dataset with (13) reversed on 11-02, When the dashboard runs for October (All), Then income = 91,500.00 and expenses = 10,000.00 (equal to E-1); When for November (GAL), Then income = -4,000.00.
29. Given any report exported to PDF, Then every page shows company name, title, branches, period, "Amounts in LKR", "Printed by <name> at <date time>" and "Page x of y", and the Daily Cash Book and Branch Comparison end with Prepared by / Checked by / Approved by lines; the same header lines appear at the top of every Excel sheet.
30. Given a Daily Cash Book or movement list PDF over more than one page, Then each page shows Brought forward / page subtotal / Carried forward, and the last Carried forward equals the report total.
31. Given October in All mode, When transfers runs, Then the reversal row Amount is -10,000.00 and the Amount column sums to Net moved 150,000.00.
32. Given October not locked for GAL, When any report covering GAL in October runs or is exported, Then "PROVISIONAL - period not locked" is shown; after October is locked for all branches in scope, it is not.
33. Given the KDY Cash book 10-12..10-16, Then the 10-12 line shows "(reversed by <ref>)", the 10-14 line "Reversal of <ref>", and receipts 15,000.00 / payments 15,000.00 for the period.
34. Given GAL income in November of Retail -4,000.00 and Service income 12,500.00, When income-expense runs, Then total 8,500.00, Retail -47.1 %, Service income 147.1 %.

## Accountant sign-off
Checked on branch `feature/reports` (HEAD `ddf729f`) against the Accounting review above. `php artisan test`: 103 passed (1,229 assertions, in-memory DB); `pint --test` passed. QA: 33/34 ACs passed in browser and in the downloaded files with all E-1..E-4 figures matching; the one failure (AC 22, PDF performance) is fixed by the 1,000-row PDF cap (`ReportService::PDF_EXPORT_CAP`, `ReportExporter::download` + `raisePdfLimits`; tests `test_ac22_pdf_cap`, `test_ac22_row_cap`). Role templates as delivered match section 2 (`PermissionSeeder.php:28-35`: Accountant view+export, Viewer view only, Cashier neither).

### Findings
| # | Finding | Result | Evidence |
|---|---|---|---|
| 1 | MUST reversal classified by the original | DONE | `ReportService.php:290-300` (comparison: original's type via `COALESCE(o.type, t.type)`, reversal debit/credit swapped and negated); `:187-191` (I&E: original's category, negated); `:380-382` (matrix: reversal keeps original's `branch_id`/`related_branch_id`, `LedgerService.php:143-148`, negated). Tests `test_ac26_reversal_is_classified_by_the_original` (incl. expense and cross-period income reversals), `test_ac4_reversal_by_period`, `test_ac15_ac31_october_equals_e3`. |
| 2 | MUST capital in its own column | DONE | `COMPARISON_KEYS` + `match` at `ReportService.php:276, 310-321`; closing formula includes Capital; screen, PDF and Excel columns "Capital introduced". Reconciliation to the ledger at `:322-326` (500 + log on mismatch, `test_unreconciled_ledger_is_a_server_error`). `test_ac27_september_with_capital` asserts Total (Funds + In - Out) = 0.00. |
| 3 | MUST dashboard on R3 basis | DONE | `DashboardController.php` uses `ReportService::countedTransactions` for totals, top-5 and 12-month trend. `test_ac28_dashboard_follows_r3`: October 91,500.00 / 10,000.00 (= E-1), GAL November -4,000.00, branch mode KDY 62,500.00 / 8,200.00. |
| 4 | MUST printed/exported content | DONE | PDF `layout.blade.php`: fixed header on every page (company, title, branches, period, "Amounts in LKR", basis line, "Printed by <name> at <time> (Asia/Colombo)", PROVISIONAL); "Page x of y" via `page_text` (`ReportExporter.php:165`); signature block (`signatures.blade.php`) on Cash Book and Branch Comparison. Excel: same header lines on every sheet, totals row, frozen header (`ReportSheet.php:47-62`). Cash book browser print: `ReportHeadComponent` + `ag-signatures` (page numbers there depend on the browser's print settings; the PDF export is the controlled copy). Test `test_ac21_ac29_accountant_exports_every_report`. |
| 5 | SHOULD page totals | DONE | `ReportExporter::carryPages`; B/F, page total, C/F in `cash-book.blade.php:12,23-24` and `transfers.blade.php:9,18-19` (last C/F labelled "= Net moved"). Screen footer "Net moved for the whole period (all pages)". Test `test_ac30_page_carry_forward`. |
| 6 | SHOULD signed reversal amounts | DONE | `ReportService.php:363`; Excel numeric signed cell. Test `test_ac15_ac31_october_equals_e3`. |
| 7 | SHOULD PROVISIONAL marker | DONE | `ReportService::provisional` (`:161-178`, global or per-branch `PeriodLock`, every month x every branch in scope); screen, PDF and Excel. Test `test_ac32_provisional_marker`. |
| 8 | SHOULD cash book reversal cross-refs | DONE | `ReportService::lineText` (`:525-538`), gross presentation. `test_ac18_kdy_cash_equals_e4` asserts "(reversed by <ref>)" / "Reversal of <ref>". |
| 9 | SHOULD percent rules | DONE | `ReportService::percent` (`:262-272`, integer cents, half away from zero, null when total <= 0; category % from category amount). Tests `test_percent_rounding_is_half_away_from_zero`, `test_ac34_negative_line_percent`, `test_ac6_cross_period_reversal_gives_negative_total`. |
| 10 | SHOULD subset-of-branches note | DONE | `subset` flag (`ReportService.php:126`); note on screen, PDF and Excel for comparison and matrix. Test `test_branch_user_and_subset_note`. |
| 11 | SHOULD calendar-year label | DONE | `ReportExporter::periodLabel`, web `periodLabel` and "Year (calendar)" option. |
| 12 | SHOULD (separate story) void deletes entries | NOT DONE - deferred by design | `LedgerService.php:179` still `entries()->delete()`. See view below. |

### Deferred (out of this feature)
- Void keeps entries + posts contra entries (finding 12) -> **AG-063** (edit/void), with **AG-091** (period lock) as the control.
- Fiscal year start (April-March) and company logo upload -> **AG-092**.
- Queued exports above the 1,000 PDF / 5,000 Excel caps, with download link -> **AG-087** (notification **AG-094**).
- Pending / In transit transfer and expense states (never count in totals) -> **AG-051**, **AG-052**, **AG-064**.
- Bank reconciliation report -> **AG-033**; audit trail report -> **AG-090**; Cash Flow Summary -> **AG-086**; scheduled emails -> **AG-088**.

### LedgerService::void hard-delete
Not a release blocker for this feature; it stays a separate backlog item (AG-063). Reasons: (a) the reports already use the only correct basis available today (voided = no entries, excluded everywhere; transaction row, `[Void]` note and `transaction.voided` audit log are kept, so the who/when/why survives); (b) `void` calls `assertOpen` on the original date, so a locked month cannot change, and every report of an unlocked month now prints "PROVISIONAL - period not locked" (finding 7), so nobody signs a figure that can still move; (c) fixing it changes posting logic in `LedgerService`, which is outside the scope of a read-only reporting feature. It remains a conflict with the "nothing is hard-deleted" rule and should be prioritised before go-live with real data: post a contra entry on the void date instead of deleting.

### Housekeeping (non-blocking, BA)
Spec body still shows the pre-review text in 4.3 note, R5 ("Funds received = debits of `capital`, ...") and Q4, and the E-2 table has no Capital column. The code, ACs 26-27 and finding 2 are correct; the BA should update those lines so the spec matches the build.

### Verdict
**SIGNED OFF.** All 4 MUST and 7 in-scope SHOULD findings are done and covered by tests; figures reconcile (opening + in - out = closing per branch, asserted against the ledger; branch rows sum to Total; matrix net sums to 0.00).
