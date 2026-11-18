# Spec: Full double entry (chart of accounts, balanced postings, trial balance)

## 1. Goal and scope
Product owner decision (2026-11-18): every money entry is full double entry against a per-branch chart of accounts.

Today (`LedgerService.php`):
- Transfers post two lines between cash/bank accounts. The transaction balances in total, but a cross-branch transfer does not balance inside each branch. The sender has one credit line and the receiver has one debit line.
- Income, expense and capital post **one** line on a cash/bank account. The category sits on `transactions.category_id`, and no income, expense or equity ledger account exists.
- `ledger_entries.debit` means "money in" and `credit` means "money out". `accounts` holds only cash/bank/wallet rows.

After this feature:
- every transaction's debits equal its credits, in total and inside each branch;
- every branch has a chart of accounts with the classes asset / liability / equity / income / expense;
- existing history is back-filled without changing any cash/bank balance;
- a trial balance report exists, per branch and consolidated, with inter-branch eliminations;
- `ledger:verify` proves all of this;
- the four existing reports, the dashboard and account statements show the same figures as before.

Backlog: there is no item yet. Proposed new rows: **AG-034** "Chart of accounts per branch" (E4) and **AG-089** "Trial balance" (E9). Touches AG-031, AG-040..043, AG-050..054, AG-060, AG-063, AG-082..085, AG-091.

Out of scope:
- manual journal entries, and free-form ledger accounts (nothing could post to them without journals);
- liabilities such as loans and payables, accruals and prepayments;
- year-end close and retained earnings (with AG-092 fiscal year);
- balance sheet and profit & loss statements;
- editing account codes;
- multi-currency.

## 2. Personas and permissions
No new permission key. Existing keys (`PermissionSeeder::PERMISSIONS`):

| Need | Key | Templates that have it |
|---|---|---|
| Chart of accounts screen (structure only, no balances) | `account.view` | Branch Admin, Accountant, Cashier, Viewer |
| Add cash/bank/wallet accounts (and with them asset accounts) | `account.manage` | Branch Admin, Accountant |
| Add income/expense accounts, through categories | `category.manage` (top level: Super Admin only, as today; a branch user gets a branch-only sub-category) | Branch Admin, Accountant |
| Trial balance (screen) / export | `report.view` / `report.export` | Branch Admin, Accountant (both); Viewer (view) |
| Statement of a non-cash ledger account (income, expense, equity, inter-branch) | `report.view` (in addition to `account.view`) | as above |
| Ledger lines on the entry detail screen | `transaction.view` | all four |
| Capital, allocations, branch creation (these create system accounts) | `super` middleware | Super Admin |

Why no `chart.manage` key: there are no free-form accounts. Each account comes from a source that already has its own permission (Accounts, Categories, Branches) or is created by the system. A new key would also go to every Branch Admin automatically (`PermissionSeeder::run` gives new keys to the `*` template).

The chart screen shows no balances. Income and expense balances are profit information, and a Cashier has `account.view` but not `report.view`. Balances are shown on the trial balance.

## 3. User flows
- **F1 Record income (Cashier, KDY).** Same form as today. The entry detail screen now shows two lines: Dr 11001 KDY Cash 50,000.00 and Cr 40101 Sales > Retail 50,000.00, with the badge "Balanced".
- **F2 Top-up (Super Admin).** Same form as today. The detail screen shows four lines:
  - MAIN: Dr 15002 Inter-branch – KDY, Cr 11001 MAIN Bank;
  - KDY: Dr 11001 KDY Cash, Cr 15001 Inter-branch – MAIN.
- **F3 Change the category of an entry (Accountant).** Edit expense -> category Rent -> Electricity -> Save. Two reclassification lines are added to the same entry, on its original date: Dr Electricity, Cr Rent. The I&E report moves the amount from Rent to Electricity, as it does today.
- **F4 Void / reverse.** Same as today. A void adds a contra for **every** line; a reversal mirrors **every** line.
- **F5 Chart of accounts (Accountant, KDY).** Menu Accounts > Chart of accounts. The accountant sees KDY's accounts grouped by class. Clicking a ledger account opens its statement.
- **F6 Month-end (Super Admin, All).**
  1. Reports > Trial balance, view Consolidated, October.
  2. Each branch column totals 0.00, eliminations total 0.00, and consolidated Dr = Cr.
  3. Lock the month, then print and sign.
- **F7 Deploy (operator).**
  1. Run `php artisan ledger:verify` (must pass).
  2. Run `php artisan ledger:backfill-double-entry --dry-run` and send the output to the accountant.
  3. Run `php artisan migrate` (chart + back-fill).
  4. Run `php artisan ledger:verify` again (must pass, with the new checks).

