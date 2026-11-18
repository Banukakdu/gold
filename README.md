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
php artisan queue:work                    # sends invitation, password reset and alert emails (or set QUEUE_CONNECTION=sync)

cd ../web
npm install
npx ng serve                              # http://localhost:4200 (proxies /api to :8000)
```

Sign in with `admin@accountgold.local` / `ChangeMe123` (set `ADMIN_EMAIL` / `ADMIN_PASSWORD` in `api/.env` before seeding).
Replace that password with `php artisan auth:reset-password admin@accountgold.local` (see "Password reset" below).

In production, run `php artisan queue:work` under a process manager (e.g. Supervisor); without it invitation and
password reset emails are never sent.

Locally, reset and invitation links are written to `api/storage/logs/laravel.log` with the default `MAIL_MAILER=log`
(or see them in Mailpit on :8025 with `MAIL_MAILER=smtp`, `MAIL_PORT=1025`). Set `QUEUE_CONNECTION=sync` in `api/.env`
if you do not run a queue worker.

Tests: `cd api && php artisan test` (runs on in-memory SQLite; the backup tests use a SQLite file in the system temp folder).

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
   if a balance differs from the ledger, a line breaks the line rules, an income/expense has no category, or a chart
   code limit is exceeded (a branch id above 999, more than 999 cash/bank/wallet accounts in a branch, more than 99
   top-level categories of a type or 99 sub-categories under one parent).
2. **Dry run:** `php artisan ledger:backfill-double-entry --dry-run`. It works on the old schema and writes nothing. It
   lists the lines to add by type and branch, the locked months that get lines, a sample of 20 entries and an
   estimated duration. The accountant reviews and signs the output; keep it with the deploy record.
3. `php artisan down`, then `php artisan migrate --force` (chart and back-fill; one `ledger.double_entry_backfilled`
   audit row per run). **Timing:** measured on MySQL 8.4 (Docker, default durability settings) with 19,080 old
   entries: 2 min 25 s for the back-fill (about 8 ms per entry; about 13 minutes per 100,000 entries). It commits every
   100 entries. Plan the maintenance window from the dry run's estimate, with a margin for a slower server.
   - **If the schema step fails** (`2026_11_22_000000_double_entry_schema` FAIL, e.g. a lost connection or a DDL
     error): MySQL has kept the columns and keys it had already added. Fix the cause and run `php artisan migrate
     --force` again; the migration adds only what is missing and replaces the trigger. Do not restore part of the
     schema by hand.
   - **If the back-fill step fails** (`2026_11_22_000001_double_entry_backfill` FAIL): keep the app down. Each stop
     writes a `ledger.double_entry_backfill_stopped` audit row with the error and what was committed before it. **Do
     not edit ledger, transaction or account rows by hand** to get past the error.
     - If the cause is outside the data (lost connection, lock timeout, disk full), fix it and run
       `php artisan migrate --force` again. It completes the rest, never adds lines twice, and records the migration.
     - If the error names an entry (for example one that does not balance), stop the deploy: restore the backup taken
       before step 3 and bring the old release back up, or escalate to the developer and the accountant with the
       error and the audit row.
     - `php artisan ledger:backfill-double-entry` does the same work for a supervised re-run (the app must be down;
       `--force` runs it while the app is up, with a weaker safety check). After it, still run
       `php artisan migrate --force` so that `2026_11_22_000001_double_entry_backfill` is recorded as run.
4. **After:** `php artisan ledger:verify`; it must pass, including the new checks V1-V6 (every entry balanced, line
   rules, trial balance nets to zero, inter-branch pairs eliminate, category accounts agree with the Income &
   Expense report, back-fill complete).
5. `php artisan up`, only after step 4 passes. Then print the last month's trial balance (Reports > Trial balance,
   Consolidated) for the accountant.

**Operations notes.**
- **Branch id limit.** Inter-branch account codes are `15` + the branch id in 3 digits, so branch ids must stay at or
  below 999; creating a branch with a higher id is refused. The limit is on the auto-increment id, not on the number of
  branches: failed inserts and MySQL settings such as `auto_increment_increment` > 1 (some replication setups) leave
  gaps and use ids up faster. Do not change `auto_increment_increment` or insert branches with explicit ids.
- **Chart sync.** Branches and categories get their chart accounts when they are created through the app. If one is
  inserted another way (SQL, a seeder or tinker without model events), run `php artisan ledger:chart-sync`: it adds
  the missing accounts and codes, never changes existing ones, and writes one `ledger.chart_created` audit row.

### Password reset (AG-002, docs/specs/password-reset.md)

"Forgot password?" on the sign-in page emails a link that works for 60 minutes and once. A reset signs the user out on
every device and emails them "Your password was changed".

1. **`FRONTEND_URL`** in `api/.env` must be the public address of the web app (e.g. `https://gold.example.lk`). Reset
   and invitation links are built only from it, never from the request's Host header. The queue worker must run with
   the same `.env`.
