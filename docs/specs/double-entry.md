# Spec: Full double entry (chart of accounts, balanced postings, trial balance)

## 1. Goal and scope
Product owner decision (2026-11-18): every money entry is full double entry against a per-branch chart of accounts.

Today (`LedgerService.php`):
- Transfers post two lines between cash/bank accounts. A cross-branch transfer balances in total but not inside each branch: the sender has one credit line and the receiver has one debit line.
- Income, expense and capital post **one** line on a cash/bank account. The category sits on `transactions.category_id`, and no income, expense or equity ledger account exists.
- `ledger_entries.debit` means "money in" and `credit` means "money out". `accounts` holds only cash/bank/wallet rows.

After this feature:
- every transaction's debits equal its credits, in total and inside each branch;
- every branch has a chart of accounts (asset / liability / equity / income / expense, plus the inter-branch group);
- inter-branch funding is booked head-office style: each non-main branch has one Head office current account, and MAIN has one Branch current account per branch;
- existing history is back-filled without changing any cash/bank balance;
- a trial balance report exists, per branch and consolidated, with inter-branch eliminations;
- `ledger:verify` proves all of this;
- the four existing reports, the dashboard and the account statements show the same figures as before.

Backlog: there is no item yet. Proposed new rows: **AG-034** "Chart of accounts per branch" (E4) and **AG-089** "Trial balance" (E9). Touches AG-031, AG-040..043, AG-050..054, AG-060, AG-063, AG-082..085, AG-091.

**Out of scope, with follow-ups** (Accounting review findings 11 and 12):
- **Year-end close and retained earnings.** These come with AG-092. AG-092 must ship before the first financial year end after go-live (31 March 2027 if the April–March year is kept). Account code 32000 "Retained earnings" is reserved now; no account is created and nothing posts to it.
- **Owner's drawings.** New backlog item "Owner's drawings": an equity account, e.g. 33000.
- **Manual journals and liabilities.** New backlog item "Manual journals + liabilities": loans, payables, statutory dues. This includes free-form ledger accounts, accruals and prepayments.
- **Help text for drawings and loans.** Until these exist, the income/expense form help text must say: do not book owner drawings, loans received or loan repayments as income or expense. This is a small follow-up on the entry form.
- **Also out of scope:** balance sheet and profit & loss statements, editing account codes, multi-currency.

## 2. Personas and permissions
No new permission key. Existing keys (`PermissionSeeder::PERMISSIONS`):

| Need | Key | Templates that have it |
|---|---|---|
| Chart of accounts screen (structure only, no balances) | `account.view` | Branch Admin, Accountant, Cashier, Viewer |
| Add cash/bank/wallet accounts (and with them, asset accounts) | `account.manage` | Branch Admin, Accountant |
| Add income/expense accounts, through categories | `category.manage` (top level: Super Admin only, as today; a branch user gets a branch-only sub-category) | Branch Admin, Accountant |
| Trial balance screen / export | `report.view` / `report.export` | Branch Admin and Accountant have both; Viewer has view |
| Statement of a non-money ledger account (income, expense, equity, inter-branch) | `report.view` in addition to `account.view` | as above |
| Ledger lines on the entry detail screen | `transaction.view` | all four |
| Capital, allocations, branch creation (these create system accounts) | `super` middleware | Super Admin |

Why no `chart.manage` key: there are no free-form accounts. Every account comes from a source that already has a permission (Accounts, Categories, Branches) or is created by the system. A new key would also be given to every Branch Admin automatically (`PermissionSeeder::run` adds new keys to the `*` template).

Why the chart screen shows no balances: income and expense balances are profit information. A Cashier has `account.view` but not `report.view`. Balances are on the trial balance.

## 3. User flows
- **F1 Record income (Cashier, KDY).** The form is unchanged. The entry detail now shows two lines, Dr 11001 KDY Cash 50,000.00 and Cr 40101 Sales > Retail 50,000.00, with the badge "Balanced".
- **F2 Top-up (Super Admin).** The form is unchanged. The detail shows four lines:
  - MAIN: Dr Branch current account – KDY, Cr 11001 MAIN Bank;
  - KDY: Dr 11001 KDY Cash, Cr Head office current account.
- **F3 Branch transfer (Accountant, KDY → GAL).** The form is unchanged. The detail shows six lines in three branches:
  - KDY: Dr Head office current account, Cr KDY Cash;
  - MAIN: Dr Branch current account – GAL, Cr Branch current account – KDY;
  - GAL: Dr GAL Cash, Cr Head office current account.

  The posting is refused if the month is locked for KDY, GAL **or** MAIN.
- **F4 Change the category of an entry (Accountant).** Edit expense → category Rent → Electricity → Save. Two reclassification lines are added to the same entry on its original date: Dr Electricity, Cr Rent. The I&E report moves the amount from Rent to Electricity, as it does today. A second change to Water posts Dr Water, Cr Electricity.
- **F5 Void / reverse.** The flow is unchanged. A void adds a contra for **every** line; a reversal mirrors **every** line.
- **F6 Chart of accounts (Accountant, KDY).** Menu Accounts > Chart of accounts. KDY's accounts are shown grouped by class, with Inter-branch in its own group. Clicking an account opens its ledger statement.
- **F7 Head-office review (Accountant with MAIN access).** Chart (MAIN) → "Branch current account – GAL" → Ledger. The statement lists the KDY→GAL transfer, and clicking it opens the entry detail. Access is allowed because the entry has lines in MAIN (R10).
- **F8 Month-end (Super Admin, All).**
  1. Reports > Trial balance, view Consolidated, October.
  2. Check that each branch column totals 0.00, the eliminations total 0.00, and consolidated Dr = Cr.
  3. Lock the month, then print and sign.
- **F9 Deploy (operator; Accounting review finding 13).**
  1. Run `php artisan ledger:verify`; it must pass.
  2. Run `php artisan ledger:backfill-double-entry --dry-run`. The accountant signs the output, and it is kept with the deploy record.
  3. Run `php artisan down`, then `php artisan migrate` (chart and back-fill).
  4. Run `php artisan ledger:verify`; it must pass, including the new checks.
  5. Run `php artisan up`, only after step 4 passes.

## 4. Screens
**S1 Chart of accounts** `/chart-of-accounts`.
- **Access:** menu item under Accounts if `auth.can('account.view')`; guard `permissionGuard('account.view')`; reloads on branch switch.
- **Branch:** in branch mode, the active branch. In All mode (Super Admin), a single-select Branch filter, defaulting to the Main branch, with inactive branches tagged.
- **Groups**, in this order:
  1. Assets (money accounts);
  2. Liabilities;
  3. Equity;
  4. **Inter-branch (eliminated on consolidation)**: a branch shows its Head office current account, MAIN shows its Branch current accounts;
  5. Income;
  6. Expenses.

  An empty group shows "No accounts".
- **Columns:** Code | Name | Type | Created from | Status.
  - Type: Cash / Bank / Wallet / Head office current account / Branch current account / Owner's capital / Category.
  - Created from: "Accounts", "Category", "System".
  - Status: Active / Closed / Inactive.
- **Category accounts** are shown as a tree. The parent row is the parent category's own account (postings with no sub-category); sub-category accounts are indented under it.
- **Row action "Ledger"** (account statement): shown for cash/bank/wallet accounts, or for any account when the user has `report.view`.
- **No add, edit or delete** on this screen. Info line: "Cash, bank and wallet accounts are added under Accounts. Income and expense accounts follow Categories. Inter-branch and capital accounts are created by the system." Link "Add account" (to Accounts) if `account.manage`.
- **Errors:** the API `message` in `.alert`.

**S2 Trial balance** `/reports/trial-balance`.
- **Access:** card in the Reports hub; guard `permissionGuard('report.view')`.
- **Filters:** the common report filters (From, To, Branches) plus **View**: "By branch" (default), or "Consolidated" (All mode only, 2 or more branches selected).

By branch: one table per branch. Rows in group order (as S1), then by code.

| Column | Content |
|---|---|
| Code, Account, Class | the account (inactive or closed accounts tagged) |
| Opening Dr, Opening Cr | balance before From |
| Period debits, Period credits | lines From..To, gross, voided transactions excluded |
| Closing Dr, Closing Cr | balance at To |

- A row shows when its closing balance or its period movement is not 0.00.
- **Totals row:** Opening Dr = Opening Cr, Period debits = Period credits, Closing Dr = Closing Cr.
- **Identity:** for every row and the totals, Opening + Period Dr − Period Cr = Closing (signed). This holds because every line of a voided transaction is dated its `txn_date`, so a void nets to 0.00 inside one day (R4 D4, V2).

Consolidated: one table.
- **Rows (consolidation lines):**
  - each cash/bank/wallet account, labelled "KDY · Cash";
  - Owner's capital;
  - one row per category code, summed over branches;
  - in the Inter-branch group, one row per pair "MAIN ↔ KDY", "MAIN ↔ GAL".
- **Columns:** one per selected branch (closing balance, signed: debit positive, credit negative; the header note says so) | Eliminations | Consolidated Dr | Consolidated Cr.
- **Eliminations:** a pair is eliminated only when MAIN and that branch are both selected. Otherwise the reports subset note is shown: "Inter-branch balances with branches not selected are not eliminated."
- **Totals:** every branch column 0.00, Eliminations 0.00, Consolidated Dr = Consolidated Cr. Consolidated rows also satisfy the identity above.