## 4. Screens
**S1 Chart of accounts** `/chart-of-accounts`. Menu item under Accounts if `auth.can('account.view')`. Guard `permissionGuard('account.view')`. Reloads on branch switch.
- Branch mode: the active branch. All mode (Super Admin): a single-select Branch filter, default the Main branch, inactive branches tagged.
- Sections in class order: Assets, Liabilities, Equity, Income, Expenses. An empty class shows "No accounts".
- Columns: Code | Name | Type | Created from | Status.
  - Type values: Cash / Bank / Wallet / Inter-branch / Owner's capital / Category.
  - Created from: "Accounts", "Category", "System".
  - Status values: Active / Closed / Inactive.
- Category accounts are shown as a tree. The parent row is the parent category's own account (posting with no sub-category). Its sub-category accounts are indented under it.
- Row action "Ledger" (account statement) is shown when the row is a cash/bank/wallet account, or when the user has `report.view`.
- No add, edit or delete on this screen. Info line: "Cash, bank and wallet accounts are added under Accounts. Income and expense accounts follow Categories. Inter-branch and capital accounts are created by the system."
- Links: "Add account" (to Accounts) if `account.manage`.
- Empty state: none, because every branch has at least a Cash account and its inter-branch accounts.
- Errors: the API `message` in `.alert`.

**S2 Trial balance** `/reports/trial-balance`. Card in the Reports hub. Guard `permissionGuard('report.view')`. Common report filters (From, To, Branches), plus **View**:
- "By branch" is the default.
- "Consolidated" is available in All mode only, when 2 or more branches are selected.

By branch, one table per branch:

| Column | Content |
|---|---|
| Code, Account, Class | the account (inactive or closed accounts tagged) |
| Opening Dr, Opening Cr | balance before From |
| Period debits, Period credits | lines From..To, gross, voided entries excluded |
| Closing Dr, Closing Cr | balance at To |

- Rows are listed by code. A row shows when its closing balance or its period movement is not 0.00.
- Totals row: Opening Dr = Opening Cr, Period debits = Period credits, Closing Dr = Closing Cr.

Consolidated: one table.
- Rows (consolidation lines):
  - each cash/bank/wallet account, labelled "KDY · Cash";
  - Owner's capital;
  - one row per category code, summed over branches;
  - inter-branch rows "KDY ↔ GAL" etc.
- Columns: one column per selected branch (closing balance, signed: debit positive, credit negative; header note says so) | Eliminations | Consolidated Dr | Consolidated Cr.
- Inter-branch rows are eliminated only when both branches are selected. Otherwise the reports subset note is shown: "Inter-branch balances with branches not selected are not eliminated."
- Totals: every branch column is 0.00, Eliminations is 0.00, and Consolidated Dr = Consolidated Cr.

Other parts of S2:
- If any total does not agree (never expected), the page shows a red `.alert` "Out of balance by X. Run ledger:verify." The server logs an error. The report is still shown.
- Header: same as the other reports (company, branches, period, "Amounts in LKR", PROVISIONAL marker). Basis line: "All posted lines; period columns exclude voided entries; income and expense are cumulative since the first entry (no year-end close yet)."
- Export: PDF and Excel with `report.export`. Same header and footer rules as reports finding 4. The PDF ends with the Prepared by / Checked by / Approved by block.

**S3 Entry detail** (`transaction-detail.component.ts`), new section "Ledger lines":
- Columns: Date | Branch | Code | Account | Debit | Credit | Note.
- Note values:
  - "Void contra of line n" when `contra_of_id` is set;
  - "Reclassification" (`origin = reclass`);
  - "Added by double-entry migration <date>" (`origin = backfill`).
- Lines are ordered by id. Totals row: Debit total and Credit total. Badge "Balanced" (green) or "Not balanced" (red, never expected).
- A cross-branch transfer shows both branches' lines (account names only; balances are never shown).
- The legacy void note (controls S4) is unchanged.

**S4 Unchanged screens.**
- The Accounts list, account pickers (income/expense/capital/allocation/transfer forms), transfer destinations, dashboard account list and branch balances keep showing **cash/bank/wallet accounts only**.
- Statement of a money account: unchanged.

