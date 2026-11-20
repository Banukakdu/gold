# Spec: Cash Flow Summary (monthly)

## 1. Goal and scope
Covers **AG-086**: "As a Super Admin, I want a Cash Flow Summary (monthly) so that I see trends." Backlog AC: monthly inflow, outflow, net and cumulative balance, consolidated or per branch, with a chart. Viewable on screen, exportable to PDF and Excel like the other reports (docs/specs/reports.md).

The report answers: how much cash came in and went out each month, how much of that was operating (income/expenses) and how much was funding (capital, allocations, fund returns, branch transfers), and what cash we held at each month end.

Out of scope: a statement of cash flows under accounting standards (indirect method, investing section), forecasts/budgets, per-account breakdown (use the Daily Cash Book), a "By branch" side-by-side table in All mode (see Q3), charts on the dashboard or in PDF/Excel (Q4), queued exports (AG-087), scheduled emails (AG-088).

## 2. Personas and permissions
Keys (`PermissionSeeder::PERMISSIONS`): `report.view` (see the report), `report.export` (PDF/Excel). No new key.

The backlog says "Super Admin", but AG-083 Branch Comparison had the same wording and was delivered under `report.view` with branch isolation. This report follows the same pattern (Q1):

| Persona | Has | Sees |
|---|---|---|
| Super Admin (`is_super_admin`) | everything | All mode: consolidated over all branches or a chosen set; branch mode: that branch |
| Branch Admin template (`*`) | view + export | active branch only |
| Accountant template | view + export | active branch only |
| Viewer template | view only | active branch only; export buttons hidden |
| Cashier template | neither | no menu entry; API 403 |
| Custom role | whatever is ticked | active branch only |

## 3. User flows
**F1 Super Admin, consolidated.** Switcher = All -> Reports -> Cash Flow Summary -> period defaults to this fiscal year to date, no branches ticked (= all branches) -> chart + monthly table load. Inter-branch movements are eliminated (R4); the note shows how much was eliminated.
**F2 Super Admin, one branch or a set.** In All mode, tick KDY only -> figures for KDY, inter-branch movements shown as Transfers in/out. Tick KDY + GAL -> movements between KDY and GAL are eliminated, movements with MAIN stay.
**F3 Branch user.** Switcher = KDY -> report shows KDY only; no Branches picker. Switching branch reloads.
**F4 Trend review.** Pick "Last 12 months" or a custom month range (up to 24 months) -> chart shows the months side by side -> export to Excel for further analysis or PDF for the file.

## 4. Screen `/reports/cash-flow`
New card on the hub `/reports`: "Cash Flow Summary - How much cash came in and went out each month?". Route guarded `permissionGuard('report.view')`; reloads on branch switch. Common report behaviour from reports.md section 4 applies (amount format, loading, errors, export buttons, PROVISIONAL marker, header).

Filters:
| Field | Type | Req | Validation |
|---|---|---|---|
| Period preset | buttons | - | "This fiscal year" (default: FY start month .. current month), "Last fiscal year" (all 12 months), "Last 12 months" (current month and the 11 before) |
| From month / To month | month (YYYY-MM) | yes | `to >= from`; at most 24 months. Client sends `from` = 1st of From month, `to` = last day of To month |
| Branches | multi-select | no | All mode only; from `GET /branches` incl. inactive (tag "Inactive"); none ticked = all branches |

Header line under the title: "Consolidated - all branches" or "Branches: KDY Kandy, GAL Galle" or the active branch.

**Chart** (above the table): one month per x position, every month in the range shown (months with no movement show zero). Top panel: paired bars Inflow and Outflow per month with a marker for Net (positive above, negative below the zero line). Bottom panel, same x axis: line of Closing balance (cumulative) at each month end. Two panels instead of a dual y axis, so the scales are not misread. Built as an inline SVG Angular component (`ag-cash-flow-chart`, no new npm package; `web/package.json` has no chart library and the dashboard trend is already plain CSS). Colours from the existing CSS tokens; works in light/dark; each bar/point has a `<title>` tooltip "Oct 2026 - Inflow 91,500.00"; the SVG has `role="img"` and an `aria-label`; the table below is the accessible source. Width follows the container (`viewBox`), usable at phone width (labels every 2nd/3rd month when narrow).

