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
  - **No result to close:** the year has no income or expense line (only balance-sheet lines such as capital or opening allocations). It does not block the next close (R8 check 3) and is covered by the cumulative lock. It can still be closed (R7, AC 36).
- **History** (review finding 5): under each row, every close and reopen of that year, oldest first, with who, when, the total result and the reopen reason.
- **Check list** on an Open row (one line per failed check), e.g. "Lock March 2027 for: KDY, GAL (or lock all branches).", "Close FY 2025-26 first.", "2 entries up to March 2027 are not balanced. Run ledger:verify."
- **"Preview"** (Ready or Closed): one card per branch with Income total, Expense total, Result, and the closing lines (Code | Account | Debit | Credit), including the MAIN lines for that branch. A summary card shows the result per branch, the total, and the Retained earnings movement. Print button.
  - **I&E reconciliation** (review finding 2): each branch card and the summary show the ledger result N, the Income & Expense report net for S..E (`countedTransactions`) and the difference. A difference other than 0.00 is shown in red and blocks the close (R8 check 5b).
  - **Print** (review finding 12): title "Year-end close FY 2026-27 — preview", the logo and header as the PDF reports, the I&E reconciliation, and the Prepared by / Checked by / Approved by block.
- **"Close FY 2026-27"** (Ready only): a dialog listing the result per branch and the total. It needs the label typed exactly ("2026-27"). Text: "This posts closing entries dated 31 Mar 2027 in every branch and keeps March 2027 locked for all branches. It can only be undone by reopening the year."
- **"Reopen year"** (latest closed year only): Reason (textarea, required, 10–500). Text: "This cancels the closing entries. The months stay locked; unlock them in Period locks if you need to change an entry." Warning (review finding 10, LKAS 8): "Reopen only before the accounts for this year are signed or filed. After that, record corrections in the current year."
- **Empty state:** "No entries yet." **Errors:** the API `message` in `.alert`.