## 5. API
| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `GET /v1/chart-of-accounts` | `perm:account.view` | `branch_id` (required in All mode; in branch mode absent or equal to the active branch, else 403), `include_inactive` (0/1, default 1) | `{branch:{id,code,name}, classes:[{class, accounts:[{id, code, name, class, type, origin:'accounts'\|'category'\|'system', category_id, parent_category_id, counterparty_branch:{id,code}\|null, status}]}]}`. No balances. |
| `GET /v1/reports/trial-balance` | `perm:report.view` | `from`, `to` (Y-m-d, ≤ 366 days), `branches[]` (rules R1 of reports), `view` (`branch`\|`consolidated`; consolidated -> 422 "Select at least two branches in All branches mode" otherwise) | branch: `meta + {sections:[{branch, rows:[{account:{id,code,name,class,type,status}, opening_dr, opening_cr, period_dr, period_cr, closing_dr, closing_cr}], totals:{…}, balanced:bool}]}`. consolidated: `meta + {branches:[…], rows:[{key, label, class, by_branch:{id: signed}, elimination, dr, cr}], totals:{by_branch, elimination, dr, cr}, balanced, subset}` |
| `POST /v1/reports/trial-balance/export` | `perm:report.view,report.export` | same + `format` | file `trial-balance_<from>_<to>.<ext>`, audit `report.exported`. `ReportService::NAMES` gains `trial-balance`. |
| `GET /v1/accounts/{id}/statement` | `perm:account.view`; for a non-money account also `report.view` (403 "Missing permission: report.view") | unchanged | unchanged. Works for every account class. |
| `GET /v1/accounts`, `/accounts/destinations` | unchanged | unchanged | **money accounts only** (`type` in cash, bank, wallet) |
| `PUT /v1/accounts/{id}` | unchanged | unchanged | 422 "This account belongs to the chart of accounts and changes with its category or branch." for a non-money account |
| `GET /v1/transactions/{id}` | unchanged | unchanged | `entries[]` gains `origin`, `contra_of_id`, `account:{id,code,name,class,type}`, `branch:{id,code}`; root gains `balanced` (bool) |
| `POST /v1/incomes`, `/expenses`, `/capital`, `/allocations`, `/transfers` | unchanged | unchanged | 422 "Choose a cash, bank or wallet account." if any picked account is not a money account |

**Schema.** These are two migrations after `2026_11_21_000000_create_attachments_table.php`. Check the other agent's latest migration date before naming them.

1. `…_double_entry_schema.php`
   - `accounts`:
     - `class` string(10) not null default `asset` (asset|liability|equity|income|expense);
     - `type` gains the values `interbranch`, `owner_capital`, `category` (`Account::MONEY_TYPES = [cash, bank, wallet]`, scope `money()`);
     - `code` string(10) nullable, unique (`branch_id`, `code`);
     - `category_id` nullable FK, unique (`branch_id`, `category_id`);
     - `counterparty_branch_id` nullable FK -> branches, unique (`branch_id`, `counterparty_branch_id`).
   - `categories`: `ledger_code` string(5) nullable unique.
   - `ledger_entries`: `origin` string(10) not null default `posting` (posting|reclass|backfill).
     - This is an `ADD COLUMN` only. Do **not** add a CHECK constraint through the schema builder: on SQLite that rebuilds the table and drops the immutability triggers (controls migration note).
   - New `BEFORE INSERT` trigger `ledger_entries_line_check` (MySQL + SQLite), DB check D1–D3 in R4.
   - Preflight (`MigrationPreflight`): refuse to start if any existing line breaks D1–D3. Same pattern as controls R2/R3.
2. `…_double_entry_backfill.php`: runs the chart creation (R1) and the back-fill (R6) through the code that `ledger:backfill-double-entry` uses.

## 6. Business rules

### R1 Chart of accounts (per branch)
Code scheme: 5 digits, and the first digit is the class.

| Code | Account | Class | Type | Created |
|---|---|---|---|---|
| 11001–11999 | each cash / bank / wallet account (existing ones numbered by id) | asset | cash\|bank\|wallet | with the account (Accounts screen, branch creation "Cash") |
| 15 + counterparty branch id (3 digits), e.g. 15002 | "Inter-branch – <code>": one per **other** branch. Debit balance = due from that branch, credit balance = due to it. | asset (shown Dr or Cr by sign) | interbranch | when a branch is created: both directions with every existing branch |
| 31000 | Owner's capital | equity | owner_capital | in the Main branch at migration; in any branch on its first capital posting (a non-main branch with an `is_main_account`) |
| 4PPSS | income category account | income | category | per category, see below |
| 5PPSS | expense category account | expense | category | per category |
| 2xxxx | (none in this feature) | liability | — | — |

- **Categories become accounts.** Each category **node** gets one ledger account per branch where the category can be used:
  - a global category gets an account in every branch, active and inactive;
  - a branch-only sub-category gets an account in its own branch only.
- The parent node's account (4PP00 / 5PP00) takes postings made to the parent with no sub-category. That is the "(no sub-category)" line of the I&E report.
- `categories.ledger_code` is the code. It is the same in every branch, which lets the consolidated trial balance add the branches up.
  - PP is the parent sequence (01–99) per type, in creation order.
  - SS is the sub-category sequence (01–99) under its parent.
  - At migration the codes are given in (type, parent name, sub name) order.
  - A 100th parent or sub gives 422 "No more category codes are available under <parent>. Ask the accountant." (Q4)
- Account name:
  - category accounts: "<Parent> > <Sub>", or "<Parent>" for the parent node;
  - inter-branch: "Inter-branch – <branch code>";
  - owner capital: "Owner's capital".
  - Renaming a category renames its accounts. Deactivating a category sets the accounts to `inactive`, and reactivating sets them back. Audit `account.updated` (system, `self` false).