**Table** (one row per month, oldest first; container scrolls horizontally on phones):
Month | Opening | Income received | Capital introduced | Transfers in | **Total inflow** | Expenses paid | Transfers out | **Total outflow** | **Net** | Operating net | **Closing (cumulative)**
Footer "Total": sums of every flow column; Opening = first month's opening; Closing = last month's closing.
Notes under the table:
- "Inflow = Income received + Capital introduced + Transfers in. Outflow = Expenses paid + Transfers out. Operating net = Income received - Expenses paid. Closing = Opening + Net."
- "Transfers in/out = opening allocations, top-ups, fund returns and branch transfers with branches outside the selection. Internal transfers between accounts of the same branch and year-end closing entries do not move cash and are not shown."
- Consolidated / any set with eliminations: "Inter-branch movements within the selection eliminated: <total>" (per month in an extra "Eliminated" column in Excel only).

Empty state: never empty - every month is listed; if the whole range has no movement: "No cash movements in this period." above the table (opening/closing still shown).

## 5. API
| Method | Path | Perm | Params |
|---|---|---|---|
| GET | `/v1/reports/cash-flow` | report.view | `from`, `to`, `branches[]` |
| POST | `/v1/reports/cash-flow/export` | report.view + report.export | same + `format=pdf\|xlsx` (existing route) |

`ReportService::cashFlow(ReportParams)`; `params('cash-flow', ...)` adds the rules below. Branch resolution exactly as reports.md R1 (403 "You do not have access to branch <code>." etc.).

Response (amounts strings, 2 decimals via `Money`): `meta(...) + {consolidated: bool, months:[{month:"2026-10", opening, income, capital, transfers_in, inflow, expenses, transfers_out, outflow, net, operating_net, closing, eliminated}], total:{same keys; opening = first, closing = last}}`. `consolidated` = all branches in scope (not a subset).

Errors: 422 "Cash flow uses whole months: From must be the first day of a month and To the last day of a month." ; 422 "The range may not be longer than 24 months." ; plus the common 400/403/422 of reports.md.

Export via `ReportExporter`: add `'cash-flow' => 'Cash Flow Summary'` to the titles, `countRows` = number of months (always under the caps), PDF Blade view `reports.pdf.cash-flow` (landscape, the table and notes, common header incl. PROVISIONAL and basis line), Excel one sheet "Cash flow" with the same columns plus "Eliminated", numeric cells `#,##0.00`, header lines and frozen header row as the other reports. File `cash-flow_<from>_<to>.<ext>`; audit row `report.exported` as today. Basis line: "Cash, bank and wallet accounts; posted entries incl. reversals; voided entries excluded".