**S6 Trial balance changes** (double-entry S2):
- **Validation:** From and To must be in one fiscal year (R5).
- **Presets** (all reports' filter bar): "This fiscal year", "Last fiscal year" (and the existing month presets).
- **Toggle "Include closing entries"** (post-closing view): shown only when To is the last day of a closed fiscal year. Default off (pre-closing).
- **Banners:**
  - amber "FY 2026-27 is not closed yet." when an earlier year with income or expense lines is not closed;
  - grey "FY 2026-27 closed on <date> by <name>." when the shown year is closed;
  - red "FY 2026-27 was reopened on <date> by <name>: <reason>." when the shown year has been reopened and not closed again (review finding 5).
- **Basis line:** "Income and expense from the start of FY <label> (<start>). Results of earlier years are in Retained earnings (MAIN) and Head office current accounts once closed, otherwise in 'Result of earlier years not yet closed'. Retained earnings excludes results before go-live. Closing entries excluded / included." (The go-live sentence is review finding 14; the opening Retained earnings journal is a follow-up in the "Manual journals + liabilities" backlog item.)
- **PDF / Excel title** (review finding 12): "Trial balance — pre-closing" or "Trial balance — post-closing" (the PDF prints the dash as "-"). The PDF ends with the Prepared by / Checked by / Approved by block.
- **PDF header of a year closed again** (review finding 5): the line "Reopened N time(s)".
- **Period label** (`ReportExporter::periodLabel`): an exact fiscal year range reads "FY 2026-27, 2026-04-01 to 2027-03-31" (the dates in the date format setting, S3; with the default format "FY 2026-27, 1 Apr 2026 to 31 Mar 2027"). The calendar label stays for Jan–Dec only when the fiscal year does not start in January.

**S7 Other screens.**
- **Transactions list and detail:** type label "Year-end close". No Edit, Void or Reverse actions.
  - **Amount** (review finding 15): the closing transaction's `amount` (total closing debits) is not money moved. The list shows "—" in the Amount column, and `year_end_close` is left out of any total or sum on the list and its export. The lines are on the detail.
- **Detail** "Ledger lines" note: "Year-end close FY 2026-27", or "Cancelled by reopening FY 2026-27" for the contras.
- **Chart of accounts:** MAIN shows 32000 "Retained earnings" under Equity, type "Retained earnings", created from "System".
- **Chart info line** adds: "At year end, each branch's result is moved to its Head office current account and to Retained earnings in MAIN."
- **Account pickers** (review finding 3): Retained earnings is never offered by any account picker, `accounts/destinations`, the transfer or the capital forms (they list cash, bank and wallet accounts only).

## 5. API
| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `GET /v1/settings/public` | `auth:sanctum` (outside `branch`, like `me`) | — | `{company_name, logo:{sha256, updated_at}\|null, currency_code, currency_decimals:2, date_format, fiscal_year_start_month, current_fiscal_year:{start_year, label, start, end}}` |
| `GET /v1/settings/logo` | `auth:sanctum` | — | the image bytes. Headers: `Content-Type` = stored mime, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=300`, `ETag` = sha256. 404 "No logo." 410 "The logo file is missing from storage. Upload it again." 409 when the sha256 does not match (R3) |
| `GET /v1/settings` | `super` | — | the public fields + `logo:{original_name, mime, size_bytes, width, height, sha256, uploaded_by:{id,name}, uploaded_at}\|null`, `currency_locked:bool`, `fiscal_year_start_locked:bool`, `last_changed:{key:{by:{id,name}, at}}` |
| `PUT /v1/settings` | `super` | any of `company_name`, `currency_code`, `date_format`, `fiscal_year_start_month`, `currency_decimals` | 200 with the `GET /settings` body. 422 for: an unknown or not editable key (e.g. `expense_negative_balance`, `company_logo`) "Unknown setting: <key>."; field rules in S1; currency with entries (R2); decimals ≠ 2 (R2); fiscal year start after a close (R4) |
| `POST /v1/settings/logo` | `super`, `throttle:attachments-upload` | multipart `file` | 201 with `logo` metadata. 422 messages in R3 |
| `POST /v1/settings/logo/remove` | `super` | — | 200. 422 "There is no logo to remove." No DELETE route (nothing is hard-deleted) |
| `GET /v1/fiscal-years` | `super` | — | `{data:[{start_year, label, start, end, status:'open'\|'ready'\|'closed'\|'reopened'\|'no_result', can_close, result, checks:[{code, message}], close:{id, closed_by, closed_at, total_net, reopened_by, reopened_at, reopen_reason}\|null, history:[same shape as close, oldest first]}]}` |
| `GET /v1/fiscal-years/{startYear}/preview` | `super` | — | `{fiscal_year, branches:[{branch:{id,code}, income, expense, net, ie_net, difference, lines:[{branch_code, code, account, debit, credit}]}], total_net, total_ie_net, retained_earnings_movement, checks}`. 404 for a year outside the list |
| `POST /v1/fiscal-years/{startYear}/close` | `super` | `confirm` (must equal the label) | 201 `{close, transactions:[{id, ref_no, branch_code, lines}]}`. 422 messages in R8 and R10 |
| `POST /v1/fiscal-years/{startYear}/reopen` | `super` | `reason` (10–500) | 200. 422 in R11 (including "FY 2026-27 is not closed." for the loser of two simultaneous reopens) |
| `GET /v1/reports/trial-balance` (+ export) | unchanged | + `closing_entries` (0/1, default 0) | `meta` gains `fiscal_year:{label, start, end, closed, closed_at, closed_by, reopened:{at, by, reason}\|null, reopen_count}`, `earlier_unclosed:bool`, `earlier_unclosed_label` and `closing_entries:bool`. Rows may include the virtual row (R6). 422 in R5 |
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
     - `period_lock_id` (FK `period_locks`): the active global lock for exactly month(E) that the close ensured (review finding 1, R8 step 7);
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
| **MAIN's own** result N > 0 | MAIN | — | 32000 N |
| **MAIN's own** result N < 0 | MAIN | 32000 \|N\| | — |
| N = 0 with non-zero accounts | — | no result line | |

The MAIN mirror lines (BCA(B) / 32000) of a non-main branch B sit in **B's** closing transaction, so the KDY close has MAIN lines. MAIN's own closing transaction (MAIN-YEC) exists only when MAIN has its own income or expense balances (review finding 13; section 7 shows this).

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
3. **Earlier year closed:** the previous fiscal year is closed, if it has **any income or expense line** (review finding 11, Q-A8). Otherwise 422 "Close FY 2025-26 first." A year with only balance-sheet lines (capital, opening allocations, funding) has nothing to close and does not block.
4. **All 12 months locked:** every branch, active and inactive, is closed through month(E), using the cumulative rule `PeriodLock::closedThrough`. Otherwise 422 "Lock March 2027 for: KDY, GAL (or lock all branches) before closing FY 2026-27."
5. **No unbalanced entry:** no transaction dated ≤ E breaks double-entry V1 (the same grouped query as the lock control). Otherwise 422 "2 entries up to March 2027 are not balanced. Run ledger:verify."
5b. **The year result reconciles to the I&E report** (review finding 2): for every branch, the ledger result N (R7) equals the Income & Expense report net for S..E (`countedTransactions`, the same basis as the I&E report). Otherwise 422 "The ledger result for KDY differs from the Income & Expense report by 100.00. Run ledger:verify." and nothing is posted.
6. **Confirmation:** `confirm` equals the label. Otherwise 422 "Type 2026-27 to confirm."
7. **What the close writes:**
   - the R7 transactions, then the `year_end_closes` row. `closed_at` is taken **after** the closing lines are written, in the same DB transaction (review finding 7);
   - an **active global period lock for exactly month(E)** (review finding 1): the close creates one unless an active global lock for that month already exists (a later global lock, e.g. April 2027, does not count). It is `locked_by` the closer, with audit `period.locked` and `source: year_end_close`. Its id is stored in `year_end_closes.period_lock_id`. **The close itself locks the year for all branches**, whatever branch or later locks exist;
   - a queued notification to every active Super Admin (review finding 5, below).

**Lock exemption.**
- The months are locked, so the closing lines are written by a **private** `LedgerService::postClose` path that skips `assertOpen`. It is the same confinement as the back-fill (double-entry R6, finding 6).
- `postClose` is reached only from `closeYear` / `reopenYear` and takes no bypass flag.
- It writes only `origin = close` lines, and only on income, expense, head_office, branch_current and retained_earnings accounts. **Never on a money account.** Anything else throws.
- It writes only lines dated E of the year being closed or reopened. It is the **only** writer allowed in a closed year (review finding 4).
- L1 (balanced per branch, re-read before commit), L2 and D1–D3 apply as to any posting.
- **Retained earnings is written only by the close** (review finding 3): `LedgerService` refuses any line on a `retained_earnings` account whose origin is not `close`. The account type is not offered by any picker, `accounts/destinations`, the transfer or the capital paths (they take cash, bank and wallet accounts only).
- **The back-fill path never writes in a closed year** (review finding 4): the private back-fill path, which skips the lock check, refuses any line dated ≤ E of an active close: "Back-fill of <ref> refused: FY 2026-27 is closed." The run stops, names the entry and adds no line.

**While a year is closed:**
- **Postings:** `LedgerService::assertOpen` also refuses any date ≤ E of the latest active close: 422 "FY 2026-27 is closed." This backs up the locks.
- **Unlocks:** `PeriodLockController::unlock` refuses
  - the close's own lock (`year_end_closes.period_lock_id`) while the close is active: 422 "FY 2026-27 is closed. Reopen the year before unlocking March 2027." (review finding 1);
  - any other unlock whose `PeriodLock::reopens()` would reopen any month of a closed year, with the same message naming the lock's month.
  - Unlocking a later month that leaves the year closed (e.g. a May 2027 lock above the March global lock, or an April 2027 global lock that existed before the close) is allowed.
- **Edit, void, reverse** of a `year_end_close` transaction: 422 (API table).

### R9 Balance and verification
`ledger:verify` additions:
- **V5 (changed):** the category account balance **excluding `origin = close` lines** equals the all-time I&E figure.
- **V7:** for every active close of year [S, E], each branch's income and expense lines dated ≤ E (all origins) net to 0.00 per account. Also: MAIN 32000 = − Σ (`total_net` of active closes), signed (credit for profit). Also (review finding 8): for each active close and branch B, the close's lines on HO in B (non-main B) or on 32000 in MAIN's own closing transaction (MAIN) equal −N(B) as stored in `summary`; V4 still holds after the close.
- **V8:**
  - `origin = close` lines exist only in `year_end_close` transactions, and those transactions have no other origin;
  - for each **active** close, every line dated in [S, E] has `created_at ≤ closed_at`, or belongs to one of that close's own transactions (ids in `summary`) (review finding 7). Only active closes are checked, so a reopen's contras need no exception;
  - every line on a `retained_earnings` account has `origin = close` (review finding 3).
- **Double-entry R8 reconciliation** (inter-branch movement = Transfers report "Net"): it now excludes `year_end_close` lines as well as non-main capital.

### R10 Who runs the close
- **Super Admin only:** the `super` middleware, in any switcher mode.
- **No delegation:** a Branch Admin or Accountant cannot close or reopen, even for their own branch. The close is one action for all branches, because MAIN's Retained earnings and the inter-branch pairs take part in every branch's close.

### R11 Reopen
- **Which year:** only the **latest** closed year. Otherwise 422 "Reopen FY 2027-28 first."
- **Reason:** 10–500 characters. Otherwise 422.
- **Concurrency** (review finding 9): the reopen reads the `year_end_closes` row `FOR UPDATE` and re-checks `status = closed` and "latest closed year" inside the transaction. Of two simultaneous reopens, one posts the contras; the other gets 422 "FY 2026-27 is not closed." and posts nothing.
- **In one DB transaction:**
  1. Lock the branch rows.
  2. Post one contra per close line, through `postClose`: same transaction, account, branch and date E, sides swapped, `contra_of_id` set, `origin = close`.
  3. Set each closing transaction's status to `void`, so the trial balance period columns leave it out (double-entry R4/D4 basis).
  4. Set `year_end_closes.status = reopened` with the who, when and reason.
  5. Write `transaction.year_end_reopened` per closing transaction (review finding 6) and `year.reopened` (R12), and queue the Super Admin notification (below).
- **No locks change.** The Super Admin unlocks months separately (F5), which is audited as today.
- **Closing again** after a reopen creates a new `year_end_closes` row and new transactions. The old ones stay void with their contras. The net effect on every account equals one clean close (AC 33).

### R11b Compensating controls for close and reopen (review finding 5, Q-A5)
No second person is required in R1. Instead:
- **Notification:** on `year.closed` and `year.reopened`, a queued notification (`YearEndNotification`, mail, after commit) goes to **every** active Super Admin, the actor included. (`AccessAlerts` skips Super Admin actors, so this is a separate class.) It gives the year, the actor, the time, the total result and, on a reopen, the reason.
- **History:** the Year-end screen keeps every close and reopen row of each year (S5).
- **Banner:** the trial balance of a year that has been reopened and not closed again shows the red banner (S6).
- **PDF:** the trial balance PDF of a year that was closed again lists "Reopened N time(s)" in its header.
- Backlog note: when the R2 approval framework (AG-051) exists, make reopening approvable by a second Super Admin.

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
| `transaction.year_end_reopened` (per closing transaction, `branch_id` = its branch, auditable = the transaction; review finding 6) | `{status: posted}` | `{status: void, reason, contra_line_ids}` |
| `transaction.year_end_close` (per closing transaction, `branch_id` = its branch; written by `finish()`) | null | as other postings |
| `period.locked` | — | the lock made by the close, `source: year_end_close` |

The audit log screen (controls S1) gains the event groups "Settings" (`settings.*`) and "Year-end" (`year.*`, `transaction.year_end_close`, `transaction.year_end_reopened`).

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
21. Given the reports filter presets, Then "This fiscal year" on 2026-11-18 sets 2026-04-01..2027-03-31, the PDF period reads "FY 2026-27, 2026-04-01 to 2027-03-31" (date format `YYYY-MM-DD`; with the default format "FY 2026-27, 1 Apr 2026 to 31 Mar 2027"), and "Last fiscal year" sets 2025-04-01..2026-03-31.
22. Given a close exists (any status), When SA changes `fiscal_year_start_month`, Then 422 "The fiscal year start cannot be changed after a year-end close." Before any close, the change is 200 and audited.

**Year-end close**
23. Given the Y-dataset with March 2027 locked for KDY and MAIN only, When SA opens Year-end, Then FY 2026-27 is Open with the check "Lock March 2027 for: GAL (or lock all branches) before closing FY 2026-27.", and `POST /fiscal-years/2026/close` returns that 422 and posts nothing.
24. Given March 2027 is locked for all branches (only that row; earlier months have no lock rows), Then the status is "Ready to close", and the preview shows KDY +24,300.00, GAL −6,800.00, MAIN −10,000.00, total +7,500.00 with the lines of section 7.
25. Given Ready, When SA closes with `confirm` "2026-27", Then 201. There are 3 transactions of type `year_end_close` dated 2027-03-31 with exactly the 15 lines of section 7, all `origin = close`, each balanced per branch. No money account has a line, and every money account's `current_balance` is unchanged. MAIN 32000 is 7,500.00 Cr. One `year.closed` row has `by_branch` and `total_net` 7,500.00. Each transaction wrote `transaction.year_end_close` with its branch.
26. Given AC 25, When the trial balance runs for 2026-04-01..2027-03-31, Then by default (pre-closing) it equals Y-1. With `closing_entries=1` it equals Y-2: every income and expense closes at 0.00, eliminations are 0.00, and the identity holds. With `closing_entries=1` and To 2027-03-30, Then 422.
27. Given AC 25, When the trial balance runs for FY 2027-28 April, by branch and consolidated, Then no virtual row shows. Consolidated Retained earnings is 7,500.00 Cr, and the consolidated Dr and Cr totals equal those of the same consolidated report run before the close.
28. Given AC 25, When SA closes FY 2026-27 again (or two close requests run at the same time), Then 422 "FY 2026-27 is already closed." Exactly one active `year_end_closes` row and one set of 15 lines exist.
29. Given `confirm` "2026", Then 422 "Type 2026-27 to confirm." Given FY 2027-28 on 2027-05-01, Then 422 "FY 2027-28 has not ended yet." Given an **expense** line dated 2026-03-15 and FY 2025-26 not closed, When closing FY 2026-27, Then 422 "Close FY 2025-26 first." Given only a **capital** line dated 2026-03-15 (FY 2025-26 has no income or expense), Then the close is not blocked by FY 2025-26, and the Year-end screen shows FY 2025-26 as "No result to close" (review finding 11).
30. Given a transaction dated ≤ 2027-03-31 whose lines do not balance (test, triggers bypassed), When closing, Then 422 "1 entries up to March 2027 are not balanced. Run ledger:verify." and nothing is posted.
31. Given AC 25, When SA unlocks the global March 2027 lock, Then 422 "FY 2026-27 is closed. Reopen the year before unlocking March 2027." Given a global May 2027 lock above it, When SA unlocks May, Then 200 (April–May reopen) and March stays locked.
32. Given AC 25, When any income, expense, transfer, reclass, void or reversal dated in FY 2026-27 is attempted, Then 422 and nothing is posted. When `PUT`, `void` or `reverse` is called on a `year_end_close` transaction, Then 422 "Year-end closing entries change only by reopening the year."
33. Given AC 25, When SA reopens FY 2026-27 with the reason "Late supplier bill for March", Then 200. 15 contra lines are added (same accounts, branches and date, sides swapped, `contra_of_id` set, `origin = close`). The 3 transactions have status `void`. The `year_end_closes` row is `reopened` with who, when and reason, `year.reopened` is written, and no period lock changed. Every account's balance equals its value before AC 25. When SA then unlocks March, posts a 1,000.00 Rent expense in KDY on 2027-03-31, re-locks and closes again, Then a new close row and new transactions exist, KDY's result is +23,300.00, Retained earnings is 6,500.00 Cr, and `ledger:verify` passes.
34. Given a reason under 10 characters, Then 422. Given FY 2026-27 and FY 2027-28 both closed, When SA reopens FY 2026-27, Then 422 "Reopen FY 2027-28 first." Given two reopens of FY 2026-27 at the same time, Then one succeeds with one set of 15 contras and the other gets 422 "FY 2026-27 is not closed." (review finding 9).
35. Given BA, ACC or CSH, When calling any `/fiscal-years` route, Then 403, and the Year-end menu is hidden. ACC can open a KDY `year_end_close` transaction (read-only), and its MAIN lines show account names only.
36. Given a year with no income or expense lines but every branch locked, When closed, Then 201 with no transactions, a `year_end_closes` row, and `year.closed` with `total_net` 0.00.
37. Given March 2027 locked for KDY, GAL and MAIN by branch locks only, When the year is closed, Then an active global lock for 2027-03 exists, `locked_by` SA, with a `period.locked` audit row with `source: year_end_close`. Given a branch MTR created after the close, Then posting to MTR dated 2027-03-20 is refused.
38. Given an inactive branch with income in the year, Then it gets its closing transaction like any other branch.
39. Given a test that makes `postClose` write a line on a money account, or a line with an origin other than `close`, Then it throws and nothing remains. No public `LedgerService` method accepts a lock bypass.

**Verification and reconciliation**
40. Given AC 25, When `ledger:verify` runs, Then V1–V8 pass. V7 confirms every KDY, GAL and MAIN income and expense account nets to 0.00 at 2027-03-31, and 32000 = 7,500.00 Cr. Given a test line inserted on 2027-02-10 after `closed_at` (locks bypassed), Then V8 names it and exits 1.
41. Given AC 25, Then V5 still matches the I&E all-time figures, and the KDY/GAL/MAIN inter-branch movement for March 2027 still equals the Transfers report "Net" (closing lines excluded).
42. Given AC 25, Then the I&E, Branch comparison, Transfers and Cash book reports and the dashboard for any range are identical to before the close.
43. Given the audit log, Then the "Settings" group lists the `settings.*` rows and "Year-end" lists `year.*`, `transaction.year_end_close` and `transaction.year_end_reopened`. ACC (KDY) sees KDY's `transaction.year_end_close` and, after a reopen, KDY's `transaction.year_end_reopened` row (old `{status: posted}`, new `{status: void, reason, contra_line_ids}`), but not `year.closed` or `year.reopened` (no branch).

**Accounting review findings (added before development)**
44. (Finding 1a) Given a global April 2027 lock created before the close and March 2027 locked for all branches, When the year is closed, Then the close still creates an active global March 2027 lock and stores its id in `period_lock_id`. When SA then unlocks April 2027, Then 200: April reopens only and March stays closed.
45. (Finding 1b) Given the AC 37 setup after the close (branch locks plus the close's global March lock), When SA unlocks the global March 2027 lock, Then 422 "FY 2026-27 is closed. Reopen the year before unlocking March 2027." After a reopen, the same unlock is 200.
46. (Finding 2) Given the Y-dataset, Then the preview's I&E column equals the I&E report for 2026-04-01..2027-03-31 per branch (KDY +24,300.00, GAL −6,800.00, MAIN −10,000.00, total +7,500.00) with difference 0.00. Given a KDY income line whose ledger result differs from the I&E report by 100.00 (test, category lines bypassed), When closing, Then 422 "The ledger result for KDY differs from the Income & Expense report by 100.00. Run ledger:verify." and nothing is posted.
47. (Finding 3) Given any posting path other than the close (income, expense, transfer, capital, reclass, void, reversal, back-fill), Then no line can be written on the `retained_earnings` account (the service throws), and `accounts`, `accounts/destinations` and the chart pickers never offer it as a money account. Given a test line on 32000 with `origin = posting` (service bypassed), Then `ledger:verify` V8 names it and exits 1.
48. (Finding 4) Given AC 25, When a back-fill re-run meets an unbalanced (money lines only) transaction dated 2027-02-10, Then it stops with "Back-fill of <ref> refused: FY 2026-27 is closed.", names the entry and adds no line.
49. (Finding 5) Given AC 25, Then every active Super Admin (the closer included) is sent a queued `YearEndNotification` with the year, the actor, the time and the total result. After AC 33's reopen, the same notification is sent with the reason. The Year-end screen lists both the close and the reopen of FY 2026-27 in its history. A trial balance of FY 2026-27 after the reopen has `meta.fiscal_year.reopened` with the date, the name and the reason (red banner). After closing again, the trial balance PDF header has "Reopened 1 time(s)".
50. (Finding 6) Given AC 33, Then each of the 3 closing transactions has a `transaction.year_end_reopened` audit row with its branch id, old `{status: posted}` and new `{status: void, reason, contra_line_ids}`.
51. (Finding 7) Given AC 25, Then `closed_at` is not earlier than the `created_at` of any of the 15 closing lines, and V8 passes on its own lines.
52. (Finding 8) Given AC 25 and a `summary` changed so that KDY's net differs from its HO close lines (test), Then `ledger:verify` V7 names KDY and exits 1.
53. (Finding 12) Given AC 25, Then the trial balance PDF title reads "Trial balance - pre-closing" by default and "Trial balance - post-closing" with `closing_entries=1`, and the PDF has the Prepared by / Checked by / Approved by block.
54. (Finding 15) Given AC 25, Then the Transactions list shows the closing transactions with type "Year-end close" and amount "—", and no list total includes them.

## 9. Open questions (assumption in bold)
**Decisions (accountant review 2026-11-18 and product-owner defaults), folded into the body above:**
- Q-A1 agreed: the branch result goes to its Head office current account; 32000 exists in MAIN only (R7).
- Q-A2 agreed: one account, "Retained earnings"; no "Current year profit" account.
- Q-A3 agreed: every branch closed through month(E) is enough; the close keeps its own global lock for month(E) (finding 1, R8 step 7).
- Q-A4 agreed: one close for all branches, all or nothing.
- Q-A5: no second person in R1; the reason plus the compensating controls of R11b instead.
- Q-A6 agreed: the trial balance default is pre-closing; the PDF states which basis it is (S6).
- Q-A7 agreed: one fiscal year per trial balance.
- Q-A8: go-live opening balances are balance-sheet only; only a year with income or expense lines must be closed before the next one (R8 check 3).
- Product owner: the year-end close ships in this feature, in production before the first FY 2027-28 trial balance; currency changeable only before the first ledger entry, decimals fixed at 2; no approval thresholds stored; no US date format; Settings screen Super Admin only; no logo on the login page; no UI for `expense_negative_balance` (if added later, audited as `settings.updated`).

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

## Accounting review (accountant, 2026-11-18, before development)

Reviewed against the double-entry spec and its sign-off, `LedgerService` (`assertOpen`, `void`, the private back-fill path), `PeriodLock::closedThrough` / `reopens`, `ReportService` (trial balance, `countedTransactions`) and `AccessAlerts`. The design is sound: one closing set per branch, balanced in each branch; the branch result goes through the head office current account to a single Retained earnings in MAIN; the reopen uses contras and deletes nothing; the lock exemption is confined like the back-fill. I re-computed section 7: KDY 62,500.00 = 62,500.00 (+24,300.00), GAL 31,800.00 = 31,800.00 (−6,800.00), MAIN −10,000.00, 15 lines, Retained earnings 7,500.00 Cr. AC 33 (+23,300.00, 6,500.00 Cr) is also correct. The findings below tighten the controls; none of them changes the posting model.

### Findings

1. **MUST — The close's lock must be recorded on the close and cannot be removed while the year is closed.** R8.7 creates a global lock only "if the global closed-through is earlier than month(E)". That leaves two gaps:
   - If a later global lock already exists (e.g. April 2027 was locked before the close), no March lock is created. Unlocking April then "reopens" March as well and is refused, so the Super Admin would have to reopen a closed year just to fix April.
   - If March is covered by branch locks plus a global lock, `reopens()` is empty when the global lock is removed, so the unlock is allowed. AC 31 then fails in the AC 37 setup, and a branch created afterwards is protected only by the `assertOpen` backstop.
   - Rule: the close ensures an **active global lock for exactly month(E)**: it creates one unless one already exists. It stores that lock's id in `year_end_closes.period_lock_id`. `PeriodLockController::unlock` refuses that lock while the close is active: 422 "FY 2026-27 is closed. Reopen the year before unlocking March 2027." The `reopens()` rule in R8 stays for every other lock.
   - ACs: (a) with a global April 2027 lock in place before the close, the close still creates a global March 2027 lock, and unlocking April afterwards is 200 and reopens April only; (b) in the AC 37 setup, unlocking the global March lock is 422.
2. **MUST — Year result must reconcile to the I&E report before closing.** The amount moved to equity must equal the profit the business reports. The preview shows, for each branch, the I&E report net for S..E (`countedTransactions`) next to the ledger result N, and the difference. Add check **R8.5b**: if any branch's difference is not 0.00, the close returns 422 "The ledger result for KDY differs from the Income & Expense report by 100.00. Run ledger:verify." and posts nothing. AC: in the Y-dataset, the preview's I&E column equals the I&E report for 2026-04-01..2027-03-31 per branch (KDY +24,300.00, GAL −6,800.00, MAIN −10,000.00, total +7,500.00).
3. **MUST — Retained earnings is written only by the close.**
   - No posting path other than `postClose` may write a line on a `retained_earnings` account. Exclude the account type from every account picker, the transfer and capital paths, and `destinations`.
   - Add a third V8 check: "every line on a `retained_earnings` account has `origin = close`".
   - Without this check, V7's "32000 = −Σ total_net" does not prove anything.
4. **MUST — No exemption bypasses a closed year.** The private back-fill path skips `assertOpen`. Today it may still post in a locked month. It must refuse any line dated ≤ E of an active close: "FY 2026-27 is closed." Otherwise a back-fill re-run would break V7. `postClose` is the only writer allowed in a closed year, and only for the close being made or reopened. AC: a back-fill re-run on an unbalanced transaction dated 2027-02-10 after the close stops and names it. It adds no line.
5. **MUST — Compensating controls for close and reopen (see Q-A5).**
   - On `year.closed` and `year.reopened`, queue a notification to **every** active Super Admin, the actor included. `AccessAlerts` skips Super Admin actors, so this needs a separate notification class. The notification gives the year, the actor, the time, the total result and, on a reopen, the reason.
   - The Year-end screen keeps the full history per year: every close and reopen row, with who, when and the reason.
   - A trial balance for a year that has been reopened and not closed again shows a red banner: "FY 2026-27 was reopened on <date> by <name>: <reason>."
   - The trial balance PDF of a year that was closed again lists "Reopened N time(s)" in its header.
6. **MUST — Per-branch audit of a reopen.** `year.reopened` has no branch, so KDY's auditors would see the closing transaction become void with no reason. For each closing transaction, also write `transaction.year_end_reopened`, with `branch_id` = its branch, old `{status: posted}` and new `{status: void, reason, contra_line_ids}`. Add this row to the "Year-end" audit group and to AC 43.
7. **SHOULD — Make V8 independent of timestamps.** "Created after that year's `closed_at`" gives false hits if `closed_at` is taken before the lines are written. Rule: for each **active** close, every line dated in [S, E] has `created_at ≤ closed_at`, **or** belongs to one of that close's own transactions (ids in `summary`). Set `closed_at` after the closing lines are written, in the same DB transaction. Only active closes are checked, so a reopen's contras need no special exception.
8. **SHOULD — V7 also checks each close's `summary` against the ledger.** For each active close and branch B, the close lines on HO in B (non-main) or on 32000 (MAIN's own result) equal −N(B) as stored in `summary`, and V4 still holds after the close.
9. **SHOULD — Concurrent reopen.** Reopen reads the `year_end_closes` row `FOR UPDATE` and re-checks `status = closed` and "latest closed year" inside the transaction. Two simultaneous reopens produce one set of 15 contras. The loser gets 422 "FY 2026-27 is not closed." Add this to AC 34.
10. **SHOULD — Reopen warning text (LKAS 8).** Add to the reopen dialog: "Reopen only before the accounts for this year are signed or filed. After that, record corrections in the current year." The system cannot know when the accounts are filed. The warning and the alert (finding 5) are the control.
11. **SHOULD — Previous-year rule (Q-A8).**
    - Change R8 check 3 to "the previous fiscal year is closed, **if it has any income or expense line**".
    - A year with only balance-sheet lines (capital, opening allocations) has nothing to close. S5 shows it with the status "No result to close". It does not block the next close, and it is covered by the cumulative lock.
    - Update the AC 29 example: a capital line dated 2026-03-15 does **not** block; an expense line dated 2026-03-15 does.
12. **SHOULD — Printed year-end evidence.**
    - The preview print and the trial balance PDF state their basis in the title: "Trial balance — pre-closing" or "Trial balance — post-closing".
    - Both PDFs end with the Prepared by / Checked by / Approved by block.
    - The preview print also shows the I&E reconciliation from finding 2.
13. **SHOULD — Notation in R7.** Replace the MAIN row with two rows: "MAIN's own N > 0: Cr 32000 N"; "MAIN's own N < 0: Dr 32000 |N|". Also state that the MAIN mirror lines (BCA(B) / 32000) sit in **B's** closing transaction. So the KDY close has MAIN lines, and MAIN-YEC exists only when MAIN has its own income or expense. Section 7 already shows this.
14. **SHOULD — Opening retained earnings at go-live (follow-up).** Profits made before go-live are in Owner's capital, because opening money was booked as capital. For a (Pvt) Ltd, the Companies Act separates stated capital from retained earnings, so the system's equity will not match the statutory balance sheet without an outside reconciliation. Add this to the "Manual journals + liabilities" backlog item (double-entry finding 12): an opening Retained earnings journal (Dr Owner's capital, Cr 32000). Until then, add to the S6 basis line: "Retained earnings excludes results before go-live."
15. **SHOULD — Transactions list totals.** The closing transaction's `amount` (total closing debits) is not money moved. Exclude `year_end_close` from any total or sum on the Transactions list and its export. Show the amount as "—", with the lines on the detail.
16. **Fine as written (no change):**
    - The closing postings in R7: the side rules for b, income and expense accounts cleared from all lines dated S..E (all origins and statuses), the result taken through HO in B and mirrored on BCA(B) / 32000 in MAIN, and money accounts never touched.
    - **Each branch balances on its own:** every closing set is balanced per branch (L1, V1).
    - **Consolidation:** the HO/BCA mirror keeps V4. Eliminations stay at 0.00, there is one Retained earnings line, and the virtual row is summed to one line. Consolidated totals are unchanged by the close (AC 27).
    - **Idempotency:** the `active_key` unique index plus the branch row locks, and the "already closed" 422.
    - **Reopen by contra** dated E, with the closing transactions set to `void`, is right. Unlike an ordinary correction, the reversal of a close belongs in the same period as the close, and nothing is deleted.
    - **Trial balance R6:** the I&E reset at S, the virtual row "Result of earlier years not yet closed", which uses all I&E lines before S including earlier closes, so it shows only unclosed years, and the pre-closing exclusion of `origin = close` in [S, E]. The identity holds on every row.
    - **The other reports:** the I&E, Branch comparison, Transfers, Cash book and dashboard are unaffected (by type and money-account filters), and the Transfers reconciliation excludes the closing lines.
    - **V5 and V7:** V5 changed to exclude `origin = close`, and V7 checks that income and expense net to zero per account. Both are correct.
    - **Audit:** the R12 events, with findings 5 and 6 added.

### Answers to the BA's questions
- **Q-A1 — Agreed: the branch result goes to the Head office current account; 32000 exists in MAIN only.** The business is one legal entity, so a branch has no equity (double-entry Q3/Q10). A branch's accumulated result is part of head office's net investment in it. This is the textbook head-office/branch treatment, and consolidation then shows one Retained earnings line without summing equity across branches. A 32000 in every branch would put equity outside MAIN and make "branch equity" look like something real. Branch funding is still visible: the closing lines carry `origin = close` and are excluded from the Transfers reconciliation.
- **Q-A2 — "Retained earnings", one account, no "Current year profit" account.** "Retained earnings" is the LKAS 1 / SLFRS term for a company. "Accumulated surplus/deficit" is used by not-for-profit and public-sector bodies. A loss balance stays in the same account and shows as a debit. The current year's result is derived from the open income and expense accounts, so a separate account would only need its own close.
- **Q-A3 — Yes, "locked through March for every branch" is enough.** Locks are cumulative, so all 12 months are closed. A lock row per month adds nothing. Monthly review is a process, and its evidence is the signed monthly trial balance (double-entry F8), not a system precondition. Finding 1 makes the close keep its own global March lock.
- **Q-A4 — One close for all branches, all or nothing.** The entity has one financial year and one set of statutory accounts, and MAIN's Retained earnings and BCA accounts take part in every branch's close. If one branch's March is disputed, do not close. Close when it is settled, or close and reopen later (R11).
- **Q-A5 — No second person in R1; the reason plus compensating controls instead (finding 5).** A small business often has one Super Admin, so a two-person rule could block the year end. A reopen on its own changes no balance-sheet figure and no month; unlocking is a separate, audited step. Required: the reason, alerts to every Super Admin, a visible banner, the full history, and the per-branch audit (finding 6). Add a backlog note: when the R2 approval framework (AG-051) exists, make reopening approvable by a second Super Admin.
- **Q-A6 — The default is pre-closing.** The pre-closing (adjusted) trial balance at E is the one I sign: the income statement is prepared from it. The post-closing view checks that only balance-sheet accounts carry forward. The PDF must say which one it is (finding 12).
- **Q-A7 — One fiscal year only.** A trial balance is "as at" a date for one year's results. Multi-year comparisons belong to the I&E and Branch comparison reports, which keep their ranges.
- **Q-A8 — Opening balances at go-live are balance-sheet only** (capital, opening allocations, funding). They need no FY 2025-26 close (finding 11). Any income or expense dated before 1 April 2026 is a result of a year whose accounts were prepared outside the system. Do not enter it. If it exists, FY 2025-26 must be closed first, and its result must agree with those accounts. Before go-live, run a one-off query for income or expense lines dated before 2026-04-01; the expected count is 0.

### Product-owner defaults
- **Year-end close built in this feature:** agreed. It must be in production before the first trial balance of FY 2027-28 (April 2027).
- **Currency changeable only before the first ledger entry; decimals fixed at 2:** agreed. LKR has 2 decimals, and changing the code with history would misstate every amount.
- **Approval thresholds deferred to AG-051/AG-064:** agreed. A stored threshold that nothing enforces would look like a control that does not exist.
- **No US date format:** agreed. 05/10 would be misread; DD/MM/YYYY is the Sri Lankan convention.
- **Settings screen Super Admin only:** agreed. The fiscal year start and the currency change how every report reads. I also recommend keeping the April start: it matches the Inland Revenue year of assessment (1 April to 31 March).
- **No logo on the login page:** no accounting effect; agreed.
- **No UI for `expense_negative_balance`:** agreed. If it is added later, the change must be audited as `settings.updated` like the other keys.

**Verdict:** ready for development once findings 1–6 (MUST) are added to the spec body and ACs. The SHOULD items can be added at the same time.