2. **Queue worker and mailer.** The reset request, the link email and the alerts are queued: run `php artisan
   queue:work` under a process manager and configure a real mailer. Production must **not** use `MAIL_MAILER=log`,
   because that writes the plain reset link to `laravel.log`. Queued payloads in `jobs` / `failed_jobs` hold the link
   until it expires; never show `failed_jobs` in an admin screen.
3. **Super Admin switch.** `AUTH_SUPER_ADMIN_EMAIL_RESET=false` (the default) means a Super Admin cannot reset by
   email: the request gets the usual answer, but no link is sent, the refusal is audited and the other Super Admins
   get an alert. Keep it off until 2FA (AG-005). A business with at least two active Super Admins may set it to
   `true`; even then a Super Admin who is the only active one gets no link.
4. **Recovery command.** Someone with server access runs
   ```bash
   php artisan auth:reset-password user@example.com
   ```
   It asks for the new password twice (hidden), applies the password policy (8+ characters, a letter and a number),
   signs the user out everywhere, removes any pending reset or invitation link, clears login lockouts for the email,
   writes an `auth.password_reset` audit row (`via: console`) and emails the user (and the Super Admins when the user
   is a Super Admin or branch administrator). It is the way to recover a Super Admin while the switch is off, and the
   way the seeded Super Admin replaces `ADMIN_PASSWORD`.
5. **Referrer-Policy.** Serve the web app with `Referrer-Policy: no-referrer`, so a reset or invitation link in the
   address bar is never sent to another site (nginx: `add_header Referrer-Policy "no-referrer" always;`, Apache:
   `Header always set Referrer-Policy "no-referrer"`). `index.html` carries the same policy as a meta tag.
6. Changing a user's email (Users screen) emails the **old** address and cancels any pending link for it.
7. Login lockout: 5 failed attempts from one IP within 15 minutes lock that email on that IP for 15 minutes from the
   5th failure. Attempts are counted before the password is checked, so parallel guesses cannot get past the limit.
   Sign-in, reset and set-password match the email exactly as stored (lower-case); an accented variant such as
   `ú@x.com`, which MySQL's collation would treat as `u@x.com`, is handled as an unknown email. Lockouts, logouts and every reset step are in the audit log (group "Logins", Super Admin only).

### Backups (AG-093, docs/specs/backups.md)

One backup is one AES-256 encrypted zip holding the database dump (`db.sql` from `mysqldump`, or `db.sqlite` in
development), `control.json` (debit and credit totals per branch and the trial balance at the dump), `manifest.json`
and every file on the `attachments` and `branding` disks. It is written to `api/storage/app/backups` with two plain
sidecars (`{file}.sha256` and `{file}.manifest.json`, counts and ids only) and copied off-server. The Super Admin sees
the status under **Backups** (read-only: there is no download, delete or "run" button anywhere in the app).

| Command | What |
|---|---|
| `php artisan backup:run` | Take a backup now (also scheduled daily at 01:30 Asia/Colombo). `--year-end[=2026]` takes the year-end snapshot of a closed fiscal year at once. |
| `php artisan backup:verify --latest` | Restore test into the scratch database (scheduled Sundays 03:00). Also `backup:verify {file}` and `--oldest-offsite` (first Sunday, 04:00). |
| `php artisan backup:prune` | Local retention: 30 daily, 12 first-of-month, year-end 13 months once copied off-server; never the newest 7. Runs after every backup. |
| `php artisan backup:restored {file} --reason="..."` | Record a restore (step 9 below). |
| `php artisan backup:offsite-check` | Go-live check of the bucket's versioning and object lock. |