## 6. Business rules
- **R1 Source.** Ledger lines on money accounts only: `ReportService::moneyLines()` (accounts of type cash / bank / wallet = codes 11001-11999), filtered on `le.branch_id IN` the branch set, by `le.entry_date` with `whereDate`. Never the `transactions.amount` column and never `accounts.current_balance` (only used as a check in AC 9). All accounts incl. inactive/closed.
- **R2 What counts.** As reports.md R2/R3 and its accounting finding 1: lines of transactions with status `posted` or `reversed`, plus their reversals; a reversal line is classified by the original's type, on the original line's side, amount negated, on the reversal date. Voided transactions (status `void`; original lines + contras on the same date) are excluded. So a cross-month reversal gives a negative figure in its month (e.g. Income received -4,000.00), as in Branch Comparison.
- **R3 Columns** per money line (effective side after R2): `income` -> Income received (Dr - Cr); `expense` -> Expenses paid (Cr - Dr); `capital` -> Capital introduced (Dr - Cr); `opening_allocation`, `fund_allocation`, `fund_return`, `branch_transfer` -> Transfers in (Dr) / Transfers out (Cr), subject to R4; `internal_transfer` -> not shown (both lines in the same branch, same date: net 0). `year_end_close` posts only to category, inter-branch and Retained earnings accounts (`Account::CLOSE_TYPES`), never to a money account, so it never appears. Any other type on a money line is unexpected and is caught by R6.
- **R4 Inter-branch elimination.** A transfer line counts in Transfers in/out only when the counterparty branch (the other of the transaction's `branch_id` / `related_branch_id`; a reversal keeps the original's pair) is **outside** the branch set. Lines whose counterparty is inside the set are summed into `eliminated` (the in side) and left out of inflow/outflow; both legs post on the same date, so they cancel in the same month. Consequences: one branch = every transfer counts; all branches = Transfers in/out are 0.00 every month; a subset eliminates only movements between selected branches. Capital is never eliminated (external money).
- **R5 Opening and cumulative.** Opening of the first month = SUM(debit - credit) of money lines in scope dated before `from` (all statuses; voided lines net to 0). Each month: Closing = Opening + Net; next month's Opening = this Closing. Months: every calendar month From..To, including months with no lines.
- **R6 Reconciliation.** For every month the computed Closing must equal SUM(debit - credit) of money lines in scope up to that month end. Mismatch = 500 + `Log::error` (no raw text to the user), as Branch Comparison. Per branch and month, the figures must agree with Branch Comparison for that month: Income, Expenses, Capital equal; Transfers in = Funds received + Transfers in; Transfers out equal.
- **R7 Period.** Fiscal year from `Settings::fiscalYearStartMonth()` (default April). Default range = current fiscal year start month .. current month (business date, Asia/Colombo). Whole months only; max 24 months. Period label follows reports (e.g. "FY 2026-27, ..." when the range is a full fiscal year).
- **R8** Money in integer cents (`Money`, `Money::sqlCents`), no floats; read-only (nothing posts, nothing writes balances). Single currency (setting). Transfer receipt status (AG-052) does not matter: the ledger posts on the transfer date.

## 7. Worked example (same E-dataset as reports.md section 7; fiscal year starts April)
Branches MAIN, KDY, GAL. Money accounts: MAIN Bank, KDY Cash, KDY Bank, GAL Cash. Movements: 09-01 Capital MAIN Bank 1,000,000.00; 09-02 Opening allocation MAIN->KDY 200,000.00 and MAIN->GAL 100,000.00; 09-20 Expense KDY 30,000.00; October (1)-(13) as in reports.md (incl. (8) internal KDY Cash->KDY Bank 40,000.00, (10) expense 5,000.00 reversed 10-14, (11) GAL->KDY 10,000.00 reversed 10-16, (12) voided expense 15,000.00, (13) GAL income 4,000.00 on 10-30 reversed 11-02).

Columns: Opening | Income | Capital | Trf in | Inflow | Expenses | Trf out | Outflow | Net | Op. net | Closing | Eliminated

**CF-1 Consolidated (All mode, no branches ticked), 2026-09..2026-11:**
| Month | Open | Inc | Cap | In | Inflow | Exp | Out | Outflow | Net | Op net | Close | Elim |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 2026-09 | 0.00 | 0.00 | 1,000,000.00 | 0.00 | 1,000,000.00 | 30,000.00 | 0.00 | 30,000.00 | 970,000.00 | -30,000.00 | 970,000.00 | 300,000.00 |
| 2026-10 | 970,000.00 | 91,500.00 | 0.00 | 0.00 | 91,500.00 | 10,000.00 | 0.00 | 10,000.00 | 81,500.00 | 81,500.00 | 1,051,500.00 | 150,000.00 |
| 2026-11 | 1,051,500.00 | -4,000.00 | 0.00 | 0.00 | -4,000.00 | 0.00 | 0.00 | 0.00 | -4,000.00 | -4,000.00 | 1,047,500.00 | 0.00 |
| Total | 0.00 | 87,500.00 | 1,000,000.00 | 0.00 | 1,087,500.00 | 40,000.00 | 0.00 | 40,000.00 | 1,047,500.00 | 47,500.00 | 1,047,500.00 | 450,000.00 |