- Branch creation (`BranchController::store`) creates the Cash account (as today), the inter-branch pairs and the global category accounts in one DB transaction. Creating a category creates its accounts in the same DB transaction.
- Safety net: when a posting needs a system or category account that is missing, `LedgerService` creates it inside the posting transaction (`firstOrCreate` on the unique keys) and writes `account.created`.
- Chart accounts are never deleted. Codes are never reused.
- `current_balance` is kept for every account class by `LedgerService::entry` only. It is always the signed debit − credit, so credit-normal accounts hold negative values. Screens never show it for non-money accounts; the trial balance reads the ledger.
- Locking: `LedgerService::lock` locks **all** accounts of a posting (money and chart accounts) in id order, as today.

### R2 Meaning of debit and credit
The usual meaning applies on every account: debit increases assets and expenses, and credit increases liabilities, equity and income. For a money account this is the same as today's "debit = money in, credit = money out", so no existing line changes meaning. Update the docblocks in `LedgerService` and `create_accounting_tables`.

### R3 Posting rules
Notation: "IB(X) in B" is branch B's inter-branch account for counterparty X. All lines are dated `txn_date` unless the table says otherwise.

| Transaction | Branch | Debit | Credit |
|---|---|---|---|
| Income (cash account A, category C) | A's branch | A | Income C |
| Expense (A, C) | A's branch | Expense C | A |
| Capital (A in Main) | Main | A | Owner's capital |
| Opening allocation Main A -> branch B account Z | Main | IB(B) in Main | A |
| | B | Z | IB(Main) in B |
| Top-up / fund allocation | same as opening allocation | | |
| Fund return branch B account Z -> Main A | B | IB(Main) in B | Z |
| | Main | A | IB(B) in Main |
| Branch transfer B account Z -> branch G account Y | B | IB(G) in B | Z |
| | G | Y | IB(B) in G |
| Internal transfer A -> A2 (same branch) | that branch | A2 | A |
| Reclassification (edit changes category C -> C2), same transaction, dated the original `txn_date`, `origin = reclass` | entry's branch | expense: Expense C2 / income: Income C | expense: Expense C / income: Income C2 |
| Void (income/expense, status -> void) | each line's branch | for **every** line L of the transaction (postings, reclass, back-fill; not earlier contras): one contra line with the same account, branch and date as L, the sides swapped, `contra_of_id = L` | |
| Reversal (new transaction, type reversal) | each line's branch | for every line L of the original: a line on the same account, sides swapped, dated the reversal date | |

