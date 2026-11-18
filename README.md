# Account-gold

Multi-branch accounting: a Main branch funds its branches, branches transfer money between each other,
every income and expense is recorded against a two-level category tree, and a Super Admin sees everything.

| Folder | What |
|---|---|
| `api/` | Laravel 11 REST API (PHP 8.3, MySQL 8, Sanctum, spatie/laravel-permission with teams = branches) |
| `web/` | Angular 18 single-page app (standalone components, signals) |
| `docs/` | Project document (PDF) and product backlog (Excel) |

## Run it locally

Requirements: PHP 8.3 + Composer, Node 22, Docker (for MySQL) or your own MySQL 8.

```bash
docker compose up -d                      # MySQL on :3306, Mailpit on :8025

cd api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed                # Main branch, Main Account, permissions, role templates, categories, Super Admin
php artisan serve                         # http://localhost:8000
php artisan queue:work                    # sends invitation emails and access alerts (or set QUEUE_CONNECTION=sync)

cd ../web
npm install
npx ng serve                              # http://localhost:4200 (proxies /api to :8000)
```

Sign in with `admin@accountgold.local` / `ChangeMe123` (set `ADMIN_EMAIL` / `ADMIN_PASSWORD` in `api/.env` before seeding).

In production, run `php artisan queue:work` under a process manager (e.g. Supervisor); without it invitation emails are never sent.

Tests: `cd api && php artisan test` (runs on in-memory SQLite).