**Setting it up (go-live checklist).**
1. **Cron:** `* * * * * cd /path/api && php artisan schedule:run >> /dev/null 2>&1`. Without it nothing runs; S1 turns red
   after 26 h.
2. **Tools on the server:** `mysqldump` and `mysql` (MySQL 8 client; paths in `BACKUP_MYSQLDUMP` / `BACKUP_MYSQL`), PHP
   `zip` built with libzip AES support, and `7z` (p7zip) for restores. Plain `unzip` cannot read AES zips.
3. **Archive password:** `BACKUP_ARCHIVE_PASSWORD`, at least 20 characters, different from `APP_KEY`. Production refuses
   to back up without it. Keep it, with the date it started, in the business password manager, held by **two** people
   (the operator, and the owner or a director). When it changes, add the old one to `BACKUP_ARCHIVE_OLD_PASSWORDS`
   (comma-separated) so restore tests of old archives still open. Keep `.env` (APP_KEY, this password) there too: it
   is not in the backup.
4. **Database user for the dump:** `SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT` on the schema. Every table must be
   InnoDB (the run fails at `precheck` naming any other). The DB user for a restore needs TRIGGER rights (see the
   controls release above about `log_bin_trust_function_creators`).
5. **Scratch database for restore tests:** create an empty `account_gold_verify` (or set `BACKUP_VERIFY_DB_DATABASE`,
   and optionally `BACKUP_VERIFY_DB_USERNAME` / `_PASSWORD` / `_HOST` / `_PORT`). Its user needs all rights on that
   schema only, with the same access restrictions as the live database. `backup:verify` refuses to run if it points at
   the live database, and drops its tables when it finishes.
6. **Off-server storage** (`BACKUP_OFFSITE_DISK=backups_offsite`, with `BACKUP_S3_KEY`, `_SECRET`, `_REGION`,
   `_BUCKET`, `_ENDPOINT`, `_PATH_STYLE`; a key of its own, not the `AWS_*` one). Laravel's `s3` driver needs the
   Flysystem S3 adapter (`composer require league/flysystem-aws-s3-v3 "^3.0"`), which is not installed by default; any
   other Laravel disk that holds files off this server can be named instead. The bucket is set up by ops, not by the app:
   - give the key **Put, Get and List only** (no delete). The app never deletes off-server and never writes to a key
     that exists ("Off-server object already exists.");
   - **versioning on** (required);
   - **object lock in compliance mode** for `yearly/` (required) and recommended for the rest;
   - lifecycle rules: `daily/` expires after 31 days, `monthly/` after 366 days, `yearly/` after 2,922 days (8 years);
   - region and provider: a business and legal decision (data residency); records must be producible to the Inland
     Revenue on request. The root credentials of the storage account are held by the business, not the IT vendor alone.

   Then run `php artisan backup:offsite-check`: it reads the versioning, object-lock and lifecycle settings where the S3
   API allows it and exits 1 when versioning is off.
7. **Mail:** a real mailer. Failure alerts, the year-end snapshot hash and the restore notice are sent at once
   (`sendNow`), not through the queue.
8. **Disk space:** each run needs 2 × the last archive free (1 GB on the first run) on `storage/app`.
9. Run `php artisan backup:run`, then `php artisan backup:verify --latest`; both must exit 0. Open Backups in the app:
   the banner must be green.

**Year-end snapshot.** The first backup after a fiscal year is closed is kept as that year's snapshot (`yearly/FY2026-27/`
off-server, 8 years). It is restore-tested at once, and every Super Admin gets an email with its SHA-256 and the debit and
credit total: the accountant files it with the year-end pack.

**Continuity check.** Each run compares the live ledger and audit-log ids with the newest backup's manifest. If the database
went back in time without `backup:restored`, the run is marked failed (`continuity`), the archive is kept, and the Super
Admins are alerted.

**Segregation.** Whoever has shell access can change the books outside the app; the continuity check and the audit trail
detect it. The operator must not be a user who records or voids entries (Cashier or Accountant).

#### Restoring a backup

1. `php artisan down`. Stop the queue worker and remove the cron line.
2. Pick the backup (Backups screen, `ls storage/app/backups`, or the bucket: `daily/`, `monthly/`, `yearly/`). Check it:
   `sha256sum -c {file}.sha256`.
