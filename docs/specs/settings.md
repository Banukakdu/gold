# Spec: System settings, fiscal year and year-end close

## 1. Goal and scope
**AG-092** (E10, Must, R1): "As a Super Admin, I want system settings (company name, logo, currency, date format, approval thresholds, fiscal year start) so that the system fits our business."
Backlog acceptance: settings are editable in the UI; the logo appears on PDF reports; currency defaults to LKR with 2 decimals (configurable).

**Also in scope: fiscal year and year-end close.** This is the condition from the double-entry sign-off: AG-092 ships before **31 March 2027** (double-entry section 1, finding 11, Q5). Today, income and expense accounts accumulate from the first entry, and the trial balance basis line says so.

Today (code on `feature/settings`):
- The `settings` table exists (`2026_11_18_100000_create_accounting_tables.php`: `key` string primary, `value` text). `App\Models\Setting` has `get`/`put`. No screen and no API write to it. Rows are created only by `DatabaseSeeder` (dev): `company_name`, `currency` = LKR, `expense_negative_balance` = block. On a production database without the seeder the rows may not exist, and `Setting::get` falls back to its defaults.
- Readers: `company_name` in `ReportService::meta`, `ReportExporter::header` and the audit Excel header; `company_logo` in `ReportExporter::header`, which accepts only a PNG/JPEG `data:` URI (nothing writes it); `expense_negative_balance` in `LedgerService`.
- "Amounts in LKR" is hard-coded in `ReportExporter::header`, `reports/pdf/layout.blade.php:43` and `web/.../report-common.ts:204`. `accounts.currency` (default LKR) exists but is not shown or used anywhere.
- The web shows dates as `d MMM y` (Angular `date` pipe) on most screens and `YYYY-MM-DD` on others (`core/dates.ts`, reports). The app header shows the fixed brand "Account gold" (`layout/shell.component.ts:12`).
- `ReportExporter::periodLabel` labels a Jan–Dec range "Year YYYY (calendar)" "until the fiscal-year setting exists".
- Chart code 32000 is reserved (`ChartOfAccounts::RESERVED_RETAINED_EARNINGS`); no account exists.
- Locks are cumulative ("closed through", controls finding 1).

**Out of scope:**
- **Approval thresholds are not stored in this release (decision, Q3).** The two stories that would use them are R2: AG-051 (transfer approval, one global amount, "0 = always, blank = never") and AG-064 (expense approval, **per branch**). They need different shapes, and nothing would read a stored value. An unenforced amount on the Settings screen would suggest a control that does not exist, and QA could not test it. The Settings screen shows an information line instead (S1). AG-051 and AG-064 each add their own setting.
- Multi-currency, exchange rates, currency symbols, and any number of decimals other than 2 (R2).
- A per-user date format, time zone setting (Asia/Colombo stays fixed), locale and number grouping.
- A logo on the login page (Q6), on Excel exports and on the browser tab.
- A UI for `expense_negative_balance` (unchanged, Q7).
- Balance sheet and profit and loss statements, owner's drawings, manual journals (double-entry section 1).
- A fiscal year that starts on a day other than the 1st, and 52/53-week years.

**Size.** AG-092 is estimated at 2 points. The year-end close is much larger. Proposal: keep AG-092 for settings and fiscal year (S1, R1–R6), and add a backlog row "Year-end close" (E10, Must, R1, due before 31 March 2027) for R7–R12 (Q1). This spec covers both, so they can ship as one feature or as two.

## 2. Personas and permissions
No new permission key. Settings and the year-end close use the existing `super` middleware, as period locks do (controls Q1). A catalogue key would be given to every Branch Admin automatically (`PermissionSeeder::run` adds new keys to the `*` template).

| Need | Guard | Who |
|---|---|---|
| Read the general settings: company name, logo, currency, date format, fiscal year start and the current fiscal year | `auth:sanctum` only (no branch, no permission) | every signed-in user. Each screen needs them for the header, date and amount display and the report presets |
| Read the Settings screen (lock reasons, logo details, last change) | `super` | Super Admin |
| Edit settings, upload or remove the logo | `super` | Super Admin |
| Year-end screen: preview, close, reopen | `super` | Super Admin |
| See whether a fiscal year is closed (trial balance banner and basis line) | `perm:report.view` (trial balance) | Branch Admin, Accountant, Viewer |
| See year-end close entries in Transactions and on the entry detail | `perm:transaction.view` (detail access rule from double-entry R10) | all four templates |
| Audit rows `settings.*` and `year.*` | `perm:audit.view` | Super Admin in All mode (the rows have no branch). Each branch's close transaction row (`transaction.year_end_close`) has that branch's id, so that branch's auditors see it |

## 3. User flows
- **F1 First set-up (Super Admin).**
  1. Menu Settings.
  2. Enter the company name "Lanka Traders (Pvt) Ltd". The currency shows LKR, 2 decimals. Set the date format to "05/10/2026". The fiscal year starts in April.
  3. Save. The header shows the new name at once. The Transactions list shows dates as 05/10/2026.
- **F2 Logo (Super Admin).**
  1. Settings, Logo, then "Upload logo" and pick `logo.png` (180 KB, 600 × 200).
  2. A preview shows. The app header shows the logo.
  3. Export the I&E report as a PDF. The logo is at the top left of every page.
- **F3 Currency after go-live (Super Admin).** The Currency field is read-only, with the note "The currency cannot be changed after entries have been posted."
- **F4 Year-end, April 2027 (Super Admin, All).**
  1. Period locks: lock March 2027 for all branches. With cumulative locks, this closes the whole year.
  2. Menu Year-end, row FY 2026-27, status "Ready to close".
  3. "Preview". For each branch: total income, total expense, result, the closing lines, and the MAIN lines.
  4. Print the preview. The accountant checks it against the pre-closing trial balance for 2026-04-01..2027-03-31.
  5. "Close FY 2026-27". Type `2026-27` to confirm. The row becomes "Closed on <date> by <name>".
  6. The post-closing trial balance at 2027-03-31 shows every income and expense account at 0.00 and Retained earnings with the year's result.