Common to both views:
- If any total or identity fails (never expected), a red `.alert` reads "Out of balance by X. Run ledger:verify." The server logs an error, and the report is still shown.
- Header: as the other reports (company, branches, period, "Amounts in LKR", PROVISIONAL marker). Basis line: "All posted lines; period columns exclude voided entries; income and expense are cumulative since the first entry (no year-end close yet)."
- Export: PDF and Excel with `report.export`, with the header and footer rules of reports finding 4. The PDF ends with the Prepared by / Checked by / Approved by block.

**S3 Entry detail** (`transaction-detail.component.ts`): new section "Ledger lines".
- **Columns:** Date | Branch | Code | Account | Debit | Credit | Note.
- **Note:**
  - "Void contra of line n" (`contra_of_id` set);
  - "Reclassification" (`origin = reclass`);
  - "Added by double-entry migration <date>" (`origin = backfill`).
- **Order:** lines by id. A totals row shows the Debit and Credit totals, with the badge "Balanced" (green) or "Not balanced" (red, never expected).
- **Cross-branch entries** show every branch's lines: account names only, never balances.
- The legacy void note (controls S4) is unchanged.

**S4 Unchanged screens.**
- The Accounts list, the account pickers (income/expense/capital/allocation/transfer forms), transfer destinations, the dashboard account list and branch balances still show **cash/bank/wallet accounts only**.
- The statement of a money account is unchanged.

## 5. API
| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `GET /v1/chart-of-accounts` | `perm:account.view` | `branch_id` (required in All mode; in branch mode absent or equal to the active branch, else 403), `include_inactive` (0/1, default 1) | `{branch:{id,code,name}, groups:[{group:'asset'\|'liability'\|'equity'\|'interbranch'\|'income'\|'expense', accounts:[{id, code, name, class, type, origin:'accounts'\|'category'\|'system', category_id, parent_category_id, counterparty_branch:{id,code}\|null, status}]}]}`. No balances. |
| `GET /v1/reports/trial-balance` | `perm:report.view` | `from`, `to` (Y-m-d, ≤ 366 days), `branches[]` (reports R1), `view` (`branch`\|`consolidated`; consolidated outside All mode or with < 2 branches → 422 "Select at least two branches in All branches mode") | branch: `meta + {sections:[{branch, rows:[{account:{id,code,name,class,type,group,status}, opening_dr, opening_cr, period_dr, period_cr, closing_dr, closing_cr}], totals:{…}, balanced:bool}]}`. consolidated: `meta + {branches:[…], rows:[{key, label, group, by_branch:{id: signed}, elimination, dr, cr}], totals:{by_branch, elimination, dr, cr}, balanced, subset}` |
| `POST /v1/reports/trial-balance/export` | `perm:report.view,report.export` | the same + `format` | file `trial-balance_<from>_<to>.<ext>`; audit `report.exported`. `ReportService::NAMES` gains `trial-balance`. |
| `GET /v1/accounts/{id}/statement` | `perm:account.view`; for a non-money account also `report.view` (403 "Missing permission: report.view") | unchanged | unchanged; works for every account class |
| `GET /v1/accounts`, `/accounts/destinations` | unchanged | unchanged | **money accounts only** (`type` in cash, bank, wallet) |
| `PUT /v1/accounts/{id}` | unchanged | unchanged | non-money account → 422 "This account belongs to the chart of accounts and changes with its category or branch." |
| `GET /v1/transactions/{id}` | `perm:transaction.view` | unchanged | `entries[]` gains `origin`, `contra_of_id`, `account:{id,code,name,class,type}`, `branch:{id,code}`; the root gains `balanced` (bool). Access per R10: the user has access to `transactions.branch_id` **or** to the branch of any of its lines. Otherwise 404, as today. |
| `PUT /v1/transactions/{id}` | unchanged | unchanged | a category change goes through `LedgerService::reclassify` (R3). Audit `transaction.reclassified` for the category; `transaction.updated` for the other fields only. |
| `POST /v1/branches` | `super` | unchanged | 422 "No more branches can be created (inter-branch account codes are limited to branch id 999)." when the new id would exceed 999 |
| `POST /v1/incomes`, `/expenses`, `/capital`, `/allocations`, `/transfers` | unchanged | unchanged | 422 "Choose a cash, bank or wallet account." if any picked account is not a money account |

**Schema.** Two migrations, dated after `2026_11_21_000000_create_attachments_table.php`.

1. `…_double_entry_schema.php`
   - `accounts`:
     - `class` string(10), not null, default `asset` (asset|liability|equity|income|expense);
     - `type` gains `head_office`, `branch_current`, `owner_capital`, `category` (`Account::MONEY_TYPES = [cash, bank, wallet]`, scope `money()`; `Account::INTERBRANCH_TYPES = [head_office, branch_current]`);
     - `code` string(10), nullable; unique (`branch_id`, `code`);
     - `category_id` nullable FK; unique (`branch_id`, `category_id`);
     - `counterparty_branch_id` nullable FK → branches; unique (`branch_id`, `counterparty_branch_id`).
   - `categories`: `ledger_code` string(5), nullable, unique.
   - `ledger_entries`: `origin` string(10), not null, default `posting` (posting|reclass|backfill).
     - `ADD COLUMN` only. Do **not** add a CHECK through the schema builder: on SQLite that rebuilds the table and drops the immutability triggers (controls migration note).
   - New `BEFORE INSERT` trigger `ledger_entries_line_check` (MySQL + SQLite): D1–D3 in R4.
   - Preflight (`MigrationPreflight`): refuse to start if any existing line breaks D1–D4 (same pattern as controls R2/R3).
2. `…_double_entry_backfill.php`: chart creation (R1) and back-fill (R6), using the code that `ledger:backfill-double-entry` uses.

## 6. Business rules

### R1 Chart of accounts (per branch)
Codes are 5 digits, and the first digit is the class.

| Code | Account | Class | Type | Where / created |
|---|---|---|---|---|
| 11001–11999 | each cash / bank / wallet account (existing ones numbered by id) | asset | cash\|bank\|wallet | every branch; created with the account (Accounts screen, or the "Cash" account on branch creation) |
| 15 + MAIN's id (3 digits) | **Head office current account** | asset (shown Dr or Cr by sign; group Inter-branch) | head_office | every **non-main** branch; exactly one; created with the branch |
| 15 + branch id (3 digits), e.g. 15002 | **Branch current account – <code>** | asset (group Inter-branch) | branch_current | **MAIN only**, one per non-main branch; created with that branch |
| 31000 | Owner's capital | equity | owner_capital | **MAIN only**; created at migration |
| 32000 | Retained earnings | equity | — | reserved, not created (AG-092) |
| 4PPSS | income category account | income | category | per category, see below |
| 5PPSS | expense category account | expense | category | per category |
| 2xxxx | (none in this feature) | liability | — | — |

**Inter-branch accounts.**
- There are no branch-to-branch accounts (no KDY↔GAL). Reconciliation is a star: one pair (MAIN, B) per non-main branch.
- A branch's Head office current account normally has a credit balance: what head office has put into the branch. MAIN's Branch current account – B normally has the matching debit balance.
- Branch creation is refused when the new branch id would be above 999 (code limit).

**Categories become accounts.**
- Each category **node** gets one ledger account per branch where it can be used:
  - a global category: every branch, active and inactive;
  - a branch-only sub-category: its own branch only.
- The parent node's account (4PP00 / 5PP00) takes postings made to the parent with no sub-category, which is the I&E "(no sub-category)" line.

**Category codes.**
- `categories.ledger_code` holds the code. It is the same in every branch, which is how the consolidated trial balance adds branches together.
- PP is the parent sequence (01–99) per type, in creation order. SS is the sub-category sequence (01–99) under its parent. At migration, codes are assigned in (type, parent name, sub name) order.
- A 100th parent or sub gives 422 "No more category codes are available under <parent>. Ask the accountant."
- Branch-only sub-categories with the same name in two branches get different codes and are not combined on consolidation.

**Names and status.**
- Category accounts: "<Parent> > <Sub>", or "<Parent>". Inter-branch: "Head office current account", "Branch current account – <code>". Capital: "Owner's capital".
- Renaming a category renames its accounts. Deactivating a category sets its accounts to `inactive`; reactivating sets them back. Audit `account.updated` (system, `self` false).

**Creation.**
- Branch creation (`BranchController::store`) creates, in one DB transaction:
  - the Cash account (as today);
  - the branch's Head office current account and MAIN's Branch current account for it;
  - the global category accounts.
- Creating a category creates its accounts in the same DB transaction.
- Safety net: when a posting needs a system or category account that is missing, `LedgerService` creates it inside the posting transaction (`firstOrCreate` on the unique keys) and writes `account.created`. The exception is Owner's capital, which is only ever in MAIN.
- Chart accounts are never deleted, and codes are never reused.

