# Spec: Transaction search, filters and Excel export

## 1. Goal and scope
**AG-065** (Must, R1) "As a user, I want to search and filter the transaction list so that I can find entries quickly."
Backlog acceptance: filters for date range, type, account, category, sub-category, status, created by, amount range and text; server-side pagination; export of the filtered list to Excel.

Built on `feature/transaction-search`, which already has double entry, attachments (`files` filter), year-end close entries, categories with effective inactive status, and report exports. The list `GET /v1/transactions` (`TransactionController::index`) and the screen `/transactions` (`TransactionListComponent`) already exist with part of this. This spec fixes the semantics, closes the gaps and adds the export.

**What is there today and what changes** (read from the code):

| Area | Today | Change |
|---|---|---|
| `from`/`to` | `nullable\|date`, `whereDate` | `date_format:Y-m-d`, `to >= from` (R2) |
| `type` | comma list, not validated | each value must be a known type (R3) |
| `status` | one value, `where('status', …)` | comma list of `posted,void,reversed` (R4) |
| `account_id` | `account_id` OR `from_account_id` OR `to_account_id` | unchanged (R5) |
| `category_id` | includes children unless `category_only=1` | unchanged; UI gets a Sub-category field (R6) |
| `created_by` | exact user id; options from `GET /transactions/creators` | creators endpoint follows the same scope and `branches[]` (R7) |
| `min`/`max` | `numeric`, raw compare with `amount`; `max=0` ignored | cents compare via `Money::sqlCents`, strict format, year-end close excluded (R8) |
| `q` | `LIKE "%$v%"` on 4 fields, **not escaped** (`%` and `_` act as wildcards) | escaped with `!` like `UserController` (R9) |
| Branch scope | `BranchScoped` on `transactions.branch_id` only: a branch never sees top-ups or transfers it **received** | sending **or** receiving branch (R10, Q1) |
| Sort | fixed `txn_date desc, id desc` | `sort`/`dir`, `id` tiebreak (R12) |
| URL | only `from,to,type,category_id,category_only,files,branches[]` read once on load | every filter, sort and page in the URL, two-way (S1) |
| Export | none | `POST /v1/transactions/export`, xlsx, cap 5,000, void and reversal evidence columns, audit `report.exported` with the resolved scope (R14) |
| Phone | 12-column table, filters inline | collapsible filter panel and card rows below 760px (S1) |

Out of scope: saved/named filters (a bookmarked URL does the job, Q4); PDF export of the list; a totals row (Q3); full-text indexes; searching inside attachment files; filtering by chart (ledger) accounts or ledger lines; cursor pagination; bulk actions on the filtered list.

## 2. Personas and permissions
No new permission key. Real keys from `PermissionSeeder`: `transaction.view`, `report.export`.

| Action | Rule |
|---|---|
| See the list, use every filter, load filter options | `transaction.view` (route `perm:transaction.view`, unchanged) |
| Export to Excel | `transaction.view` **and** `report.export` (route `perm:transaction.view,report.export`; both are required, as for `reports/{name}/export`) |
| Void / Reverse buttons on a row | unchanged rules, and only when the row's `in_active_branch` is true (R10) |

| Template | List + filters | Export |
|---|---|---|
| Super Admin | all branches (All mode) or the active branch | yes |
| Branch Admin (`*`) | active branch | yes |
| Accountant | active branch | yes |
| Viewer | active branch | no (button hidden) |
| Cashier | active branch | no (button hidden) |

`report.export` is reused on purpose: it already means "may take data out of the system in a file", and a new key would be granted automatically to every Branch Admin (`*`) anyway.

## 3. User flows
**F1 Branch user (Accountant, KDY).** Transactions -> list shows the newest 25 KDY entries (own entries and incoming top-ups/transfers) -> types "CEB" in Search, picks Type = Expense, Category = Utilities, Sub-category = Electricity, From 2026-10-01 To 2026-10-31 -> Apply -> the URL becomes `/transactions?from=2026-10-01&to=2026-10-31&type=expense&category_id=…&q=CEB` -> 3 rows, "3 entries" -> Export to Excel -> `transactions_2026-10-01_2026-10-31.xlsx` with the same 3 rows -> copies the URL into a chat; a colleague in KDY opens it and sees the same list.
**F2 Super Admin, All mode.** Switcher = All -> Transactions -> Branch column visible, Branches picker shown -> ticks GAL -> Apply -> GAL's own entries plus transfers and top-ups received by GAL (tag "In") -> sorts "Amount, highest first".
**F3 Super Admin in branch mode.** Switcher = GAL -> same as F1 for GAL; no Branches picker; a `branches[]` value from an All-mode link is dropped with the note "The branch filter applies in All branches mode only."
**F4 Cashier on a phone (390px).** Transactions -> "Filters" button (collapsed) -> opens panel -> sets Created by = self, From = today -> Apply -> panel closes, chips "From 18 Nov 2026 ×", "Created by: Nimal ×" above the card list -> taps × on a chip to remove that filter.
**F5 Drill-down from Income & Expense** (reports spec F3) keeps working: the link opens `/transactions` with `from,to,type=income,reversal,category_id,category_only=1,branches[]`; the Sub-category field shows the picked line.