Notes:
- **Line order:** the money line comes first.
- **Bug to fix:** `LedgerService::void` now locks only `$txn->account_id` and posts every contra on that one account. It must use each line's own account.
- `reverse` and `void` keep their funds checks, but only where a **money** account is credited (today `reverse` checks every debit line, which would test an expense account's balance).
- **Category edit:** `TransactionController::update` calls a new `LedgerService::reclassify(Transaction, int $categoryId)` in place of updating `category_id` directly. Changing only payee/description posts nothing.
- **Money accounts only:** capital, allocation, transfer and income/expense refuse any non-money account (R3a): `LedgerException` "Choose a cash, bank or wallet account."

### R4 Balance rule (enforced)
- **L1 LedgerService.** Every posting method builds its lines as a list first, then:
  1. checks, in integer cents, that debits = credits for the whole set **and** for each branch in it;
  2. writes the lines;
  3. re-reads `SUM(debit)`, `SUM(credit)` of the transaction per branch in the same DB transaction, before commit.

  Any mismatch throws `LedgerException('Posting is not balanced.')` and rolls everything back. A "set" is one batch: the original posting, one reclass, one void's contras, or one back-fill. Each set balances on its own, so the transaction as a whole always balances.
- **L2 One-sided lines.** Every line has exactly one side greater than 0, and neither side is negative.
- **Database (feasible checks)** in the `ledger_entries_line_check` BEFORE INSERT trigger:
  - **D1:** `debit >= 0 AND credit >= 0`, and exactly one of them `> 0`;
  - **D2:** `branch_id` equals the account's `branch_id`;
  - **D3:** when `contra_of_id` is set, the referenced line has the same `account_id` and `transaction_id`, and `debit`/`credit` are swapped.

  The database refuses with "ledger_entries line check failed (<rule>)."
- **Not feasible in the database:** "debits = credits per transaction". MySQL has no deferred constraints or statement-level triggers, so a row trigger would refuse the first line of every posting. L1 plus `ledger:verify` (R7) and the lock check (R9) cover it. Do not add a balance trigger.

### R5 Transaction amount
`transactions.amount` stays the single amount entered. For every set, the total of the money-account lines on one side equals `amount`. For a cross-branch transfer, the total debits of the set equal 2 × `amount`. Reports keep using `amount` where they use it today.

### R6 Data migration (back-fill)
- **Preflight.** Every `accounts.current_balance` must equal its ledger balance (the existing `ledger:verify` check). If not, the migration stops with a list of the accounts. Also check D1–D3 on existing rows.
- **Chart (R1).**
  - Give `ledger_code` to every category, and 11xxx codes to every existing money account (by id per branch).
  - Create the inter-branch pairs for every two branches, Owner's capital in Main (and in any branch that has capital transactions), and the category accounts.
  - Write one audit row `ledger.chart_created` `{by_branch:{code:{accounts:n}}}` (user null, branch null). There is no row per account.
- **Back-fill**, per transaction in id order, one DB transaction each. It is idempotent: a transaction whose lines already balance per branch is skipped. A re-run after a failure continues.

  | Existing transaction | Lines added (`origin = backfill`, `entry_date` = date of the line they complete) |
  |---|---|
  | income / expense, posted or reversed | Cr Income C / Dr Expense C, on `txn_date` |
  | income / expense, void with contra lines | that line, **and** its contra (`contra_of_id` = the new line, date = the existing contra's date) |
  | capital | Cr Owner's capital |
  | opening allocation, top-up, fund return, branch transfer (posted or reversed) | sender: Dr IB(receiver) in sender; receiver: Cr IB(sender) in receiver |
  | reversal of any of the above | the mirror of what the original received, on the reversal's date. The category is the **original's** `category_id` (same basis as the I&E report). |
  | internal transfer and its reversal | nothing (already balanced in one branch) |
  | legacy void (status void, no lines; `LegacyVoids`) | nothing. They stay "identified only" (controls R6). |

- Back-fill lines are **inserted** only. No `ledger_entries` row is updated or deleted, so the immutability triggers stay on the whole time. No money account gets a line, so no cash/bank/wallet `current_balance` changes. The migration snapshots them before, compares after, and throws on any difference.
- The chart accounts' `current_balance` is set by `LedgerService::backfill()` through `entry()`. That is the only allowed writer (CLAUDE.md rule).
- **Period locks:** the back-fill is the only posting that ignores `assertOpen` (see R9). It lists every (branch, period) that was locked when it inserted lines.
- **Audit:** one row `ledger.double_entry_backfilled` (user null, branch null) `{transactions:n, lines:n, by_type:{…}, by_branch:{…}, locked_periods:[{branch, period}], money_balances_unchanged:true, skipped_legacy_voids:n}`. Lines can be found by `origin = backfill`, and their `created_at` is the migration time.
- **`php artisan ledger:backfill-double-entry --dry-run`:** prints the same counts and a sample of 20 transactions with the lines that would be added. It writes nothing. Without `--dry-run` it does what the migration does (for a re-run).

### R7 `ledger:verify` additions
Each check prints its count, lists offenders (ref, date, branch, difference) and exits 1 on any failure. The existing checks (legacy voids, cached balances for **all** accounts, now including chart accounts) stay.
- **V1** Every transaction except legacy voids has lines, and SUM(debit) = SUM(credit) for the transaction and for each branch in it.
- **V2** Every line is one-sided (D1). Contra lines mirror their originals (D3). Line branch = account branch (D2).
- **V3** Trial balance nets to zero: for each branch, and for all branches together, SUM(debit) = SUM(credit) over all lines.
- **V4** Inter-branch pairs eliminate: for every two branches A and B, balance(IB(B) in A) + balance(IB(A) in B) = 0.00.
- **V5** Category accounts agree with the I&E basis. For each (branch, category), the category account's ledger balance (credit − debit for income, debit − credit for expense) equals the all-time I&E figure from `ReportService::countedTransactions`.
- **V6** Back-fill complete: no income, expense, capital or cross-branch transaction is left with only money-account lines.

### R8 Existing reports and screens stay the same
All ledger-based queries filter to money accounts (`accounts.type IN (cash, bank, wallet)`). Places to change:
- `ReportService::branchComparison`: the `$before` opening/closing query and `$moves`; join `accounts` and filter.
- `ReportService::cashBook` (account list) and `cashBookRows`.
- `DashboardController` lines 62, 66, 68 (total balance, account list, balance per branch).
- `BranchController::index` `withSum` balance.
- `AccountController::index`, `destinations`.

`incomeExpense`, `transfers` and the dashboard income/expense figures read `transactions` and do not change. The account statement already filters by `account_id`. The E-dataset figures (reports spec section 7, E-1..E-4) must be identical before and after the migration (AC 23).

### R9 Period lock
- **Every new set** asserts open, for each (branch, line date) in the set. In practice:
  - a cross-branch transfer, its reversal and each IB line → both branches;
  - reclass → the entry's branch and date (already checked by `update`);
  - void → the entry's branch and date.
- **Back-fill exemption.** The back-fill does not change any money balance or any figure in the four reports or the dashboard. It only adds the missing side, so it runs in locked months. The exemption is recorded in `ledger.double_entry_backfilled.locked_periods` (Q6). A trial balance of a locked month shows the figures for the first time after the migration.
- **New control.** `POST /period-locks` refuses to lock if any transaction dated in the scope (branch or all, cumulative through the period) breaks V1: 422 "N entries up to October 2026 are not balanced. Run ledger:verify." This is one grouped query.
- The trial balance shows PROVISIONAL like the other reports (`ReportService::provisional`).

### R10 Edge cases
- **Inactive branch:** its accounts stay. Posting to it is already refused (inactive branch). Its IB accounts stay in eliminations.
- **New branch after history exists:** IB pairs are created with every branch, and the category accounts with zero balances.
- **Closing a money account:** unchanged (zero balance). Chart accounts cannot be closed (422, see the API table).
- **A `branch_id`-specific sub-category used before migration:** its account is created in that branch only.
- **`expense_negative_balance = warn`:** applies to the money account only.
- **Concurrent postings to the same category in a branch** wait on that account's row lock. Postings are short, and this is accepted.
- **Branch isolation:** `GET /transactions/{id}` shows the counterparty branch's lines of a cross-branch transfer (account names only), as the detail screen already shows the destination account. `chart-of-accounts` and `trial-balance` follow reports R1: one branch in branch mode, any set in All mode.

## 7. Worked example (reports spec E-dataset; QA and accountant check)
Codes: MAIN Bank 11001, KDY Cash 11001, KDY Bank 11002, GAL Cash 11001. IB: MAIN→KDY 15002, etc. Owner's capital 31000. Sales 40100 > Retail 40101, Service income 40200; Rent 50100; Utilities 50200 > Electricity 50201, Water 50202. (Codes follow the name-ordered rule in R1; QA asserts by account, not by code number.)

**E-5 Back-fill of the E-dataset** (17 originals + 3 reversals = 20 transactions, 29 lines before):
- 19 transactions get 27 lines. Internal transfer (8) gets none.
- Lines added, per transaction:
  - capital: 1;
  - each opening allocation, top-up, fund return and branch transfer: 2;
  - each income or expense: 1;
  - reversals of (10), (11), (13): 1, 2, 1;
  - voided (12): 2 (Dr Rent, plus its contra Cr Rent).
- After the back-fill: 56 lines. Money balances are unchanged (MAIN Bank 620,000.00; KDY Cash 254,300.00; KDY Bank 40,000.00; GAL Cash 137,200.00 at 2026-10-31).

**E-6 Trial balance by branch, 2026-10-01..31 (closing):**

| Branch | Debit balances | Credit balances | Total |
|---|---|---|---|
| MAIN | Bank 620,000.00; IB–KDY 300,000.00; IB–GAL 80,000.00 | Owner's capital 1,000,000.00 | 1,000,000.00 |
| KDY | Cash 254,300.00; Bank 40,000.00; IB–GAL 30,000.00; Rent 30,000.00; Electricity 8,200.00 | IB–MAIN 300,000.00; Retail 50,000.00; Service income 12,500.00 | 362,500.00 |
| GAL | Cash 137,200.00; Water 1,800.00 | IB–MAIN 80,000.00; IB–KDY 30,000.00; Retail 29,000.00 | 139,000.00 |

- GAL Rent does not show: the void nets it to 0.00, and voided lines are excluded from the period columns.
- KDY October period columns:
  - Electricity: debits 13,200.00 (8,200 + 5,000), credits 5,000.00 (reversal);
  - Retail: credits 50,000.00;
  - opening at 10-01: Cash 170,000.00 Dr, Rent 30,000.00 Dr, IB–MAIN 200,000.00 Cr.

**E-7 Consolidated, All, 2026-10-01..31:**
- Eliminations: MAIN↔KDY 300,000.00 − 300,000.00 = 0.00; MAIN↔GAL 80,000.00 − 80,000.00 = 0.00; KDY↔GAL 30,000.00 − 30,000.00 = 0.00.
- Consolidated Dr:
  - MAIN·Bank 620,000.00, KDY·Cash 254,300.00, KDY·Bank 40,000.00, GAL·Cash 137,200.00 (cash total 1,051,500.00 = E-2 Total closing);
  - Rent 30,000.00, Electricity 8,200.00, Water 1,800.00;
  - total 1,091,500.00.
- Consolidated Cr: Owner's capital 1,000,000.00, Retail 79,000.00, Service income 12,500.00; total 1,091,500.00.

**E-8 GAL, 2026-11-01..30:** Retail opening 29,000.00 Cr, period debits 4,000.00 (reversal of (13)), closing 25,000.00 Cr (equals I&E GAL November Retail −4,000.00 as a movement). GAL Cash closing 133,200.00.

## 8. Acceptance criteria
**Posting**
1. Given KDY Cash and active category Retail, When income 50,000.00 is posted, Then the entry has exactly 2 lines: Dr KDY Cash 50,000.00 and Cr KDY "Sales > Retail" 50,000.00, both `origin = posting` and dated `txn_date`; KDY Cash `current_balance` rises by 50,000.00.
2. Given an expense 8,200.00 on KDY Cash in Electricity, Then the lines are Dr Electricity 8,200.00 and Cr KDY Cash 8,200.00.
3. Given income posted to the parent "Service income" (no sub-category), Then the credit line is on the parent node's account (40200, "Service income").
4. Given capital 1,000,000.00 to MAIN Bank, Then the lines are Dr MAIN Bank and Cr MAIN Owner's capital.
5. Given a top-up MAIN Bank -> KDY Cash 100,000.00, Then there are 4 lines: MAIN Dr IB–KDY / Cr Bank, and KDY Dr Cash / Cr IB–MAIN. The debits equal the credits inside MAIN and inside KDY. The same holds for an opening allocation.
6. Given a fund return GAL Cash -> MAIN Bank 20,000.00, Then GAL Dr IB–MAIN / Cr Cash, and MAIN Dr Bank / Cr IB–GAL.
7. Given a branch transfer KDY Cash -> GAL Cash 30,000.00, Then KDY Dr IB–GAL / Cr Cash, and GAL Dr Cash / Cr IB–KDY. No line is posted in MAIN.
8. Given an internal transfer KDY Cash -> KDY Bank, Then there are exactly 2 lines and no IB line.
9. Given a posted expense with 2 lines, When it is voided, Then 2 contra lines are added: each on its original's account and date, with the sides swapped and `contra_of_id` set. The transaction has 4 lines and every account nets to 0.00 for it.
10. Given a branch transfer with 4 lines, When it is reversed on a later date, Then the reversal transaction has 4 mirrored lines dated the reversal date, balanced inside each branch.
11. Given a posted expense in Rent in an open month, When an Accountant changes the category to Electricity, Then 2 lines with `origin = reclass` are added on the original date (Dr Electricity, Cr Rent), and I&E for that month shows the amount under Electricity. When the entry is then voided, Then 4 contra lines are added and both categories net to 0.00.
12. Given an edit that changes only the description, Then no line is added.
13. Given the API, When `account_id` (income, expense, capital) or `from_account_id` / `to_account_id` (transfer, allocation) is a chart account (category, IB, capital), Then 422 "Choose a cash, bank or wallet account." and nothing is posted.
14. Given a test that makes a posting set unbalanced (e.g. a stubbed line builder), Then `LedgerException` "Posting is not balanced." is thrown and no line, transaction row or balance change remains.
15. Given a raw insert into `ledger_entries` with debit 0 and credit 0, or with both > 0, or a negative side, or with a `branch_id` other than the account's, or a contra that does not mirror its original, Then the database refuses it (MySQL and SQLite).
16. Given 2 concurrent income postings to the same category in KDY, Then both succeed and the category account's `current_balance` equals its ledger balance.

**Chart of accounts**
17. Given a new global expense sub-category "Internet" under Utilities, Then every branch gets an account "Utilities > Internet" with the same `ledger_code`. Given a branch-only sub-category created by a KDY Accountant, Then only KDY gets the account.
18. Given category Retail renamed to "Retail sales", Then every branch's account is renamed; When it is deactivated, Then its accounts show Inactive on the chart, and posting to it is refused (as today).
19. Given Super Admin creates branch MTR, Then MTR gets Cash (11001), IB–MAIN, IB–KDY, IB–GAL, and every global category account; and MAIN, KDY and GAL each get IB–MTR.
20. Given a Cashier in KDY, When opening `/chart-of-accounts`, Then KDY's accounts are listed by class with no balance column and no "Ledger" action on non-money accounts; `GET /accounts/{IB id}/statement` returns 403 "Missing permission: report.view". Given an Accountant, Then the "Ledger" action opens the statement of any account.
21. Given a KDY user, When `GET /chart-of-accounts?branch_id=<GAL>`, Then 403. Given Super Admin in All mode without `branch_id`, Then 422.
22. Given the Accounts screen, the dashboard and every account picker, Then only cash, bank and wallet accounts appear; `PUT /accounts/{category account}` returns 422.

**Existing reports unchanged**
23. Given the E-dataset, When I&E, Branch Comparison, Transfers and Cash Book run for the periods in reports ACs 1–34, before the back-fill and after it, Then every response is identical (E-1..E-4 unchanged), and the dashboard totals and branch balances are identical.
24. Given new postings after the migration (an income, a top-up, a branch transfer and a reversal), Then Branch Comparison still reconciles (no 500), and Cash Book lists only money accounts.

**Migration**
25. Given the E-dataset loaded on the pre-feature schema, When `migrate` runs, Then 27 lines with `origin = backfill` are inserted into 19 transactions (E-5), none on a money account; every money account's `current_balance` is unchanged; no existing line is changed; one `ledger.double_entry_backfilled` audit row has `transactions = 19`, `lines = 27`.
26. Given voided expense (12), Then the back-fill adds Dr Rent and a contra Cr Rent whose `contra_of_id` is the back-filled line.
27. Given September 2026 locked for all branches, When the migration runs, Then September transactions are back-filled anyway and the audit row lists the locked (branch, period) pairs.
28. Given the migration has run, When `ledger:backfill-double-entry` runs again, Then it adds 0 lines. Given it stopped half-way (simulated exception), When it runs again, Then the result equals a single clean run.
29. Given one account whose `current_balance` differs from its ledger, When the migration runs, Then it stops before changing anything and names the account.
30. Given a legacy void (no lines), Then it gets no lines and `ledger:verify` still lists it as a legacy void.
31. Given `--dry-run`, Then counts are printed and the database (lines, accounts, audit) is unchanged.

**Trial balance**
32. Given the E-dataset, When the trial balance runs by branch for 2026-10-01..31 in All mode, Then the closings equal E-6, each branch's totals agree, GAL Rent is not listed, and the KDY period and opening columns equal the E-6 note.
33. Given the same with view Consolidated, Then the figures equal E-7: every branch column totals 0.00, Eliminations totals 0.00, and Consolidated Dr = Cr = 1,091,500.00.
34. Given Consolidated with only KDY and GAL selected, Then KDY↔GAL is eliminated, IB–MAIN in KDY and GAL is not, and the subset note is shown.
35. Given GAL, 2026-11-01..30, Then Retail equals E-8.
36. Given a KDY Accountant, Then only KDY's section is returned; `view=consolidated` returns 422; `branches[]=GAL` returns 403.
37. Given October not locked for a branch in scope, Then PROVISIONAL is shown. Given exports, Then PDF and Excel totals equal the screen, the header and footer follow reports finding 4, and `report.exported` is written.
38. For any period, Given the trial balance, Then for each branch and category, the period credits − debits (income) or debits − credits (expense) equal the I&E report figure for the same period and branch.

**ledger:verify and locks**
39. Given the migrated E-dataset, When `ledger:verify` runs, Then V1–V6 pass and it exits 0.
40. Given a test database where one transaction's lines were inserted unbalanced (triggers bypassed in the test), When `ledger:verify` runs, Then V1 and V3 name that transaction's ref and the difference, and it exits 1. When a Super Admin locks that month, Then 422 "1 entries up to <Month YYYY> are not balanced. Run ledger:verify."
41. Given a locked month, When a top-up dated in it is posted where only the receiving branch is locked, Then 422 "The period YYYY-MM is locked." and nothing is posted.

**Entry detail**
42. Given the top-up in AC 5, When its detail is opened, Then "Ledger lines" lists 4 lines with branch, code, account, debit, credit; the totals row shows Debit 200,000.00 and Credit 200,000.00; badge "Balanced".
43. Given voided (12) after the migration, Then the 4 lines show notes "Void contra of line n" on the contras and "Added by double-entry migration <date>" on the back-filled pair.

## 9. Open questions for the accountant (assumption in force)
- **Q1 Inter-branch accounts.** One current account per counterparty branch, whose balance shows due-from (Dr) or due-to (Cr), against separate Due-to and Due-from accounts. Assumed: one net account per counterparty.
- **Q2 Branch-to-branch transfers.** Posted directly between the two branches (KDY ↔ GAL), or routed through head office (KDY owes MAIN, MAIN owes GAL)? Assumed direct. Routing would add MAIN lines to transfers MAIN never touched, and put MAIN's period lock in the path.
- **Q3 Funding in a branch's books.** The branch credits "Inter-branch – MAIN" (a head-office current account), not a branch equity "Head office capital" account. Assumed the current account for opening allocations and top-ups alike, so a fund return simply reduces it.
- **Q4 Category mapping and codes.** One account per category node (the parent has its own "(no sub-category)" account). Codes are 5 digits (class, 2-digit parent, 2-digit sub), the same in every branch, with at most 99 parents per type and 99 subs per parent. Assumed acceptable; codes are not editable.
- **Q5 No year-end close.** Income and expense accumulate in the trial balance from the first entry. Assumed acceptable until AG-092 (fiscal year), which would add retained earnings (32000) and closing entries.
- **Q6 Back-fill in locked months.** Assumed it is allowed as a recorded system exception (it changes no cash balance and no reported figure), with a single summary audit row and `origin = backfill` on each line, not one audit row per transaction. The alternative is to unlock every month, back-fill, and re-lock.
- **Q7 Reclassification date.** Category changes are posted on the entry's original date, so the ledger agrees with I&E (which reports by `txn_date`). Assumed so. Posting on the edit date would make the ledger and I&E disagree for the months in between.
- **Q8 Trial balance basis.** The period columns exclude voided entries (the reports basis) and show reversals gross. The closing includes everything (voids net to 0.00 on their own date). Assumed acceptable.
- **Q9 Liabilities.** The class exists but has no accounts, because there are no manual journals, payables or loans. Assumed manual journals are a separate backlog item; confirm none are needed for go-live.
- **Q10 Capital outside Main.** Today capital may go to an `is_main_account` in a non-main branch. Assumed Owner's capital is then created in that branch, so the branch balances on its own. Alternatively, capital should be restricted to the Main branch. Accountant to say which.