(October Eliminated 150,000.00 = E-3 "Net moved"; October closing 1,051,500.00 = E-2 Total closing.)

**CF-2 Single branch, 2026-10:**
| Branch | Open | Inc | Cap | In | Inflow | Exp | Out | Outflow | Net | Close |
|---|---|---|---|---|---|---|---|---|---|---|
| MAIN | 700,000.00 | 0.00 | 0.00 | 20,000.00 | 20,000.00 | 0.00 | 100,000.00 | 100,000.00 | -80,000.00 | 620,000.00 |
| KDY | 170,000.00 | 62,500.00 | 0.00 | 100,000.00 | 162,500.00 | 8,200.00 | 30,000.00 | 38,200.00 | 124,300.00 | 294,300.00 |
| GAL | 100,000.00 | 29,000.00 | 0.00 | 30,000.00 | 59,000.00 | 1,800.00 | 20,000.00 | 21,800.00 | 37,200.00 | 137,200.00 |
KDY: (8) internal 40,000.00 not shown; (10) 5,000.00 and its reversal net to 0 in Expenses; (11) +10,000.00 and -10,000.00 net to 0 in Transfers in. GAL: (12) void not shown; (11) nets to 0 in Transfers out. MAIN September: Capital 1,000,000.00, Transfers out 300,000.00, Closing 700,000.00. GAL November: Income -4,000.00, Net -4,000.00, Closing 133,200.00.

**CF-3 Subset KDY + GAL, 2026-10:** Opening 270,000.00; Income 91,500.00; Transfers in 100,000.00 (top-up from MAIN); Inflow 191,500.00; Expenses 10,000.00; Transfers out 20,000.00 (fund return to MAIN); Outflow 30,000.00; Net 161,500.00; Closing 431,500.00 (= 294,300.00 + 137,200.00); Eliminated 30,000.00 ((7); (11) and its reversal net 0).

**CF-4 Default period** (today 2026-11-20, FY start April): range 2026-04..2026-11, 8 rows; April-August all 0.00 with Opening/Closing 0.00; September-November as CF-1.