## 4. Screens
### S1 Transactions `/transactions` (change `TransactionListComponent`)
Guard `permissionGuard('transaction.view')` (unchanged). Reloads on branch switch (keeps the URL filters, drops `branches[]` when not in All mode).

**Filter panel** (a `<form>`, submit = Apply):

| Field | URL / API param | Control | Validation (client mirrors server) |
|---|---|---|---|
| From | `from` | date | Y-m-d |
| To | `to` | date | Y-m-d, not before From: "To must be on or after From." |
| Type | `type` (comma list) | checkbox picker (`details.picker`, as reports) with `TYPE_LABELS`; summary "All types" or "Income, Expense" | known types only |
| Status | `status` (comma list) | checkboxes Posted / Void / Reversed; none ticked = any | `posted`, `void`, `reversed` |
| Account | `account_id` | select "Any account" + `GET /accounts` (money accounts; All mode grouped by branch code "KDY – Cash"); inactive accounts tagged "(inactive)" | integer |
| Category | `category_id` | select "Any category" + `GET /categories?include_inactive=1`, grouped "Income" / "Expense", top-level only; effectively inactive tagged "(inactive)" | integer |
| Sub-category | `category_id` + `category_only` | select, enabled when a Category is chosen: "All sub-categories" (default), "(no sub-category)", then its children; a sub is "(inactive)" when it or its parent is inactive | — |
| Created by | `created_by` | select "Anyone" + `GET /transactions/creators` (R7), names with tag "(inactive)" / "(no longer in this branch)" | integer |
| Amount from / to | `min`, `max` | text, `inputmode="decimal"`, commas stripped before sending | `^\d{1,13}(\.\d{1,2})?$`, ≥ 0.01, to ≥ from: "Amount to must be at least Amount from." |
| Search | `q` | text, placeholder "Ref no, payee/payer, reference, description" | max 100 characters after trim |
| Files | `files` | select, unchanged (Any / Has files / No files / Has removed files) | `with,none,removed` |
| Branches | `branches[]` | All mode only: checkbox picker, none ticked = all branches | integers, existing branches |
| Sort | `sort`, `dir` | select: Date, newest first (default) / Date, oldest first / Amount, highest first / Amount, lowest first | `date\|amount`, `asc\|desc` |
| Rows per page | `per_page` | select 25 (default) / 50 / 100 | — |

Buttons: **Apply** (primary), **Clear all** (removes every filter param, keeps sort and per_page, goes to page 1), **Export to Excel** (only if `auth.can('report.export')`; disabled when the list total is 0 or a request is running; label "Exporting…" while running).

Selecting a Sub-category sends `category_id = <sub id>` (no `category_only`). "(no sub-category)" sends `category_id = <parent id>&category_only=1`. "All sub-categories" sends `category_id = <parent id>` only. On load, a `category_id` that is a sub selects its parent in Category and the sub in Sub-category; an id not in the options shows Category "Category #<id>" (keeps working, see Q5). The reports drill-down `category_name` param is still accepted for the label but no longer needed.

**URL state.**
- Apply, Clear all, a chip × and a sort change call `router.navigate([], { queryParams })` with empty values removed and `page` reset to 1 (pushes a history entry). Paging and rows-per-page use `replaceUrl: true`.
- The component subscribes to `queryParamMap`: Back/Forward and opening a shared link load exactly the list the URL describes. Defaults (`sort=date`, `dir=desc`, `per_page=25`, `page=1`) are left out of the URL.
- Param names are the API names, so the URL is the API query. `branches` uses repeated `branches=` params in the browser URL and `branches[]` to the API.
- A URL the API rejects (422) shows the API message in `.alert` with a "Clear filters" link; the list is empty.

**Table columns** (wide screens): Date (`appDate`, the date format setting) · Ref (link to `/transactions/:id`) · Branch (All mode only) · Type · Category (`Parent › Sub`, "(inactive)" if effectively inactive) · Account (`Cash` or `KDY Cash → GAL Bank` for transfers) · Details (payee/payer or description) · Amount · Status · By (with tag) · Files · actions.
- **Void** income/expense: row struck through, tag "Void", legacy note as today. Shown by default.
- **Reversed** transfer: tag "Reversed", small line "by <reversal ref>" (link). Shown by default.
- **Reversal** row: Type "Reversal", Status "Reversal of <ref>" (link to the original), as `ReportExporter::statusLabel`. Shown by default.
- **Year-end close**: Type "Year-end close", Amount "—" (settings spec S7), no actions. A closing entry cancelled by reopening is Void (struck). Shown by default.
- **Incoming** row (R10, branch not in the scope set): tag "In" after the Type and no Void/Reverse buttons.
- Empty: "No transactions match these filters." with a "Clear filters" link (no filters: "No transactions yet.").
- Pager: "Previous" / "Page X of Y · N entries" / "Next". A page past the end (e.g. a link with `page=9` when there are 3 pages) navigates to the last page with `replaceUrl`.