**Balances and locking.**
- `current_balance` is kept for every account class, by `LedgerService::entry` only. It is always the signed value debit − credit, so credit-normal accounts hold negative values. Screens never show it for non-money accounts; the trial balance reads the ledger.
- `LedgerService::lock` locks **all** accounts of a posting (money and chart accounts) in id order, as today.

### R2 Meaning of debit and credit
The usual meaning applies on every account: debit increases assets and expenses; credit increases liabilities, equity and income. For a money account this is the same as today's "debit = money in, credit = money out", so no existing line changes meaning. Update the docblocks in `LedgerService` and `create_accounting_tables`.

### R3 Posting rules
Notation: "HO in B" is branch B's Head office current account. "BCA(B)" is MAIN's Branch current account – B. All lines are dated `txn_date` unless the table says otherwise.

| Transaction | Branch | Debit | Credit |
|---|---|---|---|
| Income (money account A, category C) | A's branch | A | Income C |
| Expense (A, C) | A's branch | Expense C | A |
| Capital into MAIN account A | MAIN | A | Owner's capital |
| Capital into an `is_main_account` A of non-main branch B (4 lines) | MAIN | BCA(B) | Owner's capital |
| | B | A | HO in B |
| Opening allocation, MAIN account A → branch B account Z | MAIN | BCA(B) | A |
| | B | Z | HO in B |
| Top-up / fund allocation | same as opening allocation | | |
| Fund return, branch B account Z → MAIN account A | B | HO in B | Z |
| | MAIN | A | BCA(B) |
| Branch transfer, branch B account Z → branch G account Y (6 lines) | B | HO in B | Z |
| | MAIN | BCA(G) | BCA(B) |
| | G | Y | HO in G |
| Internal transfer A → A2 (same branch) | that branch | A2 | A |
| Reclassification of an expense, current category C → C2 (`origin = reclass`, same transaction, dated the original `txn_date`) | entry's branch | Expense C2 | Expense C |
| Reclassification of an income, C → C2 | entry's branch | Income C | Income C2 |
| Void of income/expense (status → void) | each line's branch | one contra per line (see `void` below) | |
| Reversal (new transaction, type reversal) | each line's branch | every line of the original mirrored (see `reverse` below) | |

The money line comes first in each set.

**`void`** (Accounting review finding 3):
- It locks **every** account on the transaction's lines, in id order.
- It posts one contra for each line that is not itself a contra (posting, reclass and backfill lines). The contra is on that line's own account and branch, dated that line's `entry_date` (always `txn_date`, D4), with the sides swapped and `contra_of_id` set to the line.
- It fixes today's bug: `LedgerService::void` locks only `$txn->account_id` and posts every contra on that one account.

**`reverse`:**
- It mirrors every line of the original, including back-filled and reclass lines, dated the reversal date.

**Funds check (`void` and `reverse`):**
- It applies only to a **money** account being credited, and runs on the net credit per money account in the set. Today `reverse` checks every debit line, which would test an expense account's balance.