3. Extract into a fresh private folder, not `/tmp`:
   `install -d -m 700 storage/app/backup-temp/restore && 7z x -p'{password}' {file} -ostorage/app/backup-temp/restore`.
4. Create an empty database and load it as a user with TRIGGER rights: `mysql {db} < storage/app/backup-temp/restore/db.sql`.
5. Move `storage/app/attachments` and `storage/app/branding` aside (keep them until step 9 passes) and copy in
   `restore/files/attachments` and `restore/files/branding`.
6. If the code is newer than `manifest.json`'s `last_migration`, run `php artisan migrate --force`.
7. `php artisan ledger:verify` and `php artisan attachments:verify`. Both must exit 0.
8. The accountant compares the restored trial balance (Reports > Trial balance) with `restore/control.json`: every account
   balance and the per-branch debit and credit totals must be equal to the cent.
9. `php artisan backup:restored {file} --reason="..."`. It writes the `backup.restored` audit row, **signs everyone out**
   (all API tokens are revoked), emails the Super Admins, and re-lists newer archives still in `storage/app/backups`.
10. Before `up`: the Super Admin re-applies every user deactivation, role change, period lock and year-end close made after
    the backup time, using the access-alert and year-end emails received since then, and records them in the restore note.
11. Restore cron and the worker, `php artisan up`, and delete `storage/app/backup-temp/restore`. Tell the users: entries made
    after the backup time are not in the system and must be entered again from the paper evidence, with "Re-entered after
    restore of {file}; original ref {X}" in the description. The accountant reviews them against the vouchers before the
    month is locked.

Restoring never deletes anything in the backups folder.

**Release restore drill (once per release).** On staging, follow the steps above end to end with the latest production
backup, then `php artisan backup:verify {file}` (it repeats the control comparison automatically). Record the result in the
release notes.

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

All under `/api/v1`, bearer token from `POST auth/login`, plus `X-Branch-Id`. Public: `POST auth/login` ·
`POST auth/set-password` · `POST auth/forgot-password` · `POST auth/reset-password`.

`GET me` · `GET dashboard` · `GET|POST branches` · `PUT branches/{id}` · `GET|POST accounts` · `PUT accounts/{id}` ·
`GET accounts/{id}/statement?from&to` · `GET accounts/destinations` · `GET|POST categories` · `PUT categories/{id}` ·
`GET transactions` · `GET|PUT transactions/{id}` · `POST transactions/{id}/void` · `POST transactions/{id}/reverse` ·
`POST incomes` · `POST expenses` · `POST capital` · `POST allocations` · `POST transfers` ·
`GET|POST transactions/{id}/attachments` · `GET transactions/{id}/attachments/{attachment}/download` ·
`POST transactions/{id}/attachments/{attachment}/remove` ·
`GET chart-of-accounts` · `GET reports/trial-balance` · `POST reports/trial-balance/export` ·
`GET backups` (Super Admin) · `GET permissions` · `GET|POST roles` · `PUT roles/{id}` · `GET|POST users` · `PUT users/{id}` · `POST users/{id}/role`

## Status against the backlog (docs/Account-gold_Product_Backlog.xlsx)

Done in this first cut (API + UI unless noted):
AG-001 login + lockout, AG-002 password reset by email, AG-003 branch switcher, AG-004 permission checks, AG-010/011/012 branches,
AG-020/021/022/023/024 users and custom roles (API; UI screens next), AG-030/031/032 accounts and statement,
AG-040/041/043 opening balance, top-up, capital, AG-042 fund return, AG-050/053/054 transfers and reversal,
AG-060/061/063/065 income, expense, void, list and filters, AG-070/072 categories (API; tree editor UI next),
AG-080/081 dashboards, AG-090 audit log (writing; viewer UI next), AG-092 settings table, AG-091 period lock (enforced; UI next).

AG-062 attachments on income and expense entries (private files, soft removal, `attachments:verify`).

AG-093 automated daily backups (encrypted archive of DB + files, off-server copy, retention, weekly restore test, Backups screen).

Next up: users and roles screens, category editor, reports AG-082 to AG-085 with PDF/Excel export,
audit log viewer, then the R2 approval workflow.