**Active filter chips** above the table: one chip per active filter ("Type: Income, Expense ×", "Amount: 1,000.00 to 5,000.00 ×", "Search: "CEB" ×"). × removes that filter and applies.

**Phone layout (< 760px, the existing breakpoint; checked at 390px).**
- The filter panel is collapsed behind a full-width button "Filters" / "Filters (3)" (count of active filters), `aria-expanded`, min height 44px. Open: fields stack one per row, full width; Apply / Clear all / Export are full width, 44px high. Apply closes the panel.
- The panel is open by default only when it has a validation error.
- Chips wrap under the button. Sort stays visible outside the panel as a full-width select.
- Rows become cards (no table header): line 1 date · ref (link) · amount right-aligned; line 2 type · category or account; line 3 status tag · by · files count; actions as 44px buttons. No horizontal page scroll at 390px. Long payee/description text wraps (`overflow-wrap: anywhere`).
- Pager buttons are 44px; the text line wraps under them.

## 5. API (all under `auth:sanctum` + `branch`)
| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `GET /v1/transactions` | `perm:transaction.view` (unchanged) | the filter params in S1 + `page`, `per_page` (`in:25,50,100,200`; 200 stays for existing callers), `sort`, `dir` | Laravel paginator as today. Each row adds `in_active_branch` (bool), `direction` (`in` when the row's `branch_id` is not in the scope set, else null), `reversal_of_ref`, `reversed_by_ref`. 403 "You do not have access to branch KDY." for `branches[]` in branch mode naming another branch (unchanged). 422 with field errors (R2–R9) |
| `GET /v1/transactions/creators` | `perm:transaction.view` | `branches[]` (same rules) | `[{id, name, tag}]` of users who created at least one row in the scope set (R7) |
| `POST /v1/transactions/export` | `perm:transaction.view,report.export` | the same filter params, `sort`, `dir`; `format` optional, `in:xlsx` | the xlsx file, `Content-Disposition: attachment; filename="transactions_<from\|all>_<to\|all>.xlsx"`. 422 "This export has 6,214 rows; the limit is 5,000. Narrow the filters." Same 403/422 as the list |

The list and the export share one query builder (e.g. a private `TransactionController::filtered(Request): [Builder, array $filters]`), so the export always holds exactly the rows of the list with the same filters and sort.

## 6. Business rules and edge cases
**R1 Combination.** All filters are ANDed. A filter with an empty value is ignored. Whitespace-only `q` is ignored.

**R2 Dates.** `from`/`to` are `date_format:Y-m-d`; `to` must be `after_or_equal:from` ("To must be on or after From."). Both bounds inclusive on `txn_date` with `whereDate`. No default date range (all dates).

**R3 Type.** `type` is a comma list; each value must be one of `Transaction` types: `capital, opening_allocation, fund_allocation, fund_return, branch_transfer, internal_transfer, income, expense, reversal, year_end_close`. Unknown → 422 "Unknown type: <value>.". `whereIn('type', …)`.

**R4 Status.** Comma list of `posted, void, reversed`. Anything else (including `pending`, `rejected`, reserved for AG-064/AG-051) → 422 "Unknown status: <value>.". Default (no status): **every** status, so void, reversed, reversal and closing entries all appear (S1 says how). A reversal row has status `posted`; "Reversed" matches the originals only. To see reversals use Type = Reversal.

**R5 Account.** Matches the entry's money account: `account_id` (income, expense, capital) **or** `from_account_id` **or** `to_account_id` (transfers, top-ups, reversals). One account only. Year-end close entries have no money account and never match. An account id from another branch is not an error: it can only match rows already in scope (e.g. KDY's transfers to that GAL account).