## 8. Acceptance criteria
1. Given the E-dataset and Super Admin in All mode, When GET cash-flow `from=2026-09-01&to=2026-11-30` without `branches[]`, Then `consolidated` = true and months and total equal CF-1.
2. Given the same, When `branches[]` = KDY (then MAIN, then GAL) for `2026-10-01..2026-10-31`, Then the month equals the CF-2 row for that branch, and MAIN for September has Capital 1,000,000.00, Transfers out 300,000.00, Closing 700,000.00.
3. Given `branches[]` = KDY and GAL for October, Then the month equals CF-3.
4. Given any range and branch set in the E-dataset, Then internal transfer (8), voided expense (12) and its contras never change any figure, and Inflow - Outflow = Net and Opening + Net = Closing on every row.
5. Given (10) (expense 10-12, reversed 10-14) and (11) (transfer 10-15, reversed 10-16), When the report runs for KDY October, Then Expenses = 8,200.00 and Transfers in = 100,000.00. Given (13), When it runs for GAL November, Then Income received = -4,000.00 and Closing = 133,200.00.
6. Given All mode with no branches ticked, When run for any range, Then Transfers in and Transfers out are 0.00 in every month, and October Eliminated = 150,000.00 (= Transfers & Allocations "Net moved" for October).
7. Given each branch and each month September-November, When compared with Branch Comparison for the same branch and month, Then Income, Expenses, Capital and Transfers out are equal, Transfers in = Funds received + Transfers in, and Closing is equal.
8. Given any request, When the computed closing of any month differs from SUM(debit - credit) of money lines in scope up to that month end (forced in a test, e.g. a money line whose transaction type the report does not classify), Then the API returns 500 with no PHP text and an error is logged.
9. Given the E-dataset, When the report runs All mode to 2026-11-30, Then the last Closing 1,047,500.00 equals the sum of `current_balance` of all cash/bank/wallet accounts (620,000.00 + 254,300.00 + 40,000.00 + 133,200.00); for KDY, 294,300.00 = KDY Cash + KDY Bank.
10. Given a fiscal year that has been closed (AG-092 year-end close posted), When the report runs over that year before and after the close and after reopening, Then every figure is identical and no `year_end_close` transaction has a line on a money account.
11. Given today 2026-11-20 and fiscal year start April, When the screen opens, Then From = 2026-04, To = 2026-11 and 8 rows show (CF-4); "Last fiscal year" gives 2025-04..2026-03; "Last 12 months" gives 2025-12..2026-11. With fiscal year start January, "This fiscal year" gives 2026-01..2026-11.
12. Given `from=2026-10-02` or `to=2026-10-30`, Then 422 with the whole-months message; Given a range of 25 months, Then 422 "The range may not be longer than 24 months."; Given exactly 24 months, Then 200 with 24 rows.
13. Given a KDY Accountant (X-Branch-Id = KDY), When the report runs without `branches[]`, Then it equals the KDY figures; When `branches[]` = GAL's id, Then 403 "You do not have access to branch GAL."
14. Given a Cashier, When calling GET cash-flow, Then 403 and the hub has no Cash Flow card; Given a Viewer, Then data loads, export buttons are hidden, and POST export returns 403.
15. Given CF-1 on screen, Then the chart shows 3 month positions with Inflow/Outflow bars and Net markers (November Net below zero) and a closing-balance line through 970,000.00, 1,051,500.00, 1,047,500.00; hovering a bar shows its month, series and amount; at 375 px width the page has no horizontal page scroll (only the table container scrolls).
16. Given an Accountant, When exporting CF-1's range as xlsx and pdf, Then `cash-flow_2026-09-01_2026-11-30.<ext>` downloads with the same rows and totals as the screen, the common header (company, title "Cash Flow Summary", branches, period, currency, basis, printed by, PROVISIONAL when unlocked), and an audit row `report.exported` with name `cash-flow`.
17. Given incomes 0.10 and 0.20 in one month, Then Income received is exactly 0.30 on screen and in both exports.
18. Given a deactivated branch or account with history, When the report runs in All mode, Then its movements are included and the branch is tagged "Inactive" in the picker.

## 9. Open questions (assumption in force)
- Q1 Backlog says "Super Admin". Assumed `report.view` with branch isolation (same as AG-083); consolidated and multi-branch views stay Super Admin only through All mode. Product owner to confirm.
- Q2 Reversal presentation: assumed classified by the original with negated amount (a reversed income reduces Income received, can be negative), to stay consistent with Branch Comparison (R6 cross-check). Alternative: show it gross as an outflow. Accountant to confirm.
- Q3 "Per branch": assumed met by picking one branch; a side-by-side "By branch" grid in All mode is not built.
- Q4 Chart on screen only; PDF/Excel carry the table (dompdf SVG support is limited; Excel charts not needed for analysis). Can be added later.
- Q5 No chart library: inline SVG, no new npm package. If the team prefers a library (e.g. Chart.js), adding it to `web/package.json` needs approval and a lock-file commit.
- Q6 Range cap 24 months (two fiscal years side by side); custom ranges are whole months only.
- Q7 Transfers in/out lump allocations, top-ups, fund returns and branch transfers (Branch Comparison still separates Funds received). Split later if needed.