- **F5 Late correction after the close (Super Admin).**
  1. Year-end, FY 2026-27, "Reopen year". Give a reason of 10–500 characters.
  2. The closing entries are cancelled by contra lines. The months stay locked.
  3. Unlock March 2027 (Period locks, normal flow). Correct the entry. Lock March again.
  4. Close again.
- **F6 Branch Accountant, May 2027 (KDY).** Reports, Trial balance, preset "This fiscal year". The income and expense rows start at 0.00 on 2027-04-01.
  - If FY 2026-27 is not yet closed, the row "Result of earlier years not yet closed" shows last year's KDY result, and an amber banner says "FY 2026-27 is not closed yet."
  - After the close, that row is gone and the Head office current account includes KDY's result.

## 4. Screens
**S1 Settings** `/settings`. Menu item "Settings" for Super Admin only, after Period locks. Route `superGuard`. Works in any switcher mode.

| Section | Field | Type | Rules |
|---|---|---|---|
| Company | Company name | text | required, trimmed, 2–120 characters |
| Company | Logo | file + preview | R3. Buttons "Upload logo" / "Replace logo" / "Remove logo". Shows file name, size, pixels, uploaded by/at |
| Money | Currency | select of ISO 4217 codes, LKR first | default LKR. Read-only after the first ledger line, with the note from F3 |
| Money | Decimal places | read-only "2" | info: "Amounts are kept to 2 decimal places." |
| Display | Date format | select | `D MMM YYYY` "5 Oct 2026" (default, today's look); `DD/MM/YYYY` "05/10/2026"; `DD-MM-YYYY` "05-10-2026"; `YYYY-MM-DD` "2026-10-05". Info: "Changes how dates are shown, not how they are entered or stored." |
| Fiscal year | Fiscal year starts in | select January…December | default April. The current year shows below: "Current fiscal year: FY 2026-27 (1 Apr 2026 – 31 Mar 2027)". Read-only once any year has been closed, with the note "Cannot be changed after a year-end close." |
| Approvals | — | info line | "Approval thresholds are set here when transfer approval (AG-051) and expense approval (AG-064) are released." |

- **Actions:** Save (sends only changed fields; disabled until something changes) and Cancel. Each field shows "Last changed by <name> on <date>" from the audit.
- **Messages:** success "Settings saved."; errors are the API `message`/field errors in `.alert`.
- **Logo upload:** client checks before sending (the server is the authority): extension jpg/jpeg/png, at most 1 MB. "Remove logo" asks "Remove the logo? Reports and the header will show the company name only."

**S2 App header** (`shell.component.ts`): the logo (if any, max height 32 px, `alt` = company name) and the company name, replacing the fixed brand. The product name "Account gold" moves to the footer / login page. The logo is fetched as a blob through the API (it needs the bearer token), and the object URL is cached for the session.

**S3 Date display everywhere.** A new `appDate` pipe (and `colomboDateTime` for timestamps) formats with the date format setting.
- **Applies to:** every screen, the PDF reports (rows, the header "Period" line, "Printed … at") and the date number format of Excel date cells.
- **Does not apply to:** API values (always `Y-m-d` / ISO 8601), `<input type="date">` (browser locale), file names, ref numbers, audit `old_values`/`new_values` JSON.
- **Times** stay `HH:mm` (24 h), Asia/Colombo.
- **Code change:** replace the literal `date: 'd MMM y'` uses and the `YYYY-MM-DD` displays.

**S4 Currency label.** "Amounts in <code>" on screen, PDF and Excel headers reads the currency setting (3 places listed in section 1). `MoneyPipe` is unchanged (2 decimals, no symbol).

**S5 Year-end** `/year-end`. Menu "Year-end" for Super Admin only, after Period locks. Route `superGuard`.
- **Table:** one row per fiscal year, from the year of the first ledger line to the current year, newest first.
- **Columns:** Fiscal year (FY 2026-27) | Dates | Status | Result (consolidated profit or loss) | Closed by / at | Actions.
- **Status:**
  - **Open:** the year has not ended, or not all branches are locked through its last month.
  - **Ready to close:** all checks in R8 pass.
  - **Closed.**
  - **Reopened:** shows the reason; the year can be closed again.
- **Check list** on an Open row (one line per failed check), e.g. "Lock March 2027 for: KDY, GAL (or lock all branches).", "Close FY 2025-26 first.", "2 entries up to March 2027 are not balanced. Run ledger:verify."
- **"Preview"** (Ready or Closed): one card per branch with Income total, Expense total, Result, and the closing lines (Code | Account | Debit | Credit), including the MAIN lines for that branch. A summary card shows the result per branch, the total, and the Retained earnings movement. Print button.
- **"Close FY 2026-27"** (Ready only): a dialog listing the result per branch and the total. It needs the label typed exactly ("2026-27"). Text: "This posts closing entries dated 31 Mar 2027 in every branch and keeps March 2027 locked for all branches. It can only be undone by reopening the year."
- **"Reopen year"** (latest closed year only): Reason (textarea, required, 10–500). Text: "This cancels the closing entries. The months stay locked; unlock them in Period locks if you need to change an entry."
- **Empty state:** "No entries yet." **Errors:** the API `message` in `.alert`.

**S6 Trial balance changes** (double-entry S2):
- **Validation:** From and To must be in one fiscal year (R5).
- **Presets** (all reports' filter bar): "This fiscal year", "Last fiscal year" (and the existing month presets).
- **Toggle "Include closing entries"** (post-closing view): shown only when To is the last day of a closed fiscal year. Default off (pre-closing).
- **Banners:**
  - amber "FY 2026-27 is not closed yet." when an earlier year with entries is not closed;
  - grey "FY 2026-27 closed on <date> by <name>." when the shown year is closed.
- **Basis line:** "Income and expense from the start of FY <label> (<start>). Results of earlier years are in Retained earnings (MAIN) and Head office current accounts once closed, otherwise in 'Result of earlier years not yet closed'. Closing entries excluded / included."
- **Period label** (`ReportExporter::periodLabel`): an exact fiscal year range reads "FY 2026-27, 2026-04-01 to 2027-03-31". The calendar label stays for Jan–Dec only when the fiscal year does not start in January.

**S7 Other screens.**
- **Transactions list and detail:** type label "Year-end close". No Edit, Void or Reverse actions.
- **Detail** "Ledger lines" note: "Year-end close FY 2026-27", or "Cancelled by reopening FY 2026-27" for the contras.
- **Chart of accounts:** MAIN shows 32000 "Retained earnings" under Equity, type "Retained earnings", created from "System".
- **Chart info line** adds: "At year end, each branch's result is moved to its Head office current account and to Retained earnings in MAIN."

## 5. API
| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `GET /v1/settings/public` | `auth:sanctum` (outside `branch`, like `me`) | — | `{company_name, logo:{sha256, updated_at}\|null, currency_code, currency_decimals:2, date_format, fiscal_year_start_month, current_fiscal_year:{start_year, label, start, end}}` |
| `GET /v1/settings/logo` | `auth:sanctum` | — | the image bytes. Headers: `Content-Type` = stored mime, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=300`, `ETag` = sha256. 404 "No logo." 410 "The logo file is missing from storage. Upload it again." 409 when the sha256 does not match (R3) |
| `GET /v1/settings` | `super` | — | the public fields + `logo:{original_name, mime, size_bytes, width, height, sha256, uploaded_by:{id,name}, uploaded_at}\|null`, `currency_locked:bool`, `fiscal_year_start_locked:bool`, `last_changed:{key:{by:{id,name}, at}}` |
| `PUT /v1/settings` | `super` | any of `company_name`, `currency_code`, `date_format`, `fiscal_year_start_month`, `currency_decimals` | 200 with the `GET /settings` body. 422 for: an unknown or not editable key (e.g. `expense_negative_balance`, `company_logo`) "Unknown setting: <key>."; field rules in S1; currency with entries (R2); decimals ≠ 2 (R2); fiscal year start after a close (R4) |
| `POST /v1/settings/logo` | `super`, `throttle:attachments-upload` | multipart `file` | 201 with `logo` metadata. 422 messages in R3 |
| `POST /v1/settings/logo/remove` | `super` | — | 200. 422 "There is no logo to remove." No DELETE route (nothing is hard-deleted) |
| `GET /v1/fiscal-years` | `super` | — | `{data:[{start_year, label, start, end, status:'open'\|'ready'\|'closed'\|'reopened', checks:[{code, message}], close:{id, closed_by, closed_at, total_net, reopened_by, reopened_at, reopen_reason}\|null}]}` |
| `GET /v1/fiscal-years/{startYear}/preview` | `super` | — | `{fiscal_year, branches:[{branch:{id,code}, income, expense, net, lines:[{branch_code, code, account, debit, credit}]}], total_net, retained_earnings_movement, checks}`. 404 for a year outside the list |
| `POST /v1/fiscal-years/{startYear}/close` | `super` | `confirm` (must equal the label) | 201 `{close, transactions:[{id, ref_no, branch_code, lines}]}`. 422 messages in R8 and R10 |
| `POST /v1/fiscal-years/{startYear}/reopen` | `super` | `reason` (10–500) | 200. 422 in R11 |
| `GET /v1/reports/trial-balance` (+ export) | unchanged | + `closing_entries` (0/1, default 0) | `meta` gains `fiscal_year:{label, start, end, closed, closed_at, closed_by}` and `earlier_unclosed:bool`. Rows may include the virtual row (R6). 422 in R5 |
| `PUT /v1/transactions/{id}`, `POST …/void`, `POST …/reverse` | unchanged | — | for type `year_end_close`: 422 "Year-end closing entries change only by reopening the year." |

**Schema** (migrations dated after `2026_11_22_000001_double_entry_backfill.php`):
1. `…_settings_and_year_end.php`
   - **Settings rows**, inserted where missing (no overwrite):
     - `company_name` = `config('app.name')`;
     - `currency` = `LKR` (the existing key name is kept);
     - `date_format` = `D MMM YYYY`;
     - `fiscal_year_start_month` = `4`.
     - `currency_decimals` is a constant (2), not a row.
   - **Existing logo value:** if `company_logo` holds a `data:` URI, the migration validates it with R3 and stores it as a file. If it is invalid, the value is cleared and the migration writes `settings.logo_removed` with `{reason: "invalid at migration"}`.
   - **`year_end_closes`:**
     - `id`, `fiscal_year` (unsigned smallint, start year), `period_start` / `period_end` (date), `status` (`closed`|`reopened`);
     - `closed_by` (FK users), `closed_at`;
     - `reopened_by` (FK users null), `reopened_at` (null), `reopen_reason` (text null);
     - `summary` (json: per branch income, expense, net, transaction id, line count), timestamps;
     - `active_key`, unique: a generated column on MySQL (`IF(status='closed', fiscal_year, NULL)`) or a partial unique index on SQLite, the same pattern as `period_locks`.
   - **`transactions.type`** gains `year_end_close` (`Transaction::YEAR_END_CLOSE`). **`ledger_entries.origin`** gains `close`: ADD-only, with no CHECK through the schema builder (double-entry schema note).
   - **`accounts.type`** gains `retained_earnings`. The migration creates MAIN 32000 "Retained earnings" (class equity, created from System) with a zero balance and writes `account.created`. **MAIN only.**
2. **Disk** `branding` (`config/filesystems.php`), configured like `attachments`: local, root `storage_path('app/branding')`, private, `serve` false, no `url`, `throw` true. It must be in the backup set (AG-093).

## 6. Business rules

### R1 Settings store
- **Typed accessor:** `App\Support\Settings` reads every setting with its default: `companyName()`, `logo()`, `currencyCode()`, `dateFormat()`, `fiscalYearStartMonth()`, `fiscalYear(date)`. It memoises them per request. All readers in section 1 use it; `Setting::get('company_name', …)` calls are replaced.
- **Writes** go through `SettingsController` only, in one DB transaction:
  - Only changed keys are written.
  - A save with no change writes nothing and no audit row.
  - The `settings` row's `updated_at` changes.
- **Not branch data.** `settings` has no `branch_id` and no `BranchScoped`; every branch sees the same values.

### R2 Currency
- **What can change:**
  - `currency_code`: 3 upper-case letters from the ISO 4217 list shipped in the code. It can change **only while `ledger_entries` is empty** (checked in the transaction). Otherwise 422 "The currency cannot be changed after entries have been posted."
  - When it does change, every `accounts.currency` row is set to the new code in the same transaction (the column is otherwise unused). New accounts take the setting.
- **What cannot change:**
  - Decimals are fixed at 2. `Money` is integer cents, and the columns are `decimal(15,2)`. Any other value → 422 "Only 2 decimal places are supported."
  - The "configurable" in the backlog is the code before go-live, not the decimals (Q2).
- **No conversion:** changing the code never converts amounts.

### R3 Logo (reuses the attachments rules: attachments spec R2/R6 and accountant finding 2)
- **Size:** 1 byte to 1,048,576 bytes (1 MB). Empty → 422 "The file is empty."; larger → 422 "The logo may be at most 1 MB."
- **Type by magic bytes and `finfo`:** JPEG `FF D8 FF` or PNG `89 50 4E 47 0D 0A 1A 0A`. Anything else (SVG, GIF, WEBP, HEIC, PDF, text renamed `.png`) → 422 "The logo must be a PNG or JPG image."
- **Extension:** must match the detected type → 422 "logo.jpg is a PNG image, not a JPG. Rename it or choose the right file."
- **Pixels:** `getimagesize` is read before any other use. Width and height must be 16–2000 px, or 422 "The logo must be between 16 and 2000 pixels wide and high."
- **Storage:**
  - Stored as received on the `branding` disk at `logo/{uuid}.{ext}`, set read-only (0440).
  - The `company_logo` setting holds JSON `{path, original_name, mime, size_bytes, width, height, sha256, uploaded_by, uploaded_at}`. No URL, no `data:` URI in the database.
- **Nothing is hard-deleted:**
  - Replacing the logo keeps the old file.
  - "Remove" clears the setting, and the file stays on disk.
  - No code calls `Storage::disk('branding')->delete()`, except to roll back a failed upload.
- **Use:**
  - `ReportExporter::header` reads the file and checks its sha256, then builds the PNG/JPEG data URI for dompdf (the existing regex check stays).
  - On a missing file or a mismatch, the PDF is produced **without** the logo, and the server logs an error with the path.
  - `GET /settings/logo` returns 410 / 409 in those cases. S1 then shows "The logo file is missing or was changed outside the app. Upload it again."
- **Where the logo is printed:** the top left of every PDF page (all five reports and the year-end preview print), max 48 px high, aspect kept.

### R4 Date format and fiscal year start
- **Date format:** one of the four values in S1, otherwise 422. Display only (S3).
- **Fiscal year start month:** an integer from 1 to 12. The year starts on day 1 of that month, Asia/Colombo dates.
- **Fiscal year of a date d:** start year = `year(d)` if `month(d) ≥ M`, otherwise `year(d) − 1`.
- **Label:**
  - "FY 2026-27" when M > 1;
  - "FY 2026" when M = 1.
- **Fiscal year start changes:**
  - Changeable until the first close. After **any** `year_end_closes` row exists (closed or reopened), 422 "The fiscal year start cannot be changed after a year-end close."
  - Before that, a change only changes how reports group years. It is audited, and the S1 confirm says "Trial balances will use the new year boundaries."

### R5 Fiscal-year boundaries in reports
- **Trial balance:**
  - From and To must be in the same fiscal year. Otherwise 422 "From and To must be in the same fiscal year (FY 2026-27: 2026-04-01 to 2027-03-31)." This replaces the "≤ 366 days" rule for this report only.
  - Its income and expense rows reset at the fiscal year start (R6).
- **Other reports:**
  - I&E, Branch comparison, Transfers and Cash book keep their current ranges (≤ 366 days, any dates). They read `transactions` or money accounts, so the close changes none of their figures (year-end close transactions are not counted: `countedTransactions` selects by type).
  - They gain the fiscal-year presets and label.
- **Unchanged:**
  - The dashboard.
  - The account statement, which stays a true ledger: the statement of an income or expense account shows the closing lines on the last day of the year.

### R6 Trial balance rules
For a request From..To in fiscal year [S, E]:

| Account | Opening | Period | Closing |
|---|---|---|---|
| Income, expense | lines dated S..From−1 | lines From..To, as today (voided excluded) | opening + period |
| Every other account | lines dated < From (all origins, so earlier years' closes are included) | lines From..To | opening + period |
| **Virtual row "Result of earlier years not yet closed"** (group Equity, no code; per branch; shown only when ≠ 0.00) | − (all income and expense lines dated < S) | none | = opening |

- **Pre-closing (default):** lines with `origin = close` dated in [S, E] are left out of every column.
- **Post-closing** (`closing_entries=1`, allowed only when To = E and the year is closed; otherwise 422 "Closing entries can be included only for the last day of a closed fiscal year."): they are included in the period columns.
- **Totals balance:**
  - Income and expense lines before S move into the virtual row, so each branch's totals are unchanged.
  - Closing sets balance per branch (R9), so leaving them out keeps Dr = Cr.
  - The identity Opening + Period Dr − Period Cr = Closing holds on every row (double-entry AC 44).
- **Consolidated view:** the virtual row is one consolidation line, summed over branches. Retained earnings is one line (MAIN only). Inter-branch pairs eliminate as before, because a close moves both sides of a pair by the same amount.
- **After all earlier years are closed:** the virtual row is 0.00 in every branch and is not shown. Earlier income and expense lines net to 0.00, because each close cleared them.

### R7 Year-end close: what it posts
**One closing transaction per branch** that has any non-zero income or expense balance for the year:
- type `year_end_close`, status `posted`, `txn_date` = E, `branch_id` = that branch;
- `amount` = the branch's total closing debits (information only; no report reads it);
- ref `<CODE>-YEC-<YYYY>-<id>`;
- description "Year-end close FY 2026-27".

All lines are dated E, with `origin = close`. The balances are taken from **all lines dated S..E** on the branch's income and expense accounts (every origin and status; a void or an earlier reopened close nets to 0.00).

| Lines | Branch | Debit | Credit |
|---|---|---|---|
| each income or expense account with balance b ≠ 0 (b = debit − credit) | that branch | \|b\| if b < 0 | b if b > 0 |
| result N = Σ income (credit − debit) − Σ expense (debit − credit); **non-main branch B**, N > 0 | B | — | HO in B N |
| | MAIN | BCA(B) N | 32000 Retained earnings N |
| non-main B, N < 0 | B | HO in B \|N\| | — |
| | MAIN | 32000 \|N\| | BCA(B) \|N\| |
| **MAIN's own** result N > 0 (or < 0) | MAIN | — (32000 \|N\|) | 32000 N (—) |
| N = 0 with non-zero accounts | — | no result line | |

How the accounts behave at year end:
- **Head Office and branches:**
  - A branch has no equity (double-entry Q3/Q10). Its result is moved to head office through its Head office current account: profit credits it, loss debits it.
  - MAIN books the mirror on Branch current account – B and takes the result to 32000.
  - So "HO in B" becomes "head office's net investment in B, including B's retained results". This is the textbook branch-account treatment.
- **Balance-sheet accounts carry forward and are never closed:** cash, bank and wallet; the inter-branch pairs; Owner's capital; Retained earnings.
- **Inactive branches** and inactive category accounts are closed like any other (history).
- **A branch with only zero balances** gets no transaction.
- **A year with no income or expense at all** is still closed: a `year_end_closes` row with no transactions. It still locks the year (R8).

### R8 Year-end close: preconditions, locks, idempotency
`POST /fiscal-years/{y}/close` does all of this in one DB transaction (`ATTEMPTS = 3`, deadlock retry as in the posting methods). It locks every branch row in id order, as `PeriodLockController` does, so a close and a lock or unlock run one after the other.

1. **The year has ended:** E < today (Asia/Colombo). Otherwise 422 "FY 2026-27 has not ended yet."
2. **Not already closed:** no active close for the year. Otherwise 422 "FY 2026-27 is already closed." The unique `active_key` also stops two simultaneous closes; the loser gets the same 422 and nothing posts.
3. **Earlier year closed:** the previous fiscal year is closed, if it has any ledger line. Otherwise 422 "Close FY 2025-26 first."
4. **All 12 months locked:** every branch, active and inactive, is closed through month(E), using the cumulative rule `PeriodLock::closedThrough`. Otherwise 422 "Lock March 2027 for: KDY, GAL (or lock all branches) before closing FY 2026-27."
5. **No unbalanced entry:** no transaction dated ≤ E breaks double-entry V1 (the same grouped query as the lock control). Otherwise 422 "2 entries up to March 2027 are not balanced. Run ledger:verify."
6. **Confirmation:** `confirm` equals the label. Otherwise 422 "Type 2026-27 to confirm."
7. **What the close writes:**
   - the R7 transactions, then the `year_end_closes` row;
   - an **active global period lock** for month(E), if the global closed-through is earlier than month(E). It is `locked_by` the closer, with audit `period.locked` and `source: year_end_close`. **The close itself locks the year for all branches**, whatever branch locks exist.

**Lock exemption.**
- The months are locked, so the closing lines are written by a **private** `LedgerService::postClose` path that skips `assertOpen`. It is the same confinement as the back-fill (double-entry R6, finding 6).
- `postClose` is reached only from `closeYear` / `reopenYear` and takes no bypass flag.
- It writes only `origin = close` lines, and only on income, expense, head_office, branch_current and retained_earnings accounts. **Never on a money account.** Anything else throws.
- L1 (balanced per branch, re-read before commit), L2 and D1–D3 apply as to any posting.

**While a year is closed:**
- **Postings:** `LedgerService::assertOpen` also refuses any date ≤ E of the latest active close: 422 "FY 2026-27 is closed." This backs up the locks.
- **Unlocks:** `PeriodLockController::unlock` refuses an unlock whose `PeriodLock::reopens()` would reopen any month of a closed year: 422 "FY 2026-27 is closed. Reopen the year before unlocking March 2027." Unlocking a later month that leaves the year closed (e.g. a May 2027 lock above the March global lock) is allowed.
- **Edit, void, reverse** of a `year_end_close` transaction: 422 (API table).

### R9 Balance and verification
`ledger:verify` additions:
- **V5 (changed):** the category account balance **excluding `origin = close` lines** equals the all-time I&E figure.
- **V7:** for every active close of year [S, E], each branch's income and expense lines dated ≤ E (all origins) net to 0.00 per account. Also: MAIN 32000 = − Σ (`total_net` of active closes), signed (credit for profit).
- **V8:**
  - `origin = close` lines exist only in `year_end_close` transactions, and those transactions have no other origin;
  - no line dated in a closed year was created after that year's `closed_at`, except the reopen contras of a close.
- **Double-entry R8 reconciliation** (inter-branch movement = Transfers report "Net"): it now excludes `year_end_close` lines as well as non-main capital.

### R10 Who runs the close
- **Super Admin only:** the `super` middleware, in any switcher mode.
- **No delegation:** a Branch Admin or Accountant cannot close or reopen, even for their own branch. The close is one action for all branches, because MAIN's Retained earnings and the inter-branch pairs take part in every branch's close.

### R11 Reopen
- **Which year:** only the **latest** closed year. Otherwise 422 "Reopen FY 2027-28 first."
- **Reason:** 10–500 characters. Otherwise 422.
- **In one DB transaction:**
  1. Lock the branch rows.
  2. Post one contra per close line, through `postClose`: same transaction, account, branch and date E, sides swapped, `contra_of_id` set, `origin = close`.
  3. Set each closing transaction's status to `void`, so the trial balance period columns leave it out (double-entry R4/D4 basis).
  4. Set `year_end_closes.status = reopened` with the who, when and reason.
- **No locks change.** The Super Admin unlocks months separately (F5), which is audited as today.
- **Closing again** after a reopen creates a new `year_end_closes` row and new transactions. The old ones stay void with their contras. The net effect on every account equals one clean close (AC 33).

### R12 Audit
Every change writes `AuditLog::record`. `branch_id` is null unless stated.

| Event | Old | New |
|---|---|---|
| `settings.updated` | the changed keys' old values | the changed keys' new values (one row per save) |
| `settings.logo_uploaded` | previous `{sha256, original_name}` or null | `{original_name, mime, size_bytes, width, height, sha256}` |
| `settings.logo_removed` | `{sha256, original_name}` | `{reason?}` |
| `account.created` | — | 32000 at migration (as double-entry) |
| `year.closed` (auditable = `year_end_closes`) | null | `{fiscal_year, period_start, period_end, by_branch:{CODE:{income, expense, net, transaction_id, ref_no, lines}}, total_net, global_lock_created}` |
| `year.reopened` | `{closed_by, closed_at, total_net}` | `{reason, contra_line_ids, transaction_ids}` |
| `transaction.year_end_close` (per closing transaction, `branch_id` = its branch; written by `finish()`) | null | as other postings |
| `period.locked` | — | the lock made by the close, `source: year_end_close` |

The audit log screen (controls S1) gains the event groups "Settings" (`settings.*`) and "Year-end" (`year.*`, `transaction.year_end_close`).

### R13 Edge cases
- **Branch created after a close:** no lines in the closed year. The global lock covers it, so the next close's check 4 passes for it.
- **Category created or renamed after a close:** the closing lines keep their account ids. The reprinted names change (as for locked months, controls R8).
- **First year with partial data** (go-live in November 2026): FY 2026-27 is closed normally. If any line is dated before 1 April 2026 (e.g. back-dated capital), FY 2025-26 must be locked and closed first (check 3).
- **Concurrent posting during a close:** impossible, because the months are locked and `assertOpen` takes a shared lock read.
- **Concurrent unlock during a close:** they wait on the branch row locks, and the second one re-checks.
- **Settings read on every request:** memoised per request (R1). Changing a setting needs no cache flush beyond that.

## 7. Worked example (Y-dataset, FY 2026-27; QA and accountant check)
Codes as in the double-entry worked example. The balances at 2027-03-31, lines dated 2026-04-01..2027-03-31:
- **KDY:** Retail 50,000.00 Cr, Service income 12,500.00 Cr, Rent 30,000.00 Dr, Electricity 8,200.00 Dr. Result **+24,300.00**.
- **GAL:** Retail 25,000.00 Cr, Water 1,800.00 Dr, Rent 30,000.00 Dr. Result **−6,800.00**.
- **MAIN:** Rent 10,000.00 Dr. Result **−10,000.00**.
- **Total:** +7,500.00.

**Closing transactions (all dated 2027-03-31, `origin = close`):**

| Txn | Branch | Debit | Credit |
|---|---|---|---|
| KDY-YEC | KDY | Retail 50,000.00; Service income 12,500.00 | Rent 30,000.00; Electricity 8,200.00; HO current account 24,300.00 |
| | MAIN | BCA–KDY 24,300.00 | Retained earnings 24,300.00 |
| GAL-YEC | GAL | Retail 25,000.00; HO current account 6,800.00 | Water 1,800.00; Rent 30,000.00 |
| | MAIN | Retained earnings 6,800.00 | BCA–GAL 6,800.00 |
| MAIN-YEC | MAIN | Retained earnings 10,000.00 | Rent 10,000.00 |

Line counts:
- KDY-YEC 7 lines (KDY Dr 62,500.00 = Cr 62,500.00);
- GAL-YEC 6 lines (GAL 31,800.00 = 31,800.00);
- MAIN-YEC 2 lines;
- **15 lines in total.**

Retained earnings (MAIN) = 24,300.00 − 6,800.00 − 10,000.00 = **7,500.00 Cr**.

**Y-1 (pre-closing, 2026-04-01..2027-03-31):** income and expense as above, and no Retained earnings movement.
**Y-2 (post-closing, same range):** every income and expense account closes at 0.00, and Retained earnings is 7,500.00 Cr. KDY HO current account is 24,300.00 Cr more than in Y-1, GAL's is 6,800.00 Dr more, and MAIN's BCA–KDY / BCA–GAL move by the mirror amounts. Eliminations are still 0.00.

**Y-3 (FY 2027-28, 2027-04-01..2027-04-30).**
- **Before the close:**
  - KDY shows "Result of earlier years not yet closed" 24,300.00 Cr; GAL 6,800.00 Dr; MAIN 10,000.00 Dr;
  - the consolidated line is 7,500.00 Cr;
  - the income and expense rows have opening 0.00.
- **After the close:**
  - no virtual row;
  - consolidated Retained earnings is 7,500.00 Cr;
  - consolidated Dr and Cr totals are unchanged.

## 8. Acceptance criteria
Setup: branches MAIN, KDY, GAL. Users:
- SA: Super Admin;
- BA: Branch Admin KDY;
- ACC: Accountant KDY;
- CSH: Cashier KDY.

Fiscal year start April unless stated. `Storage::fake('branding')`. Real image bytes: `logo.png` (PNG 600×200, 180 KB), `logo.jpg` (JPEG), `big.png` (1,048,577 bytes), `huge.png` (PNG 3000×100), `x.svg`, `fake.jpg` (PNG bytes), `evil.png` (`<?php` text), `empty.png` (0 bytes).

**Settings: read and edit**
1. Given a database with no settings rows, When the migration runs, Then `company_name`, `currency` = LKR, `date_format` = `D MMM YYYY` and `fiscal_year_start_month` = 4 exist, and existing rows are not overwritten (a seeded `company_name` "Account-gold" stays).
2. Given CSH (any branch user), When `GET /settings/public`, Then 200 with `currency_code` LKR, `currency_decimals` 2, `date_format`, `fiscal_year_start_month` 4 and `current_fiscal_year.label` "FY 2026-27" (today 2026-11-18). When CSH calls `GET /settings`, `PUT /settings`, `POST /settings/logo` or `POST /settings/logo/remove`, Then 403 "Super Admin only." and the Settings menu is hidden.
3. Given BA (who has every catalogue key), When `PUT /settings`, Then 403. No permission key for settings exists in `PermissionSeeder::PERMISSIONS`.
4. Given SA, When `PUT /settings {company_name:"Lanka Traders (Pvt) Ltd"}`, Then 200, the header (S2), the report screen header, the PDF and the Excel header and the audit Excel header show the new name, and one `settings.updated` row has old "Account-gold" and new "Lanka Traders (Pvt) Ltd". When the same body is sent again, Then 200 and no new audit row.
5. Given `company_name` of "" or "A" or 121 characters, Then 422 and nothing changes. Given `{expense_negative_balance:"warn"}` or `{company_logo:"x"}`, Then 422 "Unknown setting: <key>." and nothing changes.
6. Given SA saves two changed fields at once, Then exactly one `settings.updated` row lists both keys and only those keys.

**Currency**
7. Given an empty ledger, When SA sets `currency_code` "USD", Then 200, every `accounts.currency` is USD, and every "Amounts in …" line (screen, PDF, Excel) reads USD. Given "usd", "US" or "XYZ", Then 422.
8. Given at least one ledger line, When SA sends any `currency_code` different from the current one, Then 422 "The currency cannot be changed after entries have been posted." and S1 shows the field read-only with that note. Sending the same code is 200 with no audit row.
9. Given `currency_decimals` 0 or 3, Then 422 "Only 2 decimal places are supported."; given 2, Then 200 with no audit row.

**Logo**
10. Given SA uploads `logo.png`, Then 201, the file is on the `branding` disk under `logo/<uuid>.png` with mode 0440, the setting holds `sha256`, `width` 600, `height` 200 and no URL, and `settings.logo_uploaded` is written. `logo.jpg` is accepted the same way.
11. Given `big.png`, Then 422 "The logo may be at most 1 MB.". Given `empty.png`, Then 422 "The file is empty.". Given `x.svg` or `evil.png` (any client Content-Type), Then 422 "The logo must be a PNG or JPG image.". Given `fake.jpg`, Then 422 naming the PNG/JPG mismatch. Given `huge.png`, Then 422 on the pixel rule. In each case nothing is stored and the setting is unchanged.
12. Given a logo, When any user exports any report as a PDF, Then every page has the logo image at the top left, and the PDF's `head.logo` is a `data:image/png;base64,` URI built from the stored file. With no logo, the header shows the company name only.
13. Given CSH, When `GET /settings/logo`, Then 200 with the image bytes, `X-Content-Type-Options: nosniff` and the `ETag` = sha256. The app header shows the logo.
14. Given the file was overwritten on disk outside the app, When a PDF is exported, Then the PDF has no logo (no error for the user) and an error is logged. `GET /settings/logo` returns 409. Given the file was deleted, Then 410, and S1 shows the "missing or changed" message.
15. Given SA replaces the logo, Then the old file is still on disk, and `settings.logo_uploaded` holds the previous sha256. When SA removes the logo, Then the setting is empty, the file is still on disk, `settings.logo_removed` is written, and a second remove → 422 "There is no logo to remove." There is no DELETE route.

**Date format**
16. Given `date_format` `DD/MM/YYYY`, Then the transaction list, the entry detail, the statement, the audit log ("05/10/2026 14:03") and the report screens show 05/10/2026 for 2026-10-05. The PDF rows and Period line use it. Excel date cells keep a real date value with number format `dd/mm/yyyy`. The API still returns `2026-10-05`, and date inputs and file names are unchanged.
17. Given `date_format` "MM/DD/YY", Then 422.

**Fiscal year and reports**
18. Given start month 4, Then 2027-03-31 is in FY 2026-27 and 2027-04-01 is in FY 2027-28. Given start month 1, Then 2026-11-18 is in "FY 2026".
19. Given the trial balance with From 2027-03-01 and To 2027-04-30, Then 422 "From and To must be in the same fiscal year (FY 2026-27: 2026-04-01 to 2027-03-31)." The I&E report accepts the same range.
20. Given the Y-dataset, no close, and a trial balance for KDY 2027-04-01..2027-04-30, Then every income and expense row has opening 0.00, "Result of earlier years not yet closed" is 24,300.00 Cr, the totals balance, the identity holds on every row, and `earlier_unclosed` is true (amber banner).
21. Given the reports filter presets, Then "This fiscal year" on 2026-11-18 sets 2026-04-01..2027-03-31, the PDF period reads "FY 2026-27, 2026-04-01 to 2027-03-31", and "Last fiscal year" sets 2025-04-01..2026-03-31.
22. Given a close exists (any status), When SA changes `fiscal_year_start_month`, Then 422 "The fiscal year start cannot be changed after a year-end close." Before any close, the change is 200 and audited.

**Year-end close**
23. Given the Y-dataset with March 2027 locked for KDY and MAIN only, When SA opens Year-end, Then FY 2026-27 is Open with the check "Lock March 2027 for: GAL (or lock all branches) before closing FY 2026-27.", and `POST /fiscal-years/2026/close` returns that 422 and posts nothing.
24. Given March 2027 is locked for all branches (only that row; earlier months have no lock rows), Then the status is "Ready to close", and the preview shows KDY +24,300.00, GAL −6,800.00, MAIN −10,000.00, total +7,500.00 with the lines of section 7.
25. Given Ready, When SA closes with `confirm` "2026-27", Then 201. There are 3 transactions of type `year_end_close` dated 2027-03-31 with exactly the 15 lines of section 7, all `origin = close`, each balanced per branch. No money account has a line, and every money account's `current_balance` is unchanged. MAIN 32000 is 7,500.00 Cr. One `year.closed` row has `by_branch` and `total_net` 7,500.00. Each transaction wrote `transaction.year_end_close` with its branch.
26. Given AC 25, When the trial balance runs for 2026-04-01..2027-03-31, Then by default (pre-closing) it equals Y-1. With `closing_entries=1` it equals Y-2: every income and expense closes at 0.00, eliminations are 0.00, and the identity holds. With `closing_entries=1` and To 2027-03-30, Then 422.
27. Given AC 25, When the trial balance runs for FY 2027-28 April, by branch and consolidated, Then no virtual row shows. Consolidated Retained earnings is 7,500.00 Cr, and the consolidated Dr and Cr totals equal those of the same consolidated report run before the close.
28. Given AC 25, When SA closes FY 2026-27 again (or two close requests run at the same time), Then 422 "FY 2026-27 is already closed." Exactly one active `year_end_closes` row and one set of 15 lines exist.
29. Given `confirm` "2026", Then 422 "Type 2026-27 to confirm." Given FY 2027-28 on 2027-05-01, Then 422 "FY 2027-28 has not ended yet." Given a line dated 2026-03-15 and FY 2025-26 not closed, When closing FY 2026-27, Then 422 "Close FY 2025-26 first."
30. Given a transaction dated ≤ 2027-03-31 whose lines do not balance (test, triggers bypassed), When closing, Then 422 "1 entries up to March 2027 are not balanced. Run ledger:verify." and nothing is posted.
31. Given AC 25, When SA unlocks the global March 2027 lock, Then 422 "FY 2026-27 is closed. Reopen the year before unlocking March 2027." Given a global May 2027 lock above it, When SA unlocks May, Then 200 (April–May reopen) and March stays locked.
32. Given AC 25, When any income, expense, transfer, reclass, void or reversal dated in FY 2026-27 is attempted, Then 422 and nothing is posted. When `PUT`, `void` or `reverse` is called on a `year_end_close` transaction, Then 422 "Year-end closing entries change only by reopening the year."
33. Given AC 25, When SA reopens FY 2026-27 with the reason "Late supplier bill for March", Then 200. 15 contra lines are added (same accounts, branches and date, sides swapped, `contra_of_id` set, `origin = close`). The 3 transactions have status `void`. The `year_end_closes` row is `reopened` with who, when and reason, `year.reopened` is written, and no period lock changed. Every account's balance equals its value before AC 25. When SA then unlocks March, posts a 1,000.00 Rent expense in KDY on 2027-03-31, re-locks and closes again, Then a new close row and new transactions exist, KDY's result is +23,300.00, Retained earnings is 6,500.00 Cr, and `ledger:verify` passes.
34. Given a reason under 10 characters, Then 422. Given FY 2026-27 and FY 2027-28 both closed, When SA reopens FY 2026-27, Then 422 "Reopen FY 2027-28 first."
35. Given BA, ACC or CSH, When calling any `/fiscal-years` route, Then 403, and the Year-end menu is hidden. ACC can open a KDY `year_end_close` transaction (read-only), and its MAIN lines show account names only.
36. Given a year with no income or expense lines but every branch locked, When closed, Then 201 with no transactions, a `year_end_closes` row, and `year.closed` with `total_net` 0.00.
37. Given March 2027 locked for KDY, GAL and MAIN by branch locks only, When the year is closed, Then an active global lock for 2027-03 exists, `locked_by` SA, with a `period.locked` audit row with `source: year_end_close`. Given a branch MTR created after the close, Then posting to MTR dated 2027-03-20 is refused.
38. Given an inactive branch with income in the year, Then it gets its closing transaction like any other branch.
39. Given a test that makes `postClose` write a line on a money account, or a line with an origin other than `close`, Then it throws and nothing remains. No public `LedgerService` method accepts a lock bypass.

**Verification and reconciliation**
40. Given AC 25, When `ledger:verify` runs, Then V1–V8 pass. V7 confirms every KDY, GAL and MAIN income and expense account nets to 0.00 at 2027-03-31, and 32000 = 7,500.00 Cr. Given a test line inserted on 2027-02-10 after `closed_at` (locks bypassed), Then V8 names it and exits 1.
41. Given AC 25, Then V5 still matches the I&E all-time figures, and the KDY/GAL/MAIN inter-branch movement for March 2027 still equals the Transfers report "Net" (closing lines excluded).
42. Given AC 25, Then the I&E, Branch comparison, Transfers and Cash book reports and the dashboard for any range are identical to before the close.
43. Given the audit log, Then the "Settings" group lists the `settings.*` rows and "Year-end" lists `year.*` and `transaction.year_end_close`. ACC (KDY) sees KDY's `transaction.year_end_close` row but not `year.closed` (no branch).

## 9. Open questions (assumption in bold)
**For the accountant (year-end close):**
- **Q-A1 Where does a branch's result go?** **Assumed: into its Head office current account, with Retained earnings (32000) in MAIN only (R7).** This follows Q3/Q10 (a branch has no equity) and the textbook branch-account treatment. Consolidation shows one Retained earnings line. The HO current account then no longer equals "funding only", and the Transfers reconciliation excludes the closing lines. The alternative is a 32000 account in every branch, summed on consolidation: simpler branch books, but equity outside MAIN. Please confirm.
- **Q-A2 Account name:** **"Retained earnings"** (the code was reserved under that name), or "Accumulated surplus/deficit"? Is one account enough, with no separate "Current year profit" account?
- **Q-A3 Lock requirement:** **every branch closed through month(E)**, using the cumulative locks. Locking March for all branches is enough, and no lock is needed per month. Is that acceptable, or must each of the 12 months have its own lock row (with sign-off evidence)?
- **Q-A4 One close for all branches:** **assumed one action, all or nothing**, because MAIN takes part in every branch's close. Is a per-branch close ever needed (e.g. one branch's March is still disputed)?
- **Q-A5 Reopen:** **Super Admin only, latest year only, contra lines on the original date, locks untouched**. Should reopening also need a second person (the Viewer/external accountant), or is the audit row and reason enough?
- **Q-A6 Trial balance default:** **pre-closing** at year end, with a "post-closing" toggle. Is that the view you print and sign at year end?
- **Q-A7 Trial balance range:** **From/To must lie in one fiscal year**. Is a multi-year trial balance ever needed?
- **Q-A8 Earlier data:** if any entry is dated before 1 April 2026 (back-dated capital or opening allocations), FY 2025-26 must be closed first. Should opening balances at go-live instead be treated as balance-sheet only (no FY 2025-26 close needed)?

**For the product owner:**
- **Q1 Split:** **keep AG-092 (settings + fiscal year) and add a new Must row "Year-end close"** with the 31 March 2027 deadline. AG-092's 2 points do not cover R7–R12.
- **Q2 Currency "configurable":** **the code is configurable until the first posting; decimals are fixed at 2** (integer cents, `decimal(15,2)`). Is that what "configurable" meant?
- **Q3 Approval thresholds:** **not stored now** (section 1). AG-051 and AG-064 add them with enforcement. Should the threshold for AG-064 be per branch, as its backlog row says?
- **Q4 Date formats offered:** **the four in S1; the default "5 Oct 2026" keeps today's look**. Is a US format (MM/DD/YYYY) needed? It is excluded to avoid misreading 05/10.
- **Q5 Who reads settings:** **every signed-in user reads the general settings; only the Super Admin sees the settings screen.** Should a Branch Admin see (not edit) the full Settings screen?
- **Q6 Logo on the login page:** **no.** That would need an unauthenticated endpoint. Is it wanted?
- **Q7 `expense_negative_balance` (block/warn):** **no UI in this feature (unchanged).** Should it be added to Settings?