**R6 Category and sub-category.** `category_id` of a top-level category matches entries on that category **and on all its sub-categories** (any branch's sub-categories, active or not), unless `category_only=1` (that node only = "(no sub-category)"). `category_id` of a sub matches that sub only. Inactive categories stay filterable (history). Category filters only ever match income and expense (others have no category). In All mode the Category options are global only (categories spec), but the parent match still includes branch-only subs.

**R7 Created by.** Exact `created_by`. Options come from `GET /transactions/creators`: users who created at least one entry **in the scope set** (R10, including `branches[]`), so a KDY user never sees names of users who only work in GAL. Former members and inactive users stay listed with their tag (accounting review finding 3). Members who never created an entry are not listed. `created_by` is validated as an integer only (no `exists` rule, so the response does not reveal whether a user id exists); an id outside the options returns no rows.

**R8 Amount range.** `min`/`max` match `^\d{1,13}(\.\d{1,2})?$`, ≥ 0.01; `max ≥ min` ("Amount to must be at least Amount from."). Converted with `Money::toCents` and compared in cents: `whereRaw(Money::sqlCents('transactions.amount').' >= ?', [$cents])` (exact on MySQL decimal and on SQLite, no float compare). Both inclusive. The amount compared is the entry's stored `amount`, always positive:
- income / expense / capital: the amount;
- transfers, top-ups, fund returns, internal transfers: the amount transferred (one figure, the same for sender and receiver);
- reversal: its own amount (equal to the original's);
- **year-end close: excluded** whenever `min` or `max` is set (its amount is total closing debits, not money moved).

**R9 Text search.** `q` (trimmed, 1–100 characters) is one phrase, matched as "contains" on `ref_no`, `payee_payer`, `reference`, `description`. LIKE wildcards are escaped: `$like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%'` with `whereRaw("<col> like ? escape '!'", [$like])` (the `UserController` pattern, works on MySQL and SQLite). So `50%` finds "50% advance" and not "500", and `_` is literal. Case-insensitive on MySQL (`utf8mb4` collation); on SQLite only for ASCII (tests use ASCII). A void reason is searchable because void appends "[Void] <reason>" to the description. Account names, category names and user names are not searched (they have their own filters).

**R10 Branch isolation and scope set.**
- **Scope set:** branch mode (any user, Super Admin with a branch picked) = {active branch}. All mode (Super Admin only) = `branches[]` if given, else every branch (active and inactive).
- **Rows in scope:** `transactions.branch_id IN set` **or** `transactions.related_branch_id IN set`. The second leg brings in top-ups, allocations and branch transfers **received** by the branch, and their reversals (they copy `related_branch_id`), the same rule as the reports transfer list (reports R1). Implementation: `Transaction::withoutGlobalScope('branch')` plus that explicit `where`, with a comment; this is the only place the list drops the scope, and the scope set comes from `BranchContext` only.
- `branches[]` in branch mode may only name the active branch, else 403 (unchanged). The web app does not send it outside All mode.
- **The scope set is built only from `BranchContext`** (set by `SetBranchContext` from `X-Branch-Id`), never from request params alone (accounting review finding 3). `branches[]` only narrows it, and only in All mode (Super Admin). An account, category or created-by filter can never widen it: every query is `scope AND filters`.
- `in_active_branch` = All mode, or `branch_id` = active branch. Void / Reverse / Edit / Files actions follow `transactions.branch_id` (double-entry R10).
- **Incoming rows are read-only everywhere** (accounting review finding 5): received top-ups, allocations, fund returns and branch transfers, and their reversals, are listed but the receiving branch gets no Void / Reverse / Edit / Files action on screen, and the API answers 404 for `POST /transactions/{id}/reverse`, `void`, `PUT` and attachment upload from the receiving branch. Those actions stay with the sending branch.
- The creators options (R7), the export (R14) and the counts use the same scope set.

**R11 Pagination.** Offset pagination (`paginate`), `per_page` 25 default. The total is the count of the filtered rows.

**R12 Sorting and stability.** `sort=date` (default) orders `txn_date dir, id dir`; `sort=amount` orders `(type = 'year_end_close') asc, amount dir, id dir` (closing entries always last, their amount is not comparable). `dir` default `desc`. `id` as the last key makes the order total, so the same request always returns the same page with no row on two pages and none skipped (as long as no entry is posted or voided in between; a new entry may shift rows by one, which is accepted).

**R13 Performance.**
- Existing indexes: `(branch_id, txn_date)`, `(type, status)`, unique `ref_no`. On MySQL every foreign key (`account_id`, `from_account_id`, `to_account_id`, `related_branch_id`, `category_id`, `created_by`, `reversal_of_id`) already has its own index, so the account, category and created-by filters need nothing new. InnoDB appends the primary key to every secondary index, so `(branch_id, txn_date)` already serves `ORDER BY txn_date, id` in branch mode.
- New migration `2026_11_24_000000_transaction_search_indexes` (ADD only, each step checks `Schema::hasIndex` first so it is resumable; no FK index is dropped, avoiding MySQL error 1553):
  - `transactions_txn_date_id_index (txn_date, id)`: All mode with no branch filter sorts the whole table; without it MySQL scans and filesorts every row for page 1.
  - `transactions_related_branch_txn_date_index (related_branch_id, txn_date)`: the incoming leg of R10, so `branch_id = ? OR related_branch_id = ?` can use an index merge instead of a full scan.
- Not indexed: `amount` (filtered after branch/date narrow the rows), `q` (`%…%` cannot use a B-tree index; acceptable at R1 volumes, FULLTEXT is out of scope).
- SQLite (tests) does not need the indexes; the migration runs there too.
- Eager loads stay (no N+1): add `reversalOf:id,ref_no` and a `reversedBy` (hasOne on `reversal_of_id`) `:id,ref_no,reversal_of_id`.

**R14 Excel export.**
- Same filters, same scope (R10), same sort (R12). No `page`/`per_page`: all matching rows.
- Cap `ReportService::EXPORT_CAP` (5,000), checked with a `count()` before building: over → 422 "This export has 6,214 rows; the limit is 5,000. Narrow the filters." Nothing is written and nothing is audited. Zero rows → a file with the header and no data rows (the button is disabled at 0 anyway).
- Built with `ReportWorkbook` (one sheet "Transactions") so cells are typed explicitly and text starting with `= + - @` is never a formula.
- **Header lines** (each a row above the table): company name; `Transactions`; `Branches: KDY Kandy` / `All branches` / `KDY Kandy, GAL Galle`; `Period: <from> to <to>` in the date format setting (`Settings::formatDate`), or `All dates`, `From <date>`, `Up to <date>`; `Filters: Type: Income, Expense; Status: Void; Account: KDY Cash; Category: Utilities › Electricity; Created by: Nimal Perera; Amount: 1,000.00 to 5,000.00 (or `from 1,000.00` / `up to 5,000.00`); Search: "CEB"; Files: No files` (or `Filters: none`); `Sort: Date, newest first`; `Rows: 3`; `Amounts in LKR`; `Printed by <name> at <date> <HH:mm> (Asia/Colombo)`. When a year-end close row is included: `Year-end close amounts are not money moved and are shown as —.`
- **Columns:** Date (`date` cell, Excel number format = `Settings::excelDateFormat()`) · Ref no · Branch (code) · Type (label; incoming rows get " (in)", e.g. "Branch transfer (in)", "Reversal (in)") · Category · Sub-category · Account · From · To · Payee / payer · Payment method · Reference · Description · Amount (`amount` cell; year-end close: text "—") · Status (`Posted`, `Void`, `Reversed by <ref>`, `Reversal of <ref>`) · Reversal of (ref) · Reversed by (ref) · Voided on (`date` cell) · Voided by · Void reason · Created by (with "(inactive)" / "(no longer in this branch)") · Files (active count).
  - **Void evidence** (accounting review finding 1): a void posts its contra lines later than `txn_date`, so Voided on / Voided by / Void reason are filled from the `transaction.voided` audit row (for a closing entry cancelled by reopening the year: `transaction.year_end_reopened`): its date (Asia/Colombo), its user and its `reason`. Loaded in one query for the exported ids (no N+1). Empty for rows that are not void, and for legacy voids that have no audit row.
  - **Reversal links** (accounting review finding 2): Reversal of = the original's ref on a reversal row; Reversed by = the reversal's ref on a reversed original. Both directions, not only in the Status text.
  - Income/expense/capital: Account = account name, From/To empty. Transfers and reversals: Account empty, From/To = `<branch code> – <account name>`. Category = top-level name, Sub-category = sub name or empty; "(Inactive)" appended as in the I&E export.
  - Amounts are as stored (positive); the Type column gives the direction (Q3). No totals row.
- **Audit:** after the file is built, `AuditLog::record('report.exported', null, null, ['name' => 'transactions', 'format' => 'xlsx', 'params' => <filters as given, empty ones removed, plus sort, dir and `scope_branch_ids`>, 'rows' => N], $branchId)`. `scope_branch_ids` is the resolved scope set of R10, sorted ascending (e.g. `[<KDY id>]` in branch mode, the `branches[]` ids, or every branch id in All mode with no branch filter), so the evidence does not depend on the reader knowing the mode (accounting review findings 4 and 10). `$branchId` = the active branch in branch mode; in All mode the single `branches[]` id when exactly one, else null (Super Admin rows). The audit log shows it in the "Reports" group with subject "Transactions": add `'transactions' => 'Transactions'` to the subject lookup in `AuditLogController` (line ~368) without adding `transactions` to `ReportService::NAMES` / `ReportExporter::TITLES` (that would open `reports/transactions/export`).
- Synchronous, like report exports. Rate: the existing reports export has no throttle; none added (Q6).

**R15 Nothing changes data.** Search and export never post, never touch `accounts.current_balance` or `ledger_entries`, and only the export writes an audit row.

## 7. Acceptance criteria
Fixture (KDY branch, unless stated): E1 income 2026-10-05 "Retail" › "Walk-in" 12,500.00 payee "CEB refund" ref INC-1 by Nimal; E2 expense 2026-10-10 Utilities › Electricity 4,200.00 payee "CEB" by Kamal; E3 expense 2026-10-12 Utilities (no sub) 50.00 description "50% advance" by Kamal; E4 expense 2026-10-15 Rent 500.00 description "Rent_Oct" voided with reason "duplicate"; E5 top-up MAIN Cash → KDY Cash 2026-10-01 100,000.00 by Super Admin (branch_id MAIN, related_branch_id KDY); E6 branch transfer KDY Cash → GAL Bank 2026-10-20 3,000.00, then reversed by E7 on 2026-10-21; E8 GAL income 2026-10-06 900.00 by Saman (GAL only); E9 KDY year-end close FY 2025-26 dated 2026-03-31, amount 80,000.00, by the Super Admin. E1–E4 and E6 use KDY Cash; E4 is by Kamal; E6 and its reversal E7 are by Nimal.

**Filters**
1. Given the fixture, When a KDY Accountant opens `/transactions` with no filters, Then the rows are E7, E6, E4, E3, E2, E1, E5, E9 (date desc, id desc), E8 is absent, and the total is 8.
2. Given E5, When KDY lists, Then E5 is present with `direction = in`, `in_active_branch = false`, tag "In", and no Reverse button; `POST /transactions/{E5}/reverse` as the KDY Accountant returns 404. As GAL (Branch Admin), `POST /transactions/{E6}/reverse` and a file upload on E6 return 404, E6 and E7 are listed with `in_active_branch = false` and no actions, and in GAL's export E7's Type reads "Reversal (in)" (accounting review finding 5).
3. When `from=2026-10-10&to=2026-10-15`, Then E2, E3, E4 only (both bounds inclusive). When `to=2026-10-01&from=2026-10-02`, Then 422 on `to` "To must be on or after From.". When `from=10/01/2026`, Then 422 on `from`.
4. When `type=expense`, Then E2, E3, E4. When `type=income,reversal`, Then E1, E7. When `type=foo`, Then 422 "Unknown type: foo.".
5. When `status=void`, Then E4 only. When `status=reversed`, Then E6 only (not E7). When `status=posted,void`, Then every row except E6. When `status=pending`, Then 422.
6. When `account_id=<KDY Cash>`, Then E1, E2, E3, E4, E5, E6, E7 (E5 via `to_account_id`, E6/E7 via from/to) and not E9.
7. When `category_id=<Utilities>`, Then E2 and E3. When `category_id=<Utilities>&category_only=1`, Then E3 only. When `category_id=<Electricity>`, Then E2 only. Given Electricity is deactivated (or only its parent is), Then the same results, and the Sub-category option reads "Electricity (inactive)".
8. When `created_by=<Kamal>`, Then E2, E3, E4. When `created_by=<Saman>` (GAL only), Then 0 rows and HTTP 200. When `created_by=abc`, Then 422.
9. Given `GET /transactions/creators` in KDY, Then it lists exactly Kamal, Nimal and the Super Admin (E5, E9), and not Saman. Given Kamal is removed from KDY, Then he is listed with tag "no longer in this branch".
10. When `min=500&max=4200`, Then E2 (4,200.00), E4 (500.00), E6 and E7 (3,000.00): both bounds inclusive. When `min=80000`, Then E5 only (E9 is never matched by an amount bound). When `min=4200.001`, `min=-5`, `min=1e3` or `min=1,000`, Then 422. When `min=5000&max=100`, Then 422 "Amount to must be at least Amount from.". When `max=0`, Then 422 (not ignored).
11. When `q=CEB`, Then E1 and E2 (payee). When `q=50%`, Then E3 only (not E1 12,500 or E5 100,000). When `q=Rent_Oct`, Then E4; when `q=Rent_Oc_` (underscore literal), Then 0 rows. When `q=duplicate`, Then E4 (void reason). When `q=INC-1`, Then E1 (ref no). When `q` is 101 characters, Then 422.
12. When `type=expense&min=100&q=CEB`, Then E2 only (filters are ANDed).
13. When `files=none` (or any `files` value), Then only income/expense rows are returned (unchanged attachments behaviour); E5, E6, E7, E9 are absent.

**Branch isolation**
14. Given a KDY Accountant, When `branches[]=<GAL>`, Then 403 "You do not have access to branch GAL.". When `branches[]=<KDY>`, Then the same result as AC 1.
15. Given the Super Admin in All mode, When no `branches[]`, Then E1–E9 and the MAIN/GAL rows are all listed with a Branch column. When `branches[]=<GAL>`, Then E8, E6 and E7 (received by GAL, `direction = in`), and not E1–E5. `GET /transactions/creators?branches[]=<GAL>` lists Saman and Nimal (E6, E7), not Kamal.
16. Given the Super Admin switched to GAL (branch mode), When opening a URL with `branches=<KDY>`, Then the web app drops it, shows "The branch filter applies in All branches mode only.", and lists GAL rows.
17. Given a KDY user and `account_id=<GAL Bank>`, Then only E6 and E7 (rows already in KDY's scope); no GAL-only row is returned.

**Sort and pagination**
18. Given 60 KDY rows, several sharing a date, When pages 1, 2, 3 are read with `per_page=25`, Then together they hold each of the 60 ids exactly once, and reading page 2 twice returns the same ids in the same order.
19. When `sort=amount&dir=desc`, Then E5 first, E9 last; `dir=asc` keeps E9 last. When `sort=name`, Then 422.
20. When `per_page=30`, Then 422; `per_page=200` still works.
21. Given 3 pages, When the URL has `page=9`, Then the screen moves to page 3 and the URL is replaced (no extra history entry).

**URL state**
22. When the user applies Type = Expense, Search = CEB and sorts by amount, Then the URL is `/transactions?type=expense&q=CEB&sort=amount` (defaults `dir=desc`, `page=1`, `per_page=25` left out). Reloading the page or opening the URL in another session of a KDY user shows the same rows and the fields filled in.
23. When the user applies two different filter sets and presses Back, Then the first set's fields and rows are shown.
24. When a URL has `min=abc`, Then the API 422 message shows in the alert with a "Clear filters" link, and the link clears all filters.
25. The reports drill-down link (reports AC 10) still opens the list with E-1's rows and the Sub-category field showing the clicked line.

**Display**
26. E4 is shown struck through with tag "Void"; E6 shows "Reversed" and "by <E7 ref>"; E7 shows "Reversal of <E6 ref>" linking to E6; E9 shows Type "Year-end close", Amount "—" and no actions.

**Export**
27. Given a user without `report.export` (Cashier, Viewer), Then the Export button is hidden and `POST /transactions/export` returns 403 "Missing permission: report.export".
28. Given the KDY Accountant with the filters of AC 12, When Export to Excel, Then `transactions_all_all.xlsx` downloads with one sheet "Transactions", the header lines of R14 (`Filters: Type: Expense; Amount: from 100.00; Search: "CEB"`, `Rows: 1`), and exactly the row E2.
29. Given `from=2026-10-01&to=2026-10-31` and date format `DD/MM/YYYY`, Then the file is `transactions_2026-10-01_2026-10-31.xlsx`, the header reads `Period: 01/10/2026 to 31/10/2026`, and the Date cells are real Excel dates formatted `dd/mm/yyyy`.
30. Given no filters in KDY, Then the export rows equal the list rows of AC 1 in the same order; E9's Amount cell is the text "—" and the header has the year-end note; E5's Type reads "<its type label> (in)", From "MAIN – Cash", To "KDY – Cash"; Amount cells are numeric with 2 decimals. E6's Status reads "Reversed by <E7 ref>" and its Reversed by cell = E7's ref; E7's Reversal of cell = E6's ref (accounting review finding 2).
31. Given a payee typed as `=HYPERLINK("x")`, Then its cell is text, not a formula.
32. Given 5,001 matching rows, When exporting, Then 422 "This export has 5,001 rows; the limit is 5,000. Narrow the filters." and no `report.exported` row is written. With exactly 5,000 it downloads.
33. After AC 28, Then one audit row `report.exported` exists with `branch_id = KDY`, `new_values = {name: 'transactions', format: 'xlsx', params: {type: 'expense', min: '100', q: 'CEB', sort: 'date', dir: 'desc', scope_branch_ids: [<KDY id>]}, rows: 1}`, and the audit log screen shows it under "Reports" with subject "Transactions". A Super Admin All-mode export with no branch filter writes `branch_id = null` and `scope_branch_ids` = every branch id; with exactly one `branches[]` it writes that branch and `scope_branch_ids: [<that id>]` (accounting review finding 4).
34. `POST /reports/transactions/export` still returns 404 "Unknown report.".
34a. Given E4 voided by Kamal with reason "duplicate", When the KDY Accountant exports with `status=void`, Then E4's row has Voided on = the void date, Voided by = Kamal's name and Void reason = "duplicate"; rows that are not void have these cells empty (accounting review finding 1).
34b. Given a KDY Accountant, When exporting with no filters, with `account_id=<GAL Bank>` and with `created_by=<Saman>`, Then no file contains E8 or any other GAL-only row, and each header reads `Branches: KDY Kandy` (accounting review finding 3).

**Phone (390px wide)**
35. Given a 390px viewport, When `/transactions` opens with no filters, Then the panel is collapsed, the "Filters" button is full width and ≥ 44px high, rows are cards, and the page has no horizontal scroll (`document.documentElement.scrollWidth <= 390`).
36. When 3 filters are active, Then the button reads "Filters (3)" and 3 chips are shown; tapping a chip × removes that filter, updates the URL and reloads.
37. When Apply is tapped with valid values, Then the panel closes; with an invalid amount, Then it stays open with the error under the field.

**Performance and integrity**
38. Given MySQL 8 and the new migration, Then `SHOW INDEX FROM transactions` includes `transactions_txn_date_id_index` and `transactions_related_branch_txn_date_index`; running the migration twice (or after a partial run) does not fail; `migrate:rollback` drops only these two.
39. Given MySQL with 200,000 transactions over 10 branches, Then `EXPLAIN` of the default All-mode page 1 query uses `transactions_txn_date_id_index` without "Using filesort", and the default branch-mode query is not `type = ALL`.
40. After any number of list and export calls, Then `ledger:verify` passes and no `accounts.current_balance` changes (R15). `pint --test` and `php artisan test` pass on SQLite.

## 8. Open questions (decisions in bold)
- **Q1 Incoming rows (R10).** Today a branch does not see top-ups, allocations or transfers it received in its list, although it can open their detail (double-entry R10) and they move its cash. **Decided (accounting review): show received top-ups, allocations, fund returns and branch transfers, and their reversals, read-only, tagged "In".** Void/Reverse/Edit/Files stay with the sending branch.
- **Q2 Default view.** **Decided (accounting review): every status and type, with void and closing entries visible (struck / "—")**, because the list is also the audit view. No "hide void" toggle in R1; the Status filter covers it.
- **Q3 Signs and totals.** **Decided (accounting review): amounts as stored (positive) on screen and in Excel, no totals row**, because the list mixes income, expenses and transfers in both directions and a plain sum would be misleading. The direction is carried by the type and the In marker.
- **Q4 Saved filters.** **Decided out of scope** (accepted in review): a bookmarked or shared URL (S1) restores the full view. Named saved filters per user would be a new backlog item.
- **Q5 Branch-only sub-categories in All mode.** The Category options in All mode are global only (categories spec), so a Super Admin can filter by a branch-only sub only in branch mode or via a drill-down link. **Accepted for R1** (review); the parent filter already includes them.
- **Q6 Export throttle.** **Decided: none** (accepted in review), as for report exports (cap 5,000, synchronous). Add `throttle` if exports become heavy.
- **Q7 Text search scope.** **Decided (accepted in review): ref no, payee/payer, reference, description only**, one phrase, no word splitting. Adding account or category names would duplicate their filters.

## Accounting review
Reviewed against double entry (`docs/specs/double-entry.md`), `LedgerService`, `PostingRules::transfer` and the existing report exports. Search and export change no data (R15), so the review is about completeness, isolation and audit evidence.

**Answers to the open questions**
- **Q1 Confirmed: show received top-ups, allocations, fund returns and branch transfers, read-only, tagged "In".** They move the branch's cash, the branch can already open them (double-entry R10), and without them the list cannot be ticked against the cash book or a bank statement. Reversals of those rows must come in the same way (they copy `related_branch_id`, `LedgerService` line ~264). Void/Reverse/Edit/Files stay with the sending branch.
- **Q2 Confirmed: every status shown by default**, void struck through, reversed and reversal rows linked, closing entries with "—". Hiding voids by default would hide exactly what an auditor looks for. No "hide void" toggle in R1; the Status filter covers it.
- **Q3 Confirmed: positive stored amounts, no totals row.** A plain sum of a mixed list is meaningless; the direction is carried by the type and the In marker (see S2).
- **Q4–Q7:** accepted as assumed, no accounting impact.

**Findings**
1. **MUST – Export carries void evidence.** A void posts contra lines on the void date, which can be in a later month than `txn_date`. Add columns **Voided on**, **Voided by**, **Void reason**, filled from the `transaction.voided` audit row (and `transaction.year_end_reopened` for a cancelled closing entry), loaded in one query for the exported ids. Empty for non-void rows. AC: E4 exported with `status=void` shows its void date, the voiding user and "duplicate".
2. **MUST – Reversal links in both directions in the export.** Add columns **Reversal of** (ref) and **Reversed by** (ref), not only in the Status text; Status for E6 reads `Reversed by <E7 ref>`. AC: AC 30 extended — E6 row has Reversed by = E7's ref, E7 row has Reversal of = E6's ref.
3. **MUST – Scope for list, export, creators and counts is built only from `BranchContext`** (R10), never from request params alone; `branches[]` only narrows it, and only in All mode (Super Admin, `SetBranchContext`). AC: a KDY Accountant's export with no filters, with `account_id=<GAL Bank>`, and with `created_by=<Saman>` never contains E8 or any other GAL-only row; the header reads `Branches: KDY Kandy`.
4. **MUST – Export audit row records the resolved scope.** `params` must also hold `scope_branch_ids` (the resolved set, e.g. `[KDY]`, or every branch id in All mode) so the evidence does not depend on the reader knowing the mode. AC 33 updated accordingly.
5. **MUST – Incoming rows are read-only everywhere.** Keep AC 2 and add: as GAL, `POST /transactions/{E6}/reverse` and a file upload on E6 return 404, and E6 shows no actions in GAL's list; the export Type for an incoming reversal reads "Reversal (in)".
6. **SHOULD – Separate Direction column in the export** (`Out` / `In` / empty for income, expense, capital and All-mode rows where both sides are in scope) instead of only the " (in)" suffix on Type, so the file can be filtered in Excel. The screen tag "In" is fine as is.
7. **SHOULD – Add Created at (timestamp, Asia/Colombo) to the export** beside Date, so back-dated entries are visible to an auditor.
8. **SHOULD – Cancelled closing entries labelled distinctly:** Status `Void (year reopened)` on screen and in Excel, so they are not confused with a voided cash entry.
9. **SHOULD – Head-office mirror lines.** A KDY → GAL transfer (and a non-main year-end close) posts BCA lines in MAIN, but under R10 MAIN's branch-mode list does not show them (MAIN is neither sender nor receiver), although `show()` lets MAIN open them. Acceptable for R1 because the list is a money-account list and MAIN sees these lines in the GL/trial balance; state this in R10 so it is a decision, not a gap.
10. **SHOULD – All-mode multi-branch export with `branch_id = null`:** fine (Super Admin only), provided finding 4 is done so each branch's auditor can find exports of its data by searching `scope_branch_ids`.

Fine as specified: amount semantics (one positive figure per transfer, reversal equal to original, closing entries excluded from amount bounds and sorted last); amount compare in cents; LIKE escaping; permissions (`transaction.view` + `report.export`; Cashier and Viewer cannot export); export cap with no audit row on refusal; no balance or ledger change (R15).