**`reclassify(Transaction, int $categoryId)`** (finding 4), called by `TransactionController::update` instead of writing `category_id` directly:
- Allowed only for income/expense with status `posted`.
- Moves the amount from the **current** category to the new one. A change C→C2 followed by C2→C3 posts Dr C3 / Cr C2 (for an expense), never from the original C.
- Posts nothing when the category is unchanged. An edit of payee or description posts nothing.
- Inside the posting lock it re-validates the new category (active, same type, global or of the entry's branch). It also re-checks the period with the locking read.
- Updates `transactions.category_id` in the same DB transaction as the lines.
- Writes `transaction.reclassified` `{old_category_id, new_category_id, amount, line_ids}` instead of a bare `transaction.updated` for the category.

**Money accounts only:** capital, allocation, transfer and income/expense refuse any non-money account with `LedgerException` "Choose a cash, bank or wallet account."

### R4 Balance rule (enforced)
**L1, in LedgerService.** Every posting method:
1. builds its lines as a list first;
2. checks, in integer cents, that debits = credits for the whole set **and** for each branch in it;
3. writes the lines;
4. re-reads `SUM(debit)` and `SUM(credit)` of the set per branch in the same DB transaction, before commit.

Any mismatch throws `LedgerException('Posting is not balanced.')` and rolls everything back. A "set" is one batch: the original posting, one reclass, one void's contras, or one back-fill. Each set balances on its own, so the transaction always balances.

**L2:** every line has exactly one side > 0, and neither side is negative.

**Database checks** (trigger `ledger_entries_line_check`, BEFORE INSERT):
- **D1:** `debit >= 0 AND credit >= 0`, and exactly one of them `> 0`.
- **D2:** `branch_id` equals the account's `branch_id`.
- **D3:** when `contra_of_id` is set, the referenced line has the same `account_id` and `transaction_id`, and `debit`/`credit` are swapped.

The database refuses a failing line with "ledger_entries line check failed (<rule>)."

**D4 (service rule and verify, not a trigger):** every line of an income/expense transaction is dated its `txn_date`. Reversals are separate transactions. This is what lets the trial balance exclude voided transactions and still reconcile.

**Not feasible in the database:** "debits = credits per transaction". MySQL has no deferred constraints or statement-level triggers, so a row trigger would refuse the first line of every posting. L1, `ledger:verify` (R7) and the lock check (R9) cover it. Do not add a balance trigger.

### R5 Transaction amount
`transactions.amount` stays the single amount entered. In every set, the money-account lines on each side total `amount`. Total debits of a set:

| Set | Total debits |
|---|---|
| income, expense, capital into MAIN, internal transfer | `amount` |
| opening allocation, top-up, fund return, capital into a non-main branch | 2 × `amount` |
| branch transfer | 3 × `amount` |

Reports keep using `amount` where they use it today.

### R6 Data migration (back-fill)
**Preflight.** The migration refuses to start, and lists the offenders, if:
- any `accounts.current_balance` differs from its ledger balance (existing `ledger:verify` check);
- any existing line breaks D1–D4;
- any income/expense has a null `category_id`, or a category whose `branch_id` is set and differs from the entry's branch.

**Chart (R1).**
- Give `ledger_code` to every category, and 11xxx codes to every existing money account (by id, per branch).
- Create the Head office / Branch current account pair for every non-main branch, Owner's capital in MAIN, and the category accounts.
- Write one audit row `ledger.chart_created` `{by_branch:{code:{accounts:n}}}` (user null, branch null).

**Back-fill.** Transactions are processed in id order, one DB transaction each:
- A transaction that already balances per branch is skipped.
- A transaction that has `origin = backfill` lines but does **not** balance stops the run with its ref named. A second back-fill set is never added.
- Inactive category accounts and inactive branches' accounts receive lines. They are history, and the "active" checks do not apply to the back-fill.

| Existing transaction | Lines added (`origin = backfill`, dated as the line they complete) |
|---|---|
| income / expense, posted or reversed | Cr Income C / Dr Expense C, on `txn_date` |
| income / expense, void with contra lines | that line **and** its contra (`contra_of_id` = the new line, same date) |
| capital into MAIN | Cr Owner's capital (1 line) |
| capital into a non-main branch B | MAIN: Dr BCA(B), Cr Owner's capital; B: Cr HO in B (3 lines) |
| opening allocation, top-up, fund return | sender's inter-branch line and receiver's inter-branch line (2 lines) |
| branch transfer B → G | B: Dr HO in B; MAIN: Dr BCA(G), Cr BCA(B); G: Cr HO in G (4 lines) |
| reversal of any of the above | the mirror of what the original received, on the reversal's date. The category is the **original's** `category_id` (the I&E basis). |
| internal transfer and its reversal | nothing (already balanced in one branch) |
| legacy void (status void, no lines; `LegacyVoids`) | nothing; they stay "identified only" (controls R6) |

**Safety.**
- Lines are **inserted** only. No `ledger_entries` row is updated or deleted, and the immutability triggers stay on the whole time.
- No money account gets a line, so no cash/bank/wallet `current_balance` changes. The migration snapshots those balances before, compares them after, and throws on any difference.
- The chart accounts' `current_balance` is set through `LedgerService`'s private back-fill path, via `entry()`. That is the only allowed writer (CLAUDE.md rule).

**Lock exemption (finding 6).**
- Only the **private** back-fill path inside `LedgerService` skips `assertOpen`. No public method has an `$ignoreLocks` flag or similar.
- That path may insert only `origin = backfill` lines, and never on a money account. It throws otherwise.

**Audit.** Each run (first run or re-run) writes its own row `ledger.double_entry_backfilled` (user null, branch null) with that run's counts: `{transactions, lines, by_type:{…}, by_branch:{…}, locked_periods:[{branch, period, lines}], money_balances_unchanged:true, skipped_legacy_voids}`. Back-filled lines carry `origin = backfill`, and their `created_at` is the run time.

**`php artisan ledger:backfill-double-entry --dry-run`** prints the same counts and a sample of 20 transactions with the lines that would be added. It writes nothing. Without `--dry-run`, it does what the migration does (for a re-run).

### R7 `ledger:verify` additions
Each check prints its count, lists offenders (ref, date, branch, difference) and exits 1 on any failure. The existing checks stay: legacy voids, and cached balances for **all** accounts, now including chart accounts.

- **V1:** every transaction except legacy voids has lines, and SUM(debit) = SUM(credit) for the transaction and for each branch in it.
- **V2:**
  - every line is one-sided (D1);
  - line branch = account branch (D2);
  - contra lines mirror their originals (D3);
  - every line of an income/expense transaction, and in particular of a `void` one, has `entry_date = txn_date` (D4).
- **V3:** the trial balance nets to zero: SUM(debit) = SUM(credit) over all lines, for each branch and for all branches together.
- **V4:** inter-branch pairs eliminate. For every non-main branch B, balance(HO in B) + balance(BCA(B) in MAIN) = 0.00. No other inter-branch account exists.
- **V5:** category accounts agree with the I&E basis. For each (branch, category), the account's ledger balance (credit − debit for income, debit − credit for expense) equals the all-time I&E figure from `ReportService::countedTransactions`.
- **V6:** the back-fill is complete. No income, expense, capital or cross-branch transaction has only money-account lines.

### R8 Existing reports and screens stay the same
All ledger-based queries filter to money accounts (`accounts.type IN (cash, bank, wallet)`). Places to change (confirmed complete by the accountant):
- `ReportService::branchComparison`: the `$before` opening/closing query and `$moves`. Join `accounts` and filter.
- `ReportService::cashBook` (account list and opening) and `cashBookRows`.
- `DashboardController` lines 62, 66 and 68: total balance, account list, balance per branch.
- `BranchController::index`, line 19: the `withSum` balance.
- `AccountController::index` and `destinations`.

`incomeExpense`, `transfers` and the dashboard income/expense figures read `transactions` and do not change. A branch transfer adds lines in MAIN only on inter-branch accounts, so MAIN's money figures do not change. The statement already filters by `account_id`.

The E-dataset figures (reports spec section 7, E-1..E-4) must be identical before and after the migration (AC 27).

**Inter-branch reconciliation with the Transfers report** (finding 8). For each branch and period, the inter-branch movement equals the Transfers report "Net" for that branch, excluding capital into non-main branches:
- non-main branch B: credits − debits on HO in B = Net(B);
- MAIN: debits − credits summed over its Branch current accounts = −Net(MAIN).

### R9 Period lock
**Every new set** asserts open for each (branch, line date) in the set:

| Set | Branches that must be open |
|---|---|
| income, expense, reclass, void | the entry's branch (on `txn_date`) |
| opening allocation, top-up, fund return | MAIN and the branch |
| capital into a non-main branch | MAIN and the branch |
| branch transfer and its reversal | sender, receiver **and MAIN** |

If MAIN is the locked branch for a branch transfer, the message is "The period YYYY-MM is locked for MAIN (head office side of this transfer)."

**Back-fill exemption.** The back-fill changes no money balance and no figure in the four reports or the dashboard; it only adds the missing side. So it runs in locked months, confined as described in R6. A locked month's trial balance shows figures for the first time after the migration. Every real posting, reclass, void and reversal dated in a locked month is still refused after the migration.

**New control.** `POST /period-locks` refuses to lock while any transaction dated in the scope (the branch or all branches, cumulative through the period) breaks V1. The response is 422 "N entries up to October 2026 are not balanced. Run ledger:verify." This is one grouped query.

The trial balance shows PROVISIONAL like the other reports (`ReportService::provisional`).

### R10 Edge cases and access
- **Inactive branch:** its accounts stay. Posting to it is already refused (inactive branch). Its pair stays in eliminations.
- **New branch after history exists:** the pair with MAIN and the category accounts are created with zero balances.
- **Closing accounts:** a money account can be closed with a zero balance, as today. Chart accounts cannot be closed (422 in the API table).
- **A branch-only sub-category used before migration:** its account is created in that branch only.
- **`expense_negative_balance = warn`:** applies to the money account only.
- **Concurrent postings** to the same category in a branch wait on that account's row lock; this is accepted. Every branch transfer also locks MAIN's two Branch current accounts. Postings are short, so this is accepted too.
- **Detail access (finding 10).**
  - `GET /transactions/{id}` resolves the transaction without the branch scope. It then allows the request if the user can act in `transactions.branch_id` **or** in the branch of any of its lines. Otherwise it returns 404, as today.
  - So a MAIN user can open the KDY→GAL transfer from MAIN's Branch current account statement, and GAL can open it too.
  - A Super Admin in All mode can open any transaction.
  - The same check applies to the statement's links. Edit, void and reverse keep their existing rules, which are based on `transactions.branch_id`.
- **Chart and trial balance branch scope:** `chart-of-accounts` and `trial-balance` follow reports R1: one branch in branch mode, any set in All mode.

## 7. Worked example (reports spec E-dataset; QA and accountant check)
**Codes:**
- money: MAIN Bank 11001, KDY Cash 11001, KDY Bank 11002, GAL Cash 11001;
- MAIN id 1, KDY id 2, GAL id 3: HO in KDY and HO in GAL = 15001; in MAIN, BCA(KDY) = 15002 and BCA(GAL) = 15003;
- Owner's capital 31000;
- income: Sales 40100 > Retail 40101, Service income 40200;
- expense: Rent 50100, Utilities 50200 > Electricity 50201, Water 50202.

QA asserts by account, not by code number.

**E-5 Back-fill of the E-dataset.** There are 17 originals and 3 reversals (20 transactions), with 29 lines before the back-fill.
- 19 transactions get **33** lines; internal transfer (8) gets none.

| Transactions | Lines added each |
|---|---|
| capital | 1 |
| opening allocations S2, S3, top-up (6), fund return (9) | 2 |
| branch transfers (7), (11) and the reversal of (11) | 4 |
| incomes/expenses (S4, 1–5), (10), reversal of (10), (13), reversal of (13) | 1 |
| voided (12) | 2 (Dr Rent and its contra Cr Rent) |

- That is 1 + 4×2 + 3×4 + 10×1 + 2 = 33. There are **62** lines after the back-fill.
- Money balances are unchanged: MAIN Bank 620,000.00, KDY Cash 254,300.00, KDY Bank 40,000.00, GAL Cash 137,200.00 at 2026-10-31.

**E-6 Trial balance by branch, 2026-10-01..31 (closing):**

| Branch | Debit balances | Credit balances | Total |
|---|---|---|---|
| MAIN | Bank 620,000.00; BCA–KDY 270,000.00; BCA–GAL 110,000.00 | Owner's capital 1,000,000.00 | 1,000,000.00 |
| KDY | Cash 254,300.00; Bank 40,000.00; Rent 30,000.00; Electricity 8,200.00 | HO current account 270,000.00; Retail 50,000.00; Service income 12,500.00 | 332,500.00 |
| GAL | Cash 137,200.00; Water 1,800.00 | HO current account 110,000.00; Retail 29,000.00 | 139,000.00 |

- GAL Rent is not listed: the void nets it to 0.00 and its lines are excluded from the period columns.
- KDY October period columns:
  - Electricity: debits 13,200.00 (8,200 + 5,000), credits 5,000.00 (the reversal);
  - Retail: credits 50,000.00;
  - HO current account: debits 40,000.00 ((7) 30,000 + reversal of (11) 10,000), credits 110,000.00 ((6) 100,000 + (11) 10,000).
- KDY opening at 10-01: Cash 170,000.00 Dr, Rent 30,000.00 Dr, HO current account 200,000.00 Cr.
- Identity check, KDY HO current account: −200,000.00 + 40,000.00 − 110,000.00 = −270,000.00.

**E-7 Consolidated, All, 2026-10-01..31:**
- Eliminations: MAIN ↔ KDY 270,000.00 − 270,000.00 = 0.00; MAIN ↔ GAL 110,000.00 − 110,000.00 = 0.00. There is no KDY ↔ GAL row.
- Consolidated Dr:
  - MAIN·Bank 620,000.00, KDY·Cash 254,300.00, KDY·Bank 40,000.00, GAL·Cash 137,200.00 (cash 1,051,500.00 = E-2 Total closing);
  - Rent 30,000.00, Electricity 8,200.00, Water 1,800.00;
  - total **1,091,500.00**.
- Consolidated Cr: Owner's capital 1,000,000.00, Retail 79,000.00, Service income 12,500.00; total **1,091,500.00**.

**E-8 GAL, 2026-11-01..30:** Retail opening 29,000.00 Cr, period debits 4,000.00 (reversal of (13)), closing 25,000.00 Cr. That movement equals I&E GAL November Retail −4,000.00. GAL Cash closing 133,200.00.

**E-9 Inter-branch vs Transfers report, October** (R8; E-3 Net):
- KDY HO credits − debits = 110,000.00 − 40,000.00 = **+70,000.00**.
- GAL HO credits − debits = (30,000 + 10,000) − (20,000 + 10,000) = **+10,000.00**.
- MAIN Branch current accounts, debits − credits: BCA–KDY (110,000 − 40,000) + BCA–GAL (40,000 − 30,000) = 80,000.00 Dr, so Net **−80,000.00**.

## 8. Acceptance criteria
**Posting**
1. Given KDY Cash and active category Retail, When income 50,000.00 is posted, Then the entry has exactly 2 lines: Dr KDY Cash 50,000.00 and Cr KDY "Sales > Retail" 50,000.00, both with `origin = posting` and dated `txn_date`. KDY Cash `current_balance` rises by 50,000.00.
2. Given an expense of 8,200.00 on KDY Cash in Electricity, Then the lines are Dr Electricity 8,200.00 and Cr KDY Cash 8,200.00.
3. Given income posted to the parent "Service income" (no sub-category), Then the credit line is on the parent node's account (40200, "Service income").
4. Given capital of 1,000,000.00 to MAIN Bank, Then the lines are Dr MAIN Bank and Cr MAIN Owner's capital, and no branch other than MAIN has an Owner's capital account.
5. Given an `is_main_account` A in KDY, When capital of 50,000.00 is posted to A, Then there are 4 lines: MAIN Dr BCA–KDY / Cr Owner's capital, and KDY Dr A / Cr HO current account. When the month is locked for MAIN only, Then 422 and nothing is posted.
6. Given a top-up MAIN Bank → KDY Cash of 100,000.00, Then there are 4 lines: MAIN Dr BCA–KDY / Cr Bank, and KDY Dr Cash / Cr HO current account. Debits equal credits inside MAIN and inside KDY. The same holds for an opening allocation.
7. Given a fund return GAL Cash → MAIN Bank of 20,000.00, Then the lines are GAL Dr HO current account / Cr Cash, and MAIN Dr Bank / Cr BCA–GAL.
8. Given a branch transfer KDY Cash → GAL Cash of 30,000.00, Then there are 6 lines, all on `txn_date`: KDY Dr HO / Cr Cash; MAIN Dr BCA–GAL / Cr BCA–KDY; GAL Dr Cash / Cr HO. Each branch balances, and total debits are 90,000.00.
9. Given an internal transfer KDY Cash → KDY Bank, Then there are exactly 2 lines and no inter-branch line.
10. Given a posted expense with 2 lines, When it is voided, Then 2 contra lines are added. Each is on its original's account and date, with the sides swapped and `contra_of_id` set. The transaction has 4 lines, and every account nets to 0.00 for it.
11. Given the branch transfer in AC 8, When it is reversed on a later date, Then the reversal transaction has 6 mirrored lines dated the reversal date, balanced in each of KDY, MAIN and GAL.
12. Given a branch transfer KDY → GAL, When the month is locked for MAIN only, Then 422 "The period YYYY-MM is locked for MAIN (head office side of this transfer)." and nothing is posted. The same applies to its reversal.
13. Given a posted expense in Rent in an open month, When an Accountant changes its category to Electricity, Then 2 lines with `origin = reclass` are added on the original date (Dr Electricity, Cr Rent), `category_id` is Electricity, and an audit row `transaction.reclassified` lists the old and new category, the amount and the line ids. I&E for that month shows the amount under Electricity.
14. Given the entry in AC 13, When the category is then changed to Water, Then the new lines are Dr Water / Cr Electricity (not from Rent). When it is then voided, Then 6 contra lines are added, and Rent, Electricity, Water and the cash account each net to 0.00 for the transaction.
15. Given an edit that changes only the description, or sets the same category, Then no line is added and no `transaction.reclassified` row is written. Given a reversed or void entry, When its category is changed, Then 422 "This entry cannot be edited."
16. Given the API, When `account_id` (income, expense, capital) or `from_account_id` / `to_account_id` (transfer, allocation) is a chart account (category, inter-branch, capital), Then 422 "Choose a cash, bank or wallet account." and nothing is posted.
17. Given a test that makes a posting set unbalanced (e.g. a stubbed line builder), Then `LedgerException` "Posting is not balanced." is thrown, and no line, transaction row or balance change remains.
18. Given a raw insert into `ledger_entries` that is any of the following, Then the database refuses it (on MySQL and on SQLite):
    - debit 0 and credit 0;
    - both sides > 0;
    - a negative side;
    - a `branch_id` other than the account's;
    - a contra that does not mirror its original.
19. Given 2 concurrent income postings to the same category in KDY, Then both succeed, and the category account's `current_balance` equals its ledger balance.

**Chart of accounts**
20. Given a new global expense sub-category "Internet" under Utilities, Then every branch gets an account "Utilities > Internet" with the same `ledger_code`. Given a branch-only sub-category created by a KDY Accountant, Then only KDY gets the account.
21. Given category Retail is renamed to "Retail sales", Then every branch's account is renamed. When it is deactivated, Then its accounts show Inactive on the chart, and posting to it is refused (as today).
22. Given Super Admin creates branch MTR, Then MTR gets Cash (11001), its Head office current account and every global category account, and MAIN gets "Branch current account – MTR". KDY and GAL get no new account. Given the next branch id would be 1000, Then 422 and no branch is created.
23. Given a Cashier in KDY, When opening `/chart-of-accounts`, Then:
    - KDY's accounts are listed in the groups Assets, Liabilities, Equity, Inter-branch (eliminated on consolidation), Income, Expenses;
    - there is no balance column;
    - there is no "Ledger" action on non-money accounts;
    - `GET /accounts/{HO id}/statement` returns 403 "Missing permission: report.view".

    Given an Accountant, Then "Ledger" opens the statement of any account.
24. Given MAIN's chart, Then the Inter-branch group lists "Branch current account – KDY" and "– GAL", and there is no Owner's capital outside MAIN.
25. Given a KDY user, When `GET /chart-of-accounts?branch_id=<GAL>`, Then 403. Given Super Admin in All mode without `branch_id`, Then 422.
26. Given the Accounts screen, the dashboard and every account picker, Then only cash, bank and wallet accounts appear. `PUT /accounts/{category account}` returns 422.

**Existing reports unchanged**
27. Given the E-dataset, When I&E, Branch Comparison, Transfers and Cash Book run for the periods in reports ACs 1–34, before and after the back-fill, Then every response is identical (E-1..E-4 unchanged). The dashboard totals and branch balances are identical too.
28. Given new postings after the migration (an income, a top-up, a branch transfer and a reversal), Then Branch Comparison still reconciles (no 500), MAIN's Branch Comparison row is unchanged by the branch transfer, and the Cash Book lists only money accounts.
29. Given the E-dataset, When the HO current account and MAIN's Branch current accounts are compared with the Transfers report for October, Then KDY +70,000.00, GAL +10,000.00 and MAIN −80,000.00 (80,000.00 Dr), as in E-9.

**Migration**
30. Given the E-dataset loaded on the pre-feature schema, When `migrate` runs, Then:
    - 33 lines with `origin = backfill` are inserted into 19 transactions (E-5), none on a money account, for 62 lines in total;
    - every money account's `current_balance` is unchanged;
    - no existing line is changed;
    - one `ledger.double_entry_backfilled` row has `transactions = 19` and `lines = 33`.
31. Given voided expense (12), Then the back-fill adds Dr Rent and a contra Cr Rent whose `contra_of_id` is the back-filled line, both on 10-20.
32. Given an existing capital transaction into an `is_main_account` of KDY, Then the back-fill adds 3 lines (MAIN Dr BCA–KDY, MAIN Cr Owner's capital, KDY Cr HO current account), and KDY has no Owner's capital account.
33. Given September 2026 locked for all branches, When the migration runs, Then September transactions are back-filled anyway, and the audit row lists each locked (branch, period) with its line count.
34. Given the migration has run, When `ledger:backfill-double-entry` runs again, Then it adds 0 lines and writes its own audit row with `lines = 0`. Given a run stopped half-way (simulated exception), When it runs again, Then the lines and balances equal those of a single clean run.
35. Given a transaction with `origin = backfill` lines that does not balance (test, triggers aside), When the back-fill runs, Then it stops, names the transaction, and adds no second back-fill set.
36. Given a voided income in a category that is now inactive, and an account of an inactive branch, Then both are back-filled.
37. Given any of the following, When the migration runs, Then it stops before changing anything and names the offender:
    - an account whose `current_balance` differs from its ledger;
    - an income with a null `category_id`;
    - a branch-only category used by another branch's entry.
38. Given a legacy void (no lines), Then it gets no lines, and `ledger:verify` still lists it as a legacy void.
39. Given `--dry-run`, Then counts are printed, and the lines, accounts and audit rows are unchanged.
40. Given the migration has run and September is locked, When a normal income, a reclass, a void and a reversal dated in September are attempted, Then each returns 422 "The period 2026-09 is locked." and nothing is posted. No public `LedgerService` method accepts a lock bypass.

**Trial balance**
41. Given the E-dataset, When the trial balance runs by branch for 2026-10-01..31 in All mode, Then:
    - the closings equal E-6 (MAIN 1,000,000.00, KDY 332,500.00, GAL 139,000.00);
    - each branch's totals agree;
    - GAL Rent is not listed;
    - KDY's period and opening columns equal the E-6 notes.
42. Given the same with view Consolidated, Then the figures equal E-7: every branch column totals 0.00, Eliminations totals 0.00 (MAIN ↔ KDY 270,000.00, MAIN ↔ GAL 110,000.00, no KDY ↔ GAL row), and Consolidated Dr = Cr = 1,091,500.00.
43. Given Consolidated with only KDY and GAL selected, Then nothing is eliminated, the HO current account shows in both branches, the subset note is shown, and Consolidated Dr = Cr.
44. Given any period, by branch and consolidated, Then for every row and for the totals, Opening + Period Dr − Period Cr = Closing (signed), including periods that contain voided (12) and reversals (10), (11), (13).
45. Given GAL, 2026-11-01..30, Then Retail equals E-8.
46. Given a KDY Accountant, Then only KDY's section is returned; `view=consolidated` returns 422; `branches[]=GAL` returns 403.
47. Given October not locked for a branch in scope, Then PROVISIONAL is shown. Given exports, Then the PDF and Excel totals equal the screen, the header and footer follow reports finding 4, and `report.exported` is written.
48. Given any period, branch and category, Then the trial balance's period credits − debits (income) or debits − credits (expense) equal the I&E report figure for the same period and branch.

**ledger:verify and locks**
49. Given the migrated E-dataset, When `ledger:verify` runs, Then V1–V6 pass and it exits 0.
50. Given a test database where one transaction's lines were inserted unbalanced (triggers bypassed in the test), When `ledger:verify` runs, Then V1 and V3 name that transaction's ref and the difference, and it exits 1. When a Super Admin then locks that month, Then 422 "1 entries up to <Month YYYY> are not balanced. Run ledger:verify."
51. Given a test line of a void transaction dated other than its `txn_date`, When `ledger:verify` runs, Then V2 reports it and it exits 1.
52. Given a locked month, When a top-up dated in it is posted and only the receiving branch is locked, Then 422 "The period YYYY-MM is locked." and nothing is posted.

**Entry detail and access**
53. Given the top-up in AC 6, When its detail is opened, Then "Ledger lines" lists 4 lines with branch, code, account, debit and credit. The totals row shows Debit 200,000.00 and Credit 200,000.00, with the badge "Balanced".
54. Given voided (12) after the migration, Then its 4 lines show "Void contra of line n" on the contras and "Added by double-entry migration <date>" on the back-filled pair.
55. Given a user whose only branch is MAIN, When opening the KDY→GAL branch transfer (from the BCA–GAL statement), Then the detail loads with all 6 lines. Given a user of a fourth branch MTR only, Then 404. The MAIN user sees no Void or Reverse action, because those rules follow `transactions.branch_id`.

## 9. Decisions on the open questions (Accounting review)
- **Q1 Inter-branch accounts:** one net current account per counterparty. With Q2, a branch has exactly one, with MAIN.
- **Q2 Branch-to-branch transfers:** routed through Head Office, 6 lines; MAIN's period must be open (finding 1).
- **Q3 Funding in a branch's books:** the Head office current account, never branch equity or income.
- **Q4 Category codes:** as specified. Codes are not editable. Same-name branch-only sub-categories in two branches are not combined.
- **Q5 Year-end close:** income and expense are cumulative until AG-092. AG-092 must ship before the first year end after go-live (see section 1).
- **Q6 Back-fill in locked months:** a recorded system exception, confined as in R6 and R9.
- **Q7 Reclassification date:** the original date. Corrections to a locked month are made by reversal in an open month and re-entry.
- **Q8 Trial balance basis:** period columns exclude voided entries, and reversals are shown gross. The condition is D4 / V2 / AC 44.
- **Q9 Liabilities:** none for go-live. Journals and liabilities are a follow-up (section 1).
- **Q10 Capital outside Main:** Owner's capital exists only in MAIN. Capital into a non-main account posts 4 lines.

No open questions remain.

## Accounting review (accountant, 2026-11-18, before development)

Reviewed against `LedgerService.php`, the accounting/controls migrations, `ReportService`, `TransactionController::update`, `DashboardController`, `BranchController` and `AccountController`. Overall the design is sound: one chart per branch, every set balanced per branch, D1–D3 in the database, L1 in the service, `ledger:verify` V1–V6 and money-account reports left alone. The changes below are mostly a result of two decisions (Q2 and Q10).

### Findings

1. **MUST — Branch-to-branch transfers go through Head Office (Q2).** Each non-main branch has exactly **one** inter-branch account, "Head office current account" (IB(MAIN) in B). MAIN has one "Branch current account – <code>" (IB(B) in MAIN) per branch. There are no branch-to-branch (KDY↔GAL) accounts. Changes:
   - **R1:** the 15xxx row becomes "one pair per non-main branch with MAIN, created with the branch". AC 19: MTR gets IB–MAIN only; MAIN gets IB–MTR; KDY and GAL get nothing.
   - **R3, branch transfer B account Z -> G account Y (3 branches, 6 lines, all on `txn_date`):**
     - B: Dr IB(MAIN) in B, Cr Z;
     - MAIN: Dr IB(G) in MAIN, Cr IB(B) in MAIN;
     - G: Dr Y, Cr IB(MAIN) in G.
   - **R5:** for a branch transfer, the set's total debits = 3 × `amount` (allocation/return: 2 × `amount`).
   - **R9:** a branch transfer, and its reversal, assert open for B, G **and** MAIN. If MAIN is the locked one, the message names MAIN: "The period YYYY-MM is locked for MAIN (head office side of this transfer)."
   - **R6 back-fill:** a branch transfer and its reversal get 4 lines (sender IB, MAIN pair, receiver IB).
   - **V4:** pairs are (MAIN, B) only.
   - **ACs:** AC 7 = the 6 lines above. AC 10 = 6 mirrored lines, balanced in each of the 3 branches. AC 34 = with only KDY+GAL selected nothing is eliminated, IB–MAIN shows in both branches, the subset note is shown, and Dr = Cr still holds.
   - **Corrected worked example.** E-5 / AC 25: 19 transactions get **33** lines (each of (7), (11) and the reversal of (11) gets 4), so there are **62** lines after the back-fill; the money balances are unchanged. E-6 closing at 2026-10-31:
     - MAIN: Dr Bank 620,000.00, IB–KDY 270,000.00, IB–GAL 110,000.00 / Cr Owner's capital 1,000,000.00. Total 1,000,000.00.
     - KDY: Dr Cash 254,300.00, Bank 40,000.00, Rent 30,000.00, Electricity 8,200.00 / Cr IB–MAIN 270,000.00, Retail 50,000.00, Service income 12,500.00. Total 332,500.00.
     - GAL: Dr Cash 137,200.00, Water 1,800.00 / Cr IB–MAIN 110,000.00, Retail 29,000.00. Total 139,000.00.
     - E-7: eliminations MAIN↔KDY 270,000.00 and MAIN↔GAL 110,000.00, with no KDY↔GAL. Consolidated Dr = Cr = 1,091,500.00, unchanged.
     - KDY 10-01 opening is unchanged (IB–MAIN 200,000.00 Cr). The KDY October IB–MAIN period columns are debits 40,000.00 and credits 110,000.00.
2. **MUST — Owner's capital exists only in MAIN (Q10).** R1's 31000 row is MAIN only. Capital into an `is_main_account` of a non-main branch B posts 4 lines (MAIN: Dr IB(B), Cr Owner's capital; B: Dr A, Cr IB(MAIN)) and asserts open for both branches. The back-fill treats existing non-main capital the same way. Delete "in any branch on its first capital posting" and the R6 "and in any branch that has capital transactions". Add an AC.
3. **MUST — Void and reverse fixes (BA-identified) confirmed, with detail.** `void` locks **every** account on the transaction's lines (id order), posts one contra per non-contra line (posting, reclass, backfill) on that line's own account and branch, dated that line's `entry_date` (always `txn_date`, see 7). `reverse` mirrors every line, including back-filled lines. In both, the funds check applies only to a **money** account being credited, and it is run on the net credit per money account in the set. Add an AC: void an expense that has been reclassified twice. That gives 6 contras, and every account nets to 0.00 for the transaction.
4. **MUST — `reclassify` rules.**
   - It is allowed only for type income/expense with status `posted`. This matches `TransactionController::update` today: a reversed or void entry cannot be edited.
   - It moves the amount from the **current** category to the new one: C→C2, then C2→C3 posts Dr C3 / Cr C2, never from the original C. It posts nothing when the category is unchanged.
   - Inside the posting lock it re-validates the new category (active, same type, global or the entry's branch) and re-checks the period with the locking read.
   - It updates `transactions.category_id` in the same DB transaction as the lines.
   - It writes `transaction.reclassified` `{old_category_id, new_category_id, amount, line_ids}` in place of a bare `transaction.updated` for the category.
5. **MUST — Back-fill idempotency and scope.**
   - Skip a transaction only if it already balances per branch.
   - If it has `origin = backfill` lines but does not balance, **stop** and name it. Never add a second back-fill set.
   - Post to inactive category accounts and to inactive branches' accounts. These are history, and the active checks do not apply.
   - Preflight also refuses to run if any income/expense has a null `category_id` or a category whose branch differs from the entry's branch, and lists them.
   - Each run (first run or re-run) writes its own `ledger.double_entry_backfilled` row with that run's counts. AC 28's "equals a single clean run" is about lines and balances, not about the number of audit rows.
6. **MUST — The lock exemption is confined to the back-fill.**
   - It lives only inside the private back-fill path (no `$ignoreLocks` flag on any public `LedgerService` method).
   - It may insert only `origin = backfill` lines, and never on a money account.
   - `locked_periods` lists `{branch, period, lines}`.
   - Add an AC: after the migration, a normal posting, reclass, void and reversal dated in a locked month are still refused.
7. **MUST — Trial balance void exclusion must reconcile.** Excluding voided transactions from the period columns is safe only because every line of a voided transaction (posting, reclass, back-fill and contras) is dated its `txn_date` (controls R1). Required:
   - Add to V2: "every line of a `void` transaction has `entry_date = txn_date`".
   - Add an AC: for every row and for the totals, Opening + Period Dr − Period Cr = Closing (signed), by branch and consolidated.
8. **SHOULD — Reconciliation AC between ledger and the Transfers report.** For each branch and period, the net movement on its inter-branch accounts (credits − debits for a non-main branch's IB–MAIN; debits − credits summed over MAIN's IB accounts) equals the Transfers report "Net" for that branch, excluding non-main capital (finding 2). E-dataset, October: KDY +70,000.00, GAL +10,000.00, MAIN −80,000.00 (as 80,000.00 Dr).
9. **SHOULD — Presentation of the inter-branch accounts.** On S1 and S2, show them in their own group "Inter-branch (eliminated on consolidation)" after Equity, not under Assets: a branch's Head office current account normally has a credit balance and reads like the head office's investment in the branch. The names are "Head office current account" in a branch and "Branch current account – KDY" in MAIN. The 15 + 3-digit branch id code is fine; refuse to create a branch with id > 999.
10. **SHOULD — Detail access for the head office side.** A user with access to MAIN can open `GET /transactions/{id}` for any transaction that has a line in MAIN (e.g. KDY→GAL, reached from the IB–KDY statement), with the same rule for any branch that has a line. Otherwise MAIN's own ledger statement links to a 403.
11. **SHOULD — Year-end close (Q5).** AG-092 must ship before the first financial year end after go-live (31 March 2027 if the business keeps the Sri Lankan April–March year). Otherwise the trial balance's income and expense lines run across years. Reserve 32000 Retained earnings now (no postings).
12. **SHOULD — Drawings and loans (Q9).** Until equity drawings and liabilities exist, tell users in the help text not to book owner drawings, loans received or loan repayments as income/expense. Add backlog items: "Owner's drawings" (equity, e.g. 33000), and "Manual journals + liabilities" (loans, payables, statutory dues).
13. **SHOULD — Deploy in maintenance mode.** F7: `php artisan down` before step 3, and `up` only after step 4 passes. The accountant signs the step-2 dry-run output, and it is kept with the deploy record.
14. **Fine as written (no change):**
    - Chart structure and codes: 11xxx money, 31000 capital, 4PPSS/5PPSS with the same code in every branch for consolidation, codes never reused.
    - Posting rules for income, expense, capital in MAIN, opening allocation, top-up, fund return, internal transfer, reclass and void.
    - L1 (pre-check plus re-read per branch), L2, D1–D3, and the decision not to add a balance trigger.
    - The R8 list is complete: the only reads of `ledger_entries` / `current_balance` outside `LedgerService` are `ReportService` (branch comparison, cash book list/opening/rows), `DashboardController` 62/66/68, `BranchController` 19 and `AccountController` 18/29/79. The statement filters by account, so it is already safe.
    - The I&E report already uses the original's `category_id` for reversals, so V5 and AC 38 hold.
    - The new period-lock refusal for unbalanced entries.

### Answers to the open questions
- **Q1 — Agreed: one net current account per counterparty.** This is the standard head-office / branch current account pair. One reciprocal balance per pair is what you reconcile and eliminate; separate Due-to/Due-from accounts would only need netting again. With Q2, a branch has exactly one such account (with MAIN).
- **Q2 — Changed: route through Head Office** (finding 1). This is the textbook branch-accounting treatment, and it is what an owner-run multi-branch business expects. Each branch's books show one figure, "what head office has put into this branch". Head office sees every branch's net funding in one place, and reconciliation is a star (n−1 pairs) rather than a mesh (n(n−1)/2 pairs). KDY sending cash to GAL is in substance head office moving its own money: KDY's funding falls and GAL's rises. The MAIN period-lock dependency is accepted, because month-end locks are set by head office, normally for all branches together.
- **Q3 — Agreed: the head office current account, not branch equity and never income.** The branches and head office are one legal entity, so a branch has no capital of its own. Opening allocations, top-ups and returns all move the same current account. Equity (Owner's capital) sits only in MAIN (finding 2).
- **Q4 — Agreed.** There is one account per category node, the parent posting account matches the I&E "(no sub-category)" line, and the 5-digit codes are the same across branches, with 99/99 limits. Codes are not editable. Branch-only sub-categories with the same name in two branches get different codes and are not combined on consolidation; that is acceptable.
- **Q5 — Agreed for now.** Cumulative income/expense is acceptable until AG-092, with the deadline in finding 11. The S2 basis line already discloses it.
- **Q6 — Agreed: a recorded system exception, no unlock/re-lock.** Unlocking every month would create unlock history that looks like real reopening and would open a window for real postings. The back-fill changes no cash balance and no reported figure. One summary audit row plus `origin = backfill` on every line is enough, given findings 5, 6 and 13.
- **Q7 — Agreed: the original date.** A category change corrects the analysis of the same event; it is not a new event. Because `update` is refused in a locked month, the rule "locked months cannot change" holds. Corrections to a locked month are made by reversal in an open month and re-entry.
- **Q8 — Agreed**, on condition of finding 7. Excluding voided entries matches the reports basis. Reversals shown gross is the correct trial balance presentation.
- **Q9 — Confirmed:** no liabilities or manual journals are needed for go-live. Journals and liabilities are a separate backlog item (finding 12).
- **Q10 — Capital stays in MAIN's books only** (finding 2). Capital can still be received physically into a non-main account, but it is booked as owner's capital in MAIN and as head office funding in the branch. A single legal entity has one capital account, and consolidation then shows exactly one Owner's capital line.

Spec body revised to match this review.

## Accountant sign-off (accountant, 2026-11-18, after development)

Build checked: `git diff eff4c65...HEAD` on `feature/double-entry` (10 commits up to 8e7571f). `php artisan test`: 251 passed (SQLite, run here). I also ran a throwaway probe test (not in the repo). Through the API it posted and read back every transaction type, a reclass on income and on expense, a void after two reclasses, a reversal of a reclassified expense, a reversal of a branch transfer, and a MAIN-only lock. It then ran `ledger:verify` and the trial balance (by branch and consolidated).

### What I verified
- **Posting lines (entry detail API), by code.** All as R3:
  - capital into MAIN: Dr 11001 / Cr 31000;
  - capital into KDY's main account: MAIN Dr 15002 / Cr 31000, then KDY Dr 11003 / Cr 15001;
  - opening allocation and top-up: MAIN Cr 11001 / Dr 150BB, then branch Dr 11001 / Cr 15001;
  - fund return GAL→MAIN: GAL Cr Cash / Dr 15001, then MAIN Dr 11001 / Cr 15003;
  - branch transfer KDY→GAL: 6 lines, KDY Dr 15001 / Cr Cash, MAIN Dr 15003 / Cr 15002, GAL Dr Cash / Cr 15001; debits 3 × amount;
  - internal transfer: 2 money lines only;
  - income reclass: Dr old / Cr new. Expense reclass: Dr new / Cr old, taken from the current category.

  Every set balances per branch, and the "Balanced" flag is correct.
- **Chart and codes.** These are correct:
  - money 11001+ per branch;
  - Head office current account 15 + MAIN id in each non-main branch; Branch current account 15 + branch id in MAIN only;
  - Owner's capital 31000 in MAIN only; 32000 reserved and not created;
  - 4PPSS / 5PPSS, with the parent's own account at PP00;
  - inter-branch accounts shown in their own group.

  No new permission key, and the role templates are unchanged.
- **Each branch balances on its own; eliminations correct.** E-6 / E-7 match: MAIN 1,000,000.00, KDY 332,500.00, GAL 139,000.00, consolidated Dr = Cr 1,091,500.00, MAIN↔KDY 270,000.00 and MAIN↔GAL 110,000.00 net to 0.00, and there is no KDY↔GAL row. On the probe data, consolidated Dr 1,007,500.00 equals total cash/bank. The subset (pair not selected) is not eliminated and carries the note.
- **Identity on every row.** Opening + period Dr − period Cr = closing on every row and total, by branch and consolidated (AC 44 and the probe, including void and reversal periods).
- **Void, reverse, reclassify.**
  - A void after two reclasses posts 6 contras on the lines' own accounts and dates, and every account nets to 0.00.
  - A reversal mirrors every line, including reclass lines; a reclassified-then-reversed expense nets on the current category only.
  - The funds check runs on the net credit per money account only.
  - The same category, or a payee-only edit, posts nothing.
  - A reversal of a branch transfer has 6 lines. With only MAIN locked, a branch transfer is refused with the "head office side" message, a fund return is refused, and a KDY income is allowed.
- **Lock exemption.** It lives only in the private `postBackfill` (`LedgerService.php:332`). That path refuses money accounts (`:336`, `:349`), writes `origin = backfill` only and runs no `assertOpen`. The public `backfill(Transaction)` takes nothing else, and no public method has a bypass parameter (AC 40 and the probe). An already-balanced entry is skipped.
- **`ledger:verify` V1–V6.** All pass on the probe data and the migrated E-dataset. The offender tests for V1/V3 (AC 50), V2-D4 (AC 51) and V4/V5/V6 exist and pass. The period-lock refusal for unbalanced entries is in place.
- **Back-fill audit row.** It is written once per completed run (user null, branch null) with transactions, lines, by_type, by_branch, locked_periods {branch, period, lines}, money_balances_unchanged and skipped_legacy_voids (ACs 30, 33, 34).
- **README deploy steps.** They cover: verify first, dry run signed by the accountant, `down`, `migrate`, verify, `up`, timings (19,080 entries in 2 min 25 s, about 8 ms each, plus the dry-run estimate) and recovery for both migrations.

### Deviation from R6: commit every 100 entries. ACCEPTED.
Each entry is still all-or-nothing: it is a savepoint, and L1 re-reads the whole transaction per branch. A failure rolls back only the open batch, and a re-run completes it (AC 34: same lines and balances as a clean run). The batching does not change any figure. The maintenance-mode / `--force` guard is a good control. With `--force`, entries commit one at a time and the safety check becomes "cached = ledger" for money accounts, which is acceptable. Condition: MUST A below.

### Findings 1–13 of the Accounting review
| # | Status | Evidence |
|---|---|---|
| 1 Branch transfers via Head Office | DONE | `PostingRules.php:63-82`; `LedgerService.php:493` (MAIN message); PostingTest ac8, ac11, ac12; probe |
| 2 Owner's capital only in MAIN | DONE | `PostingRules.php:42-54`; `ChartOfAccounts::ownerCapital`; PostingTest ac4/ac5; BackfillTest ac32 |
| 3 Void / reverse fixes | DONE | `LedgerService.php:211`, `:256`, `:528`; ReclassifyTest ac14; probe |
| 4 Reclassify rules | DONE | `LedgerService.php:169-203`; `TransactionController::update`; ReclassifyTest ac13–15 |
| 5 Back-fill idempotency and scope | DONE (round 2) | Skip, stop-on-unbalanced, inactive accounts and preflight (ac34–37). A stopped run now writes `ledger.double_entry_backfill_stopped` (A, fixed in 97cd1ae). |
| 6 Lock exemption confined | DONE | `LedgerService.php:307-364`; BackfillTest ac33, ac40 |
| 7 Void exclusion reconciles | DONE | `DoubleEntryPreflight::lineOffenders` D4 (V2); TrialBalanceTest ac44 |
| 8 Ledger ↔ Transfers report | DONE | BackfillTest ac29 (KDY +70,000.00, GAL +10,000.00, MAIN −80,000.00) |
| 9 Inter-branch presentation | DONE | Groups and names as specified; branch id ≤ 999 refused. Ordering defect in consolidated rows: SHOULD B |
| 10 Head-office detail access | DONE | `TransactionController::show`; VerifyAndControlsTest ac55; probe (MAIN user, 6 lines, read-only) |
| 11 Year-end close | DONE for this release | 32000 reserved. AG-092 must ship before 31 March 2027. |
| 12 Drawings / loans | FOLLOW-UP | Out of scope (section 1). The help-text item and the two backlog rows are still to be added. |
| 13 Maintenance-mode deploy | DONE | README "Deploying the double-entry release"; back-fill command refuses while the app is up |

### Must fix before sign-off
- **A (MUST, audit trail; finding 5).** `api/app/Services/DoubleEntryBackfill.php:169-192`: if a write run stops, its committed batches stay in the ledger but no audit row is written. BackfillTest.php:341 asserts exactly that ("the stopped run wrote no row"). The `ledger.double_entry_backfilled` rows then add up to fewer lines than there are `origin = backfill` lines. Ledger lines are recorded with no audit row, which breaks the rule that every change is audited.

  Fix: on a non-dry run that throws, write a row such as `ledger.double_entry_backfill_stopped` (user null, branch null) before re-throwing. It must hold the error and the counts **committed** by that run, for example the backfill lines with `created_at` ≥ run start. Then change BackfillTest ac34 to assert that the rows' line counts sum to 33.

### Should fix (do not block)
- **B.** `api/app/Services/ReportService.php:636/644/654`: consolidated rows are sorted by comparing arrays. PHP compares array length first, so the 4-element money-account keys always sort after the 2-element keys. The API rows and the consolidated **PDF** (signed at month end, F8) list Equity, Inter-branch, Income, Expenses and then Assets, under a second heading. The screen is fine because it groups by `group`. Figures and totals are correct. Fix: compare `group` first, or pad the sort keys to the same length, and assert the row order in TrialBalanceTest ac42.
- **C.** README.md:111-113, recovery after a back-fill failure:
  - "Fix the reported entry" gives no method. Say: do not edit data by hand; restore the backup, or escalate to the developer and the accountant.
  - After `ledger:backfill-double-entry` completes, run `php artisan migrate --force` again. Otherwise `2026_11_22_000001` stays pending and runs again at the next deploy. The re-run is harmless (0 lines and its own audit row), but it is unexpected.

### Round 1 verdict (superseded): NOT SIGNED OFF, pending MUST A.

### Round 2 re-check (accountant, 2026-11-18; commits 986f4fe, 97cd1ae)
`php artisan test`: 253 passed (SQLite, run here).
- **A: DONE.** `DoubleEntryBackfill::run` now has a catch block. On a write run that throws, it writes `ledger.double_entry_backfill_stopped` (user and branch null) with the error and the counts from the last **committed** batch (`$committed`, taken after each commit), then re-throws. BackfillTest ac34 asserts the rows: stopped (`partial`), completing run (`33 − partial`) and re-run (0). Together they sum to 33, so the audit rows now reconcile with the `origin = backfill` lines. ac35 asserts a stopped row for the unbalanced entry. On SQLite, the migration runs inside one transaction, so the lines and the row roll back together, which is consistent.
- **B: DONE.** `ReportService::consolidatedTrialBalance` now compares the group first, then the keys element by element. The new test `test_consolidated_rows_are_in_group_order` checks that the rows start with Assets, MAIN comes first, each group appears once and in order, and income and expense rows follow their codes. The PDF headings follow the row order, so they are correct too.
- **C: DONE.** README.md, "If the back-fill step fails":
  - keep the app down, and do not edit rows by hand;
  - for an outside cause (connection, lock timeout, disk), re-run `migrate --force`;
  - for an error that names an entry, restore the backup or escalate;
  - after the supervised command, run `migrate --force` so that `2026_11_22_000001` is recorded.

  ac34 asserts that this last `migrate` records the migration and adds nothing.
- **Deadlock fix and posting retry (review R3b): ACCEPTED.**
  - Every posting method (`capital`, `transfer`, `incomeOrExpense`, `reclassify`, `reverse`, `void`) runs in `DB::transaction(..., ATTEMPTS = 3)`, as do the account, branch, category and transaction-update controllers.
  - Laravel retries only on a concurrency error (deadlock or lock wait), and only at the top level. A failed attempt is rolled back as a whole, and the audit rows (same connection), lines, `transactions` row and cached balances all go with it. Each attempt re-reads its state under lock: the transaction row is reloaded FOR UPDATE, the accounts are re-locked, the period lock and funds are checked again, and `TransactionController::update` and `CategoryController::update` call `refresh()` first. A retry therefore re-validates and cannot double-post.
  - No queued job or notification is sent from inside a posting. `AccessAlerts` is used only for user and role changes.
  - The back-fill is not retried per entry (`backfill()` has no attempts), and a stopped batch is now audited (A).
- **Retry probe.** A throwaway test, with the test-wrapper transaction committed so that LedgerService is at the top level, injected one deadlock late in each of four attempts:
  - branch transfer: after its final audit insert;
  - reclass with a payee edit: after its ledger line insert;
  - void: after the status update;
  - reversal of the branch transfer: after its audit insert.

  Each request succeeded on retry with exactly one set of lines (6 / 2 / 4 contras / 6), one `transactions` row, and one of each audit row (`transaction.branch_transfer`, `transaction.reclassified`, `transaction.updated` with old payee "A", `transaction.voided`, `transaction.reversed`, `transaction.reversal`). `ledger:verify` passed.
- **Chart code lock.** `chart_code_locks` is a single row that no foreign key references. It is created by the schema migration and recreated if it is missing. Codes are still max + 1 under that lock, so they stay unique and are never reused, and the chart is unchanged.
- **Note (no action).** On MySQL, a retried or rolled-back posting uses up an AUTO_INCREMENT id, and `ref_no` contains the id. A retry can therefore leave a gap in the reference sequence. This already happens for any posting that fails after its insert. It is not a control weakness, because no posted entry is missing (V1/V6 and the audit log prove completeness), but auditors should be told that reference gaps are expected.

### Verdict: **SIGNED OFF** (round 2, 2026-11-18)
Findings 1–11 and 13 are DONE; finding 12 is a follow-up outside this release. Round-1 items A, B and C are fixed and tested. The batching deviation and the deadlock retry are accepted. The build is cleared for the pull request.

Two conditions apply to the deploy, not to the code:
- the dry-run output (F9 step 2) is signed by the accountant before `migrate`;
- AG-092 (year-end close) ships before 31 March 2027.