Uploading attachments locally: PHP's default `upload_max_filesize = 2M` refuses most 5 MB files. `php -d
upload_max_filesize=6M artisan serve` does **not** work: `artisan serve` starts a child PHP process that does not
inherit `-d` options. Set the limits in the `php.ini` that `php --ini` shows, or put a file such as
`uploads.ini` (`upload_max_filesize = 6M` and `post_max_size = 8M`) in a folder and start the server with
`PHP_INI_SCAN_DIR=:/path/to/folder php artisan serve` (the leading `:` keeps the default scan dir).

### Deploying the controls release (void with contra entries, audit log, period locks)

The migration `2026_11_20_000000_accounting_controls` adds database triggers that refuse UPDATE/DELETE on
`ledger_entries` and `audit_logs`.

1. **MySQL privileges.** With binary logging on (the MySQL 8 default), `CREATE TRIGGER` needs the SUPER privilege
   unless the server has `log_bin_trust_function_creators = 1`. If the app's DB user is not an admin, set it first,
   e.g. `SET PERSIST log_bin_trust_function_creators = 1;` (or `log_bin_trust_function_creators=1` in `my.cnf`).
   The migration checks this before changing anything and stops with a clear message otherwise.
2. **Before the migration:** `php artisan ledger:verify`. It checks every `accounts.current_balance` against the
   ledger (exit code 1 on any difference) and lists voids made before this release ("legacy voids", whose ledger
   lines were deleted by the old code), flagging any without a `transaction.voided` audit row. The migration also
   stops before any change if `period_locks` holds duplicate locks for the same branch and month.
3. `php artisan migrate --force`
4. **After the migration:** run `php artisan ledger:verify` again; it must exit 0. The Super Admin reviews the
   legacy void list: where a report for that month was printed and signed before the void, re-print it and mark the
   old copy superseded. No ledger correction is made.
5. Rolling back is possible only while no lock has been unlocked (unlock history has two rows per branch and month).

### Deploying the attachments release (AG-062 receipts and invoices)

The migration `2026_11_21_000000_create_attachments_table` adds the `attachments` table with triggers that refuse
DELETE and any UPDATE other than the one-time removal (same MySQL trigger privilege as above). Files are stored on the
private `attachments` disk (`api/storage/app/attachments`, never under `public/`, no `storage:link`) and are only
streamed by the API after an access check.

1. **PHP upload limits.** Each file may be 5 MB. Set `upload_max_filesize = 6M` and `post_max_size = 8M` (or more) in
   the PHP-FPM `php.ini`; PHP's default 2M refuses most phone photos and scanned PDFs. Allow a request body of at least
   8 MB in the web server too (nginx `client_max_body_size 8m;`, Apache `LimitRequestBody 8388608`).
2. **Storage.** `storage/app/attachments` must exist and be writable by the PHP user (files are written read-only,
   `0440`). Do not serve it from the web server.
3. **Backups.** Back up `storage/app/attachments` **together with** the database, in the same backup run, so a
   restore never pairs attachment rows with older files. Rows and files are never deleted by the app; keep them at
   least as long as the statutory record period (confirm with the tax adviser; normally at least 5 years).
4. `php artisan migrate --force`, then **`php artisan attachments:verify`**. It checks that every attachment row
   (removed ones included) has its file on disk with the stored sha256, lists missing or changed files by entry ref
   and attachment id, and exits 1 on any problem. Run it after every deploy and after every backup restore, next to
   `php artisan ledger:verify`.
5. **Database user hardening.** The triggers stop DELETE and UPDATE, but `TRUNCATE TABLE` skips triggers (on
   `attachments`, `ledger_entries` and `audit_logs` alike). MySQL needs the DROP privilege for TRUNCATE, so the app's
   runtime DB user must not hold DROP (nor ALTER/TRIGGER): grant it `SELECT, INSERT, UPDATE, DELETE` on the schema and
   run migrations with a separate admin user.
6. Rolling back the migration is refused once any attachment exists (rows and files are evidence); restore a backup
   taken before the migration instead.

### Deploying the double-entry release (chart of accounts, balanced postings, trial balance)

The migrations `2026_11_22_000000_double_entry_schema` and `2026_11_22_000001_double_entry_backfill` add the chart of
accounts to `accounts` (codes, classes, category accounts, Owner's capital in MAIN, head office / branch current
accounts), a `ledger_entries_line_check` trigger (same MySQL trigger privilege as above) and the missing lines of every
existing entry, so each entry balances in total and per branch. Cash, bank and wallet balances do not change, and
neither do the existing reports. Take a database backup first: the back-fill cannot be rolled back once it has run.

1. **Before:** `php artisan ledger:verify`; it must pass. The schema migration also refuses to start (before any change)
   if a balance differs from the ledger, a line breaks the line rules, or an income/expense has no category.
2. **Dry run:** `php artisan ledger:backfill-double-entry --dry-run`. It works on the old schema and writes nothing. It
   lists the lines to add by type and branch, the locked months that get lines, and a sample of 20 entries. The
   accountant reviews and signs the output; keep it with the deploy record.
3. `php artisan down`, then `php artisan migrate --force` (chart and back-fill; one `ledger.double_entry_backfilled`
   audit row per run). If it stops half-way, fix the reported entry and run `php artisan ledger:backfill-double-entry`:
   it completes the rest and never adds lines twice.
4. **After:** `php artisan ledger:verify`; it must pass, including the new checks V1-V6 (every entry balanced, line
   rules, trial balance nets to zero, inter-branch pairs eliminate, category accounts agree with the Income &
   Expense report, back-fill complete).
5. `php artisan up`, only after step 4 passes. Then print the last month's trial balance (Reports > Trial balance,
   Consolidated) for the accountant.

## How it works

**Branch context.** The app sends the active branch in an `X-Branch-Id` header. Middleware checks the user belongs
to that branch, sets it as the permission "team" (so roles resolve per branch), and a `BranchScoped` global scope
filters branch-owned models, so a missing `where` can never leak another branch's data. Super Admin can send `all`
for the consolidated view.

**Roles.** Permissions are fixed in code (`database/seeders/PermissionSeeder.php`). Roles are created per branch
from those permissions. Roles with `branch_id = NULL` are global templates (Branch Admin, Accountant, Cashier, Viewer).
Nobody can grant a permission they don't hold.

**Money.** All postings go through `app/Services/LedgerService.php`:
each transaction writes full double entry against the branch's chart of accounts (balanced in total and per branch;
on a cash, bank or wallet account debit = money in, credit = money out) inside one DB transaction with
row locks on the accounts, updates the cached `accounts.current_balance`, and writes an audit log. Nothing is deleted:
income/expense entries are voided, transfers are reversed. Locked periods (`period_locks`) reject postings.
Amounts are handled as integer cents (`app/Support/Money.php`), never floats.

| Transaction type | Effect |
|---|---|
| `capital` | + Main account |
| `opening_allocation` / `fund_allocation` | Main account → branch account |
| `fund_return` | branch account → Main account |
| `branch_transfer` | branch A → branch B |
| `internal_transfer` | account → account, same branch |
| `income` / `expense` | + / − branch account, with category |
| `reversal` | exact opposite of the original |

## API (v1)

All under `/api/v1`, bearer token from `POST auth/login`, plus `X-Branch-Id`.

`GET me` · `GET dashboard` · `GET|POST branches` · `PUT branches/{id}` · `GET|POST accounts` · `PUT accounts/{id}` ·
`GET accounts/{id}/statement?from&to` · `GET accounts/destinations` · `GET|POST categories` · `PUT categories/{id}` ·
`GET transactions` · `GET|PUT transactions/{id}` · `POST transactions/{id}/void` · `POST transactions/{id}/reverse` ·
`POST incomes` · `POST expenses` · `POST capital` · `POST allocations` · `POST transfers` ·
`GET|POST transactions/{id}/attachments` · `GET transactions/{id}/attachments/{attachment}/download` ·
`POST transactions/{id}/attachments/{attachment}/remove` ·
`GET chart-of-accounts` · `GET reports/trial-balance` · `POST reports/trial-balance/export` ·
`GET permissions` · `GET|POST roles` · `PUT roles/{id}` · `GET|POST users` · `PUT users/{id}` · `POST users/{id}/role`

## Status against the backlog (docs/Account-gold_Product_Backlog.xlsx)

Done in this first cut (API + UI unless noted):
AG-001 login + lockout, AG-003 branch switcher, AG-004 permission checks, AG-010/011/012 branches,
AG-020/021/022/023/024 users and custom roles (API; UI screens next), AG-030/031/032 accounts and statement,
AG-040/041/043 opening balance, top-up, capital, AG-042 fund return, AG-050/053/054 transfers and reversal,
AG-060/061/063/065 income, expense, void, list and filters, AG-070/072 categories (API; tree editor UI next),
AG-080/081 dashboards, AG-090 audit log (writing; viewer UI next), AG-092 settings table, AG-091 period lock (enforced; UI next).

AG-062 attachments on income and expense entries (private files, soft removal, `attachments:verify`).

Next up: users and roles screens, category editor, reports AG-082 to AG-085 with PDF/Excel export,
password reset (AG-002), audit log viewer, then the R2 approval workflow.
