# Spec: Automated daily backups

## 1. Goal and scope
**AG-093** "As an operator, I want automated daily database backups so that data can be restored."
Backlog acceptance: nightly mysqldump (or spatie/laravel-backup) to off-server storage, 30-day retention; restore procedure documented and tested once per release.

Built on `feature/transaction-search`. Fixed decisions:
- **One backup = one encrypted archive** that holds the database dump **and** the private files (`attachments` and `branding` disks), written in the same run. The attachments and settings specs already require this ("backed up together, so a restore never pairs rows with older files").
- **Built in the app, no new composer package.** A `backup:run` command uses `mysqldump` (MySQL) or `VACUUM INTO` (SQLite dev) and PHP `ZipArchive` (AES-256). We need our own `backups` table, audit rows, alerts and restore test, so spatie/laravel-backup would add a package and still need most of this code (Q1).
- **Off-server copy** to an S3-compatible disk when one is configured. **The app never deletes off-server copies.** Off-server retention is a bucket lifecycle rule, so the app's key needs no delete right, and a compromised server cannot wipe the off-server backups.
- **Nothing in the app deletes a backup except local retention** (`backup:prune`). There is no API or screen action to delete, download, or start a backup.
- The Super Admin screen is **read-only status**. Restoring is an operator task on the server, done by following the README.

Out of scope: restoring from the UI, downloading from the UI (Q2), "Back up now" button (Q3), incremental or point-in-time (binlog) backups, backing up `.env` or code, heartbeat or uptime monitoring of cron itself (Q4), per-branch backups.

## 2. Personas and permissions
- **Super Admin** (`is_super_admin`): the only user who sees the Backups screen and API. They use the existing `super` middleware and `superAdminGuard`, in any switcher mode, because backups are global like Settings. They also receive the failure emails.
- **Operator** (server shell, not an app user): runs the commands, configures cron, the off-server bucket and the archive password, and does restores.
- No new permission key. Nothing goes in `PermissionSeeder`. Branch Admin, Accountant, Cashier and Viewer have no access (API 403 "Super Admin only.", web route redirected by the guard, no nav link).
- Audit rows are `branch_id = null` and are listed on the audit log screen under the new group **Backups**. That screen already needs `audit.view`. Branch users with `audit.view` see only their branch's rows, so in practice only Super Admins see backup rows.

## 3. User flows
**F1 Nightly backup (scheduler, no user).** At 01:30 Asia/Colombo, `backup:run` runs:
1. Lock.
2. Precheck.
3. Dump the DB.
4. Add the files.
5. Encrypt and zip.
6. Checksum and self-check.
7. Copy off-server.
8. Record the row and the audit row.
9. Run `backup:prune`.
On any failure, the row is `failed`, a `backup.failed` audit row is written, and every active Super Admin gets an email.

**F2 Super Admin checks backups.** Nav "Backups" (next to Settings) -> S1. They see a green line "Last successful backup: today 01:31 (3.2 GB), copied off-server." and the list. If last night failed, they see a red banner and the error on the row, and they also received the email.

**F3 Weekly restore test (scheduler).** Sunday 03:00 Asia/Colombo, `backup:verify --latest`:
1. Restore the newest successful backup into the scratch DB and a temp folder.
2. Run `ledger:verify` and `attachments:verify` against them.
3. Record Passed or Failed on the backup row.
Failures alert the Super Admins.

**F4 Release restore drill (operator, once per release; backlog AC).** On staging, follow README "Restoring a backup" end to end with the latest production backup. Then run `ledger:verify` and `attachments:verify`. The accountant compares the trial balance and the Main account balance with production as of the backup time. Record the result in the release notes.

**F5 Real restore (operator).** Follow README "Restoring a backup" (R10). Then run `php artisan backup:restored <filename> --reason="..."` so the audit trail shows that a restore happened and from which backup.

**F6 Before a risky deploy (operator).** Run `php artisan backup:run` by hand. It behaves the same as F1, with `triggered_by = console`.

## 4. Screens
### S1 Backups `/backups` (new, `superAdminGuard`; nav link "Backups" shown only to Super Admins, after "Settings")
**Status banner** (top, first matching rule wins, from `summary`):
| Condition | Style | Text |
|---|---|---|
| No `succeeded` backup ever | red | "No backup has completed yet. Check that cron runs `php artisan schedule:run` every minute." |
| Last `succeeded` older than 26 h | red | "The last successful backup was {age} ago ({date time}). Check the scheduler and the failure emails." |
| Off-server disk not configured | amber | "Backups are kept only on this server. Configure off-server storage (BACKUP_OFFSITE_DISK) before go-live." |
| Latest backup's off-server copy `failed` | amber | "Last night's backup was not copied off-server: {error}." |
| Archive password not set (non-production only) | amber | "Backups are not encrypted (BACKUP_ARCHIVE_PASSWORD is empty). Production refuses to run without it." |
| Last restore test `failed`, or none in 8 days | amber | "The last restore test failed on {date}." / "No restore test in the last 8 days." |
| otherwise | green | "Last successful backup: {date time} ({size}), {copied off-server / local only}." |

**Summary line** (under the banner): "Runs daily at 01:30 · Restore test Sundays 03:00 · Keeps 30 daily and 12 monthly backups on this server".

**Table** (newest first, 50 per page, filter Status: All / Succeeded / Failed):
| Column | Content |
|---|---|
| Started | `YYYY-MM-DD HH:mm` Asia/Colombo |
| Status | tag: Running (blue), Succeeded (green), Failed (red) |
| Duration | `m:ss`; blank while running |
| Size | archive size, KB/MB/GB with 1 decimal |
| Contents | "DB + 1,204 files" (attachments + branding file count) |
| SHA-256 | first 12 hex characters, monospaced, with a "Copy" button for the full 64; the title tooltip shows the full value |
| Off-server | Copied / Failed (tooltip: error) / Not configured |
| Kept as | Daily / Monthly |
| On this server | Yes / "Pruned {date}" |
| Restore test | Passed {date} / Failed {date} (tooltip: first error line) / — |
| By | Scheduler / Console |
- A failed row shows its `error` as a second line in red, wrapped and never truncated.
- No row actions. There are no download, delete or run buttons.
- Empty state: "No backups yet. The first backup runs tonight at 01:30. An operator can run `php artisan backup:run` now."
- Load error: the API `message` in `.alert`.
- Phone (< 760px): rows become cards. Line 1: date + status tag. Line 2: size · contents · duration. Line 3: off-server · kept as · restore test. Line 4: SHA-256 (12 chars + Copy). No horizontal scroll at 360px.

## 5. API
| Method, path | Guard | Request | Response |
|---|---|---|---|
| `GET /v1/backups` | `auth:sanctum` + `super` (in the same group as `settings`, outside `branch`) | `status` (optional, `succeeded`/`failed`), `page` | `{data:[Backup], meta:{pagination}, summary}` |

- `Backup` = `{id, filename, status, triggered_by, started_at, finished_at, duration_seconds, size_bytes, sha256, db_driver, encrypted, files_count, kept_as ('daily'|'monthly'), offsite_status ('copied'|'failed'|'not_configured'), offsite_error, local_pruned_at, verify_status (null|'passed'|'failed'), verified_at, verify_error, error}`. No paths, no disk names, no URLs.
- `summary` = `{last_success_at, offsite_configured, encrypted_configured, schedule:{backup:'01:30', verify:'Sun 03:00', timezone:'Asia/Colombo'}, retention:{daily:30, monthly:12, min_keep:7}, last_verify:{at, status}|null}`.
- Errors: 401 unauthenticated; 403 "Super Admin only." for everyone else.
- There are no POST, PUT or DELETE routes for backups.

**Schema (one migration)** `backups`:
- `id`, `filename` (string 100, unique), `status` (string 12: `running`/`succeeded`/`failed`), `triggered_by` (string 10: `scheduler`/`console`), `started_at`, `finished_at` (null), `size_bytes` (unsigned bigint null), `sha256` (char 64 null), `db_driver` (string 10), `encrypted` (bool), `files_count` (unsigned int null), `kept_as` (string 8 null), `offsite_status` (string 16 null), `offsite_error` (text null), `local_pruned_at` (timestamp null), `verify_status` (string 8 null), `verified_at` (null), `verify_error` (text null), `error` (text null), `manifest` (json null), timestamps.
- A DB trigger refuses DELETE on `backups`, as for `attachments` (MySQL `SIGNAL`, SQLite `RAISE(ABORT)`). The model's `deleting` throws `ImmutableRecordException`. Updates are allowed: the status changes as the run progresses.

**Config** `config/backup.php` (env in brackets, defaults shown):
- `time` (`BACKUP_TIME`, `01:30`), `verify_day_time` (`Sun 03:00`), `timezone` = `app.business_timezone`.
- `local_disk` = `backups`. New disk: local, root `storage_path('app/backups')`, private, `serve` false, `throw` true.
- `offsite_disk` (`BACKUP_OFFSITE_DISK`, empty = not configured). The value names a disk. New disk `backups_offsite`: driver `s3` with its own `BACKUP_S3_*` key, secret, region, bucket, endpoint and path-style settings, so that the main `AWS_*` key is not reused.
- `password` (`BACKUP_ARCHIVE_PASSWORD`, at least 20 characters).
- `keep_daily` (30), `keep_monthly` (12), `min_keep` (7).
- `verify_connection` = `backup_verify`. New connection in `config/database.php`: copy of `mysql` with `BACKUP_VERIFY_DB_DATABASE` (default `account_gold_verify`), and for SQLite a temp file.
- `mysqldump_path` (`BACKUP_MYSQLDUMP`, `mysqldump`) and `mysql_path` (`BACKUP_MYSQL`, `mysql`).

**Schedule** (`routes/console.php`, which today has only `inspire`):
- `Schedule::command('backup:run')->dailyAt(time)->timezone(tz)->withoutOverlapping(360)->onOneServer()`.
- `Schedule::command('backup:verify --latest')->weeklyOn(0, '03:00')->timezone(tz)->withoutOverlapping(360)`.
- README: the cron line `* * * * * cd /path/api && php artisan schedule:run >> /dev/null 2>&1`.

## 6. Business rules and edge cases
**R1 Run order and consistency** (`backup:run`):
1. Take a cache lock `backup:run` (6 h). If it is held -> "A backup is already running." exit 1, with no row and no alert.
2. Mark stale runs as failed: rows that are `running` with `started_at` more than 6 h ago -> `failed`, error "Interrupted: the run did not finish." Each gets an audit row and an alert.
3. Insert the row (`running`, filename `ag-backup-YYYYMMDD-HHMMSS.zip`, in the business timezone).
4. Precheck:
   - Password set (production: required, else fail "BACKUP_ARCHIVE_PASSWORD is not set.").
   - The DB is not `:memory:`.
   - Free space on the local disk is at least 2 × the last succeeded size (first run: 1 GB). Otherwise fail with "Not enough free disk space: {free} free, {need} needed."
5. **Dump the DB first, then copy files.** This order is consistent because attachment and logo files are written before their rows and are never deleted (removed or replaced files stay on disk). Every row in the snapshot therefore has its file. A file uploaded during the run may be in the archive without a row, which is harmless.
6. Build the archive in `storage/app/backup-temp/{uuid}/` (mode 0700). Then move it to the `backups` disk. The temp folder is deleted in `finally`, on success or failure.

**R2 Database dump.**
- **MySQL:** run `mysqldump --single-transaction --quick --routines --triggers --events --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 {db}` through Symfony `Process` (no shell), with stdout to `db.sql`.
  - Credentials go in a temp `--defaults-extra-file` (mode 0600, deleted after). They are never put on the command line, where `ps` would show them.
  - Strip `DEFINER=` clauses from the output, so that triggers restore under any user.
  - Non-zero exit or empty output -> fail with stage `dump` and the first 500 chars of stderr.
  - The DB user needs SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT (README).
- **SQLite (dev):** `VACUUM INTO '{temp}/db.sqlite'` (a consistent copy) instead of copying the file.
- **Other drivers:** fail "Backups support mysql and sqlite only."
- The dumper is an interface (`DatabaseDumper`) with `MySqlDumper` and `SqliteDumper`, so tests can bind a fake.
- The immutable tables (`ledger_entries`, `audit_logs`, `attachments`, `backups`) are dumped with their triggers. The dump creates each trigger after the table's data, so a restore does not trip them.

**R3 Archive contents** (`.zip`):
- `db.sql` or `db.sqlite`.
- `files/attachments/...` and `files/branding/...`: every file on those disks, paths kept.
- `manifest.json`:
  - `app_version`: git commit if available.
  - `created_at`.
  - `db_driver`.
  - `last_migration`.
  - `db_sha256`.
  - Row counts of `transactions`, `ledger_entries`, `accounts`, `attachments`, `audit_logs`, `users`, `branches`.
  - `files_count` and `files_bytes` per disk.
- Not included: the `local` and `public` disks, `.env`, logs, cache, sessions and code. The README says to keep `.env` (APP_KEY, the archive password) in the password manager.

**R4 Compression and encryption.**
- `ZipArchive`: `db.*` and `manifest.json` deflated; attachment and logo files stored without compression (`CM_STORE`), because JPG, PNG and PDF are already compressed. ZIP64 is used automatically above 4 GB.
- Every entry is encrypted with AES-256 (`EM_AES_256`) using `BACKUP_ARCHIVE_PASSWORD`. The password is separate from APP_KEY and must also be kept off the server.
- Entry names are not encrypted (zip format). They are only uuids and numeric ids.
- Outside production, an empty password is allowed: the archive is unencrypted, `encrypted = false`, and S1 shows the amber banner.
- Restore needs `7z x` (README). Plain `unzip` cannot read AES zips.

**R5 Checksums and self-check.**
- `sha256` of the whole archive is stored on the row and in a sidecar `{filename}.sha256` (`sha256sum` format) next to it, both locally and off-server.
- After writing, reopen the archive with `ZipArchive::CHECKCONS` and the password. Check that the entry count = 2 + files_count, and that `db.*` hashes to `manifest.db_sha256`. Any mismatch fails the run with stage `verify_archive`, and the archive is deleted (it was never a succeeded backup).

**R6 Off-server copy.**
- If `offsite_disk` is set, the archive goes to `daily/{filename}` with `writeStream`, followed by the sidecar.
- The first succeeded backup of a calendar month (business tz) is also written to `monthly/{filename}`.
- After upload, the remote size must equal the local size. Otherwise `offsite_status = failed`.
- An off-server failure **does not fail the backup** (the local archive is good): `status = succeeded`, `offsite_status = failed`, `offsite_error` set, plus an alert and an audit row.
- **The app never calls delete on the off-server disk.** README: give the key Put, Get and List only. Set the bucket lifecycle so that `daily/` expires after 31 days and `monthly/` after 366 days. Turn on versioning or object lock if the provider has it.

**R7 Local retention** (`backup:prune`, run at the end of every `backup:run`; can also be run alone):
- `kept_as = monthly` for the first succeeded backup of each calendar month, `daily` otherwise (set when the run succeeds).
- The local file of a succeeded backup is deleted when **all** of these hold:
  - (a) it is a daily older than 30 days, or a monthly older than 12 months, by `started_at`;
  - (b) it is not among the newest 7 succeeded backups (`min_keep`), so a stopped scheduler never prunes the last good copies;
  - (c) it has not already been pruned.
- Pruning deletes the archive and sidecar from the `backups` disk and sets `local_pruned_at`. The row is kept. Each pruned file writes `backup.pruned`.
- Failed runs keep no archive (R5), so nothing is pruned for them.
- `backup:prune` is the only code that calls `delete` on the `backups` disk. A test greps `app/` for this (AC 19).

**R8 Failure alerts.**
- New `BackupAlertNotification` (mail) is sent to every active Super Admin (`is_super_admin`, `status = active`), as in `AccessAlerts::notify`.
- Events that alert:
  - backup failed (any stage, including stale `running`);
  - off-server copy failed;
  - prune failed;
  - restore test failed.
- Subject "Account-gold backup FAILED: {stage}" or "Account-gold restore test FAILED". The body has the filename, time, stage, error (first 500 chars), and "See Backups in Account-gold or the server log."
- **Sent with `sendNow`, not queued.** The queue is the database, and a backup failure is often a database or disk problem, so a queued mail might never leave. If sending throws, `report($e)` is called, and the command still exits 1, so cron output and the log show it. Success sends no email.

**R9 Audit rows** (`AuditLog::record`, `user_id` null (shown as "System") for scheduler runs; `branch_id` null; auditable = the `Backup`; `client = ['ip' => null, 'user_agent' => 'artisan backup:run']`):
| Event | Label | new_values |
|---|---|---|
| `backup.created` | Backup created | `filename, size_bytes, sha256, db_driver, encrypted, files_count, kept_as, offsite_status, duration_seconds, triggered_by` |
| `backup.failed` | Backup failed | `filename, stage, error` |
| `backup.offsite_failed` | Backup not copied off-server | `filename, error` |
| `backup.pruned` | Backup pruned | `filename, started_at, kept_as, rule` (`daily_30` / `monthly_12`) |
| `backup.verified` | Restore test passed | `filename, duration_seconds, counts` |
| `backup.verify_failed` | Restore test failed | `filename, stage, error` |
| `backup.restored` | Backup restored | `filename, sha256, reason, restored_at, operator` (OS user) |
- Add the group `backups => [['backup.'], []]` to `AuditLogController::GROUPS`, and the labels above to `LABELS`.
- The `backup.created` row is written after the dump, so it appears in the next night's backup and not in its own.

**R10 Restore procedure** (README section "Restoring a backup"; the developer writes it, and the accountant checks it at sign-off):
1. `php artisan down`. Stop the queue worker and remove the cron line.
2. Pick the backup (S1 or `ls storage/app/backups`, or the bucket). Check `sha256sum -c {file}.sha256`.
3. `7z x -p{password} {file} -o/tmp/restore`.
4. Create an empty database. Load it with `mysql {db} < db.sql`, as a user with TRIGGER rights.
5. Replace `storage/app/attachments` and `storage/app/branding` with `files/attachments` and `files/branding`. Keep the old folders aside until step 9 passes.
6. If the code is newer than `manifest.last_migration`, run `php artisan migrate --force`.
7. `php artisan ledger:verify` and `php artisan attachments:verify`. Both must exit 0.
8. The accountant checks the trial balance and Main account balance against the last printed reports.
9. `php artisan backup:restored {filename} --reason="..."`. This writes the `backup.restored` audit row and re-creates the `backups` rows for newer archives still on the local disk, using their sidecar and manifest, so S1 lists them again.
10. Restore cron and the worker, then `php artisan up`. Tell the users: entries made after `{backup time}` are not in the system and must be entered again from the paper evidence.
- Restore never deletes anything in the backups folder.

**R11 Restore test** (`backup:verify {filename?} {--latest}`):
1. Pick the backup. Its local archive must exist; if it has been pruned locally, read `daily/` or `monthly/` from the off-server disk. Check the sha256 against the row.
2. Extract it to a temp folder (0700).
3. **Safety:** refuse with "The verify database must not be the live database." when the `backup_verify` connection points at the same host + database as the default connection (or the same SQLite file).
4. Drop all tables in the verify DB, then load `db.sql` with the `mysql` client, or copy `db.sqlite`.
5. Switch `database.default` to `backup_verify`, and point the `attachments` and `branding` disks at the extracted `files/` folders, for the rest of the process only.
6. Run `ledger:verify` and `attachments:verify`. Compare the row counts with the manifest.
7. Switch back, and record `verify_status`, `verified_at` and `verify_error` on the backup row in the **live** DB. Write the audit row and alert on failure.
8. Drop the verify tables and delete the temp folder in `finally`.
- Exit 0 on pass, 1 on fail.
- It never writes to the live database except the backup row and the audit row.

**R12 Edge cases.**
- Mail not configured: alerts go to the log mailer, and the command still exits 1 on failure (README go-live checklist: real mailer).
- No active Super Admin: no email; the audit row is still written.
- The clock jumps or two runs land in one second: the unique `filename` makes the second insert fail -> "A backup is already running." exit 1.
- The archive password changes: old archives need the old password. README: keep every password used, with the date it started.
- A deactivated Super Admin gets no email and cannot open S1.
- A branch user calling `GET /v1/backups` with any `X-Branch-Id` -> 403.

## 7. Acceptance criteria
Setup: users SA (Super Admin), SA2 (Super Admin, active), SA3 (Super Admin, deactivated), BA (Branch Admin KDY), ACC (Accountant KDY).
- SQLite **file** database (not `:memory:`) for backup tests.
- `Storage::fake('backups')`, `Storage::fake('backups_offsite')`, and the real `attachments` and `branding` disks pointed at a temp dir holding 3 attachment files with rows and 1 logo.
- `BACKUP_ARCHIVE_PASSWORD = 'test-password-1234567890'`. `Notification::fake()`. The test clock is frozen at 2026-11-18 01:30 Asia/Colombo.

**Run**
1. Given the setup, When `backup:run` runs, Then it exits 0, and one `backups` row has `status = succeeded`, `triggered_by = console`, `encrypted = true`, `files_count = 4`, a 64-hex `sha256` and `size_bytes > 0`. Also `backups/ag-backup-20261118-013000.zip` and `.zip.sha256` exist, and the sidecar holds the same sha256.
2. Given the archive from AC 1, When it is opened with `ZipArchive` and no password, Then reading `db.sqlite` fails. With the password, it reads, and the archive holds `db.sqlite`, `manifest.json`, 3 files under `files/attachments/` and 1 under `files/branding/`.
3. Given the archive, Then `manifest.json` has `db_sha256` equal to sha256(`db.sqlite`), row counts equal to the live counts for the 7 listed tables, and `files_count` per disk (3 and 1).
4. Given `sha256_file(archive)`, Then it equals `backups.sha256`.
5. Given the setup, When `backup:run` runs, Then one `backup.created` audit row exists with `user_id` null, `branch_id` null, and `new_values` with `filename`, `sha256` and `size_bytes` equal to the row.
6. Given an attachment file is added to the disk **after** the dump step (fake dumper hook), Then the run still succeeds, and every attachment row in the dumped DB has its file in the archive.
7. Given a backup is running (lock held), When `backup:run` runs again, Then it exits 1 with "A backup is already running.", no new row, and no notification.
8. Given a row `running` started 7 h ago, When `backup:run` runs, Then that row is `failed` with error "Interrupted: the run did not finish.", a `backup.failed` audit row exists, and SA and SA2 are notified.
9. Given `APP_ENV=production` and an empty password, When `backup:run` runs, Then the row is `failed` with "BACKUP_ARCHIVE_PASSWORD is not set.", there is no archive on the disk, and SA and SA2 are notified (SA3 is not). Given `APP_ENV=local` and an empty password, Then it succeeds with `encrypted = false`.
10. Given the DB is SQLite `:memory:`, When `backup:run` runs, Then the row is `failed` with stage `precheck`.
11. Given a fake MySQL dumper that exits 2 with stderr "Access denied", When `backup:run` runs, Then the row is `failed`, `error` contains "Access denied", `backup.failed` has `stage = dump`, `storage/app/backup-temp` is empty, and `backups` holds no zip.
12. Given the MySQL dumper (unit test on the built command), Then the arguments include `--single-transaction`, `--routines`, `--triggers`, `--events`, `--hex-blob` and `--defaults-extra-file=...`, and no argument contains the DB password. The defaults file is gone after the run.
13. Given dump output with `/*!50017 DEFINER=`u`@`%`*/`, Then the written `db.sql` has no `DEFINER=`.
14. Given the self-check finds a hash mismatch (fake), Then the row is `failed` with stage `verify_archive`, and the archive is not on the disk.
15. *(manual, staging MySQL)* Given MySQL 8 with data, When `backup:run` runs and the archive is restored per R10 into a new database, Then `ledger:verify` and `attachments:verify` exit 0, and `SHOW TRIGGERS` lists the same triggers as live (`ledger_entries_*`, `audit_logs_*`, `attachments_*`, `backups_no_delete`).

**Off-server**
16. Given `BACKUP_OFFSITE_DISK=backups_offsite`, When the first backup of November runs, Then `daily/{file}`, `daily/{file}.sha256` and `monthly/{file}` exist on the fake off-server disk, `offsite_status = copied`, and `kept_as = monthly`. The next day's run writes only to `daily/`, with `kept_as = daily`.
17. Given the off-server write throws, Then the row is `succeeded` with `offsite_status = failed` and `offsite_error` set, a `backup.offsite_failed` audit row exists, SA and SA2 are notified, and the exit code is 1.
18. Given no off-server disk is configured, Then `offsite_status = not_configured`, and the S1 banner is amber with the "kept only on this server" text.

**Retention**
19. Given that a code search of `app/` (test reads the files) for delete calls on the `backups` or `backups_offsite` disks is run, Then the only matches are in `BackupPruner`, and none touch `backups_offsite`.
20. Given 40 succeeded daily backups, one per day ending today (the first of each month is monthly), When `backup:prune` runs, Then dailies older than 30 days have no local file, have `local_pruned_at` set, and have one `backup.pruned` row each. Their rows still exist. Monthlies younger than 12 months are kept.
21. Given only 5 succeeded backups, all 60 days old, When `backup:prune` runs, Then nothing is pruned (`min_keep` 7).
22. Given a monthly backup 13 months old, and 7 newer succeeded backups, When `backup:prune` runs, Then it is pruned with `rule = monthly_12`.
23. Given any `Backup` row, When the model is deleted, or `DB::table('backups')->delete()` is run, Then it is refused (exception / trigger), and the row remains.

**Alerts and audit**
24. Given any failure in AC 8, 9, 11 or 17, Then `BackupAlertNotification` is sent with `sendNow`, not queued (assert with `Notification::assertSentTo`, and that `Queue::fake()` saw no notification job), to SA and SA2 only, with the stage in the subject.
25. Given mail sending throws, Then the command still records the row and audit, reports the exception, and exits 1.
26. Given the audit log screen, When SA filters by group "Backups", Then the `backup.*` rows show with the labels in R9 and "System" as the user.

**Restore test**
27. Given the backup from AC 1 and a `backup_verify` SQLite file different from the live one, When `backup:verify --latest` runs, Then it exits 0, the row has `verify_status = passed` and `verified_at` = now, and a `backup.verified` audit row exists. Live DB row counts are unchanged, and the temp folder is gone.
28. Given the archive file was changed by one byte, When `backup:verify {filename}` runs, Then it exits 1 with a sha256 mismatch error, `verify_status = failed`, `backup.verify_failed` is written, and SA and SA2 are notified.
29. Given an archive whose restored DB has a ledger account with a cached balance difference (fixture), When `backup:verify` runs, Then `ledger:verify` fails inside it, the row is `failed` with the first error line, and the command exits 1.
30. Given `backup_verify` points at the live database, When `backup:verify` runs, Then it exits 1 with "The verify database must not be the live database.", and nothing is dropped.
31. Given the local archive is pruned and the off-server `daily/` copy exists, When `backup:verify {filename}` runs, Then it reads the off-server copy and passes.
32. When `backup:restored ag-backup-20261118-013000.zip --reason="disk failure"` runs, Then a `backup.restored` audit row has that filename, its sha256 and the reason. Rows are re-created for newer archives on the local disk that lack a row (from their sidecar and manifest).

**Schedule**
33. Given `routes/console.php`, When `schedule:list` runs, Then `backup:run` shows daily at 01:30 Asia/Colombo, and `backup:verify --latest` shows Sundays at 03:00 Asia/Colombo.

**Screen and API**
34. Given SA, When `GET /v1/backups` is called with any or no `X-Branch-Id`, Then 200 with rows newest first, and no field contains a filesystem path, a disk name or a URL. `summary.retention` = `{daily:30, monthly:12, min_keep:7}`.
35. Given BA or ACC, When `GET /v1/backups` is called, Then 403 "Super Admin only.". In the web app, `/backups` redirects and the nav has no "Backups" link.
36. Given there are no rows, Then S1 shows the empty state text and a red "No backup has completed yet" banner.
37. Given the last succeeded backup is 27 h old, Then S1's banner is red and shows that age. Given it is 2 h old with off-server copied, Then the banner is green.
38. Given a failed row, Then S1 shows the Failed tag and the full error under the row. The SHA-256 cell shows 12 chars, and Copy puts all 64 on the clipboard.
39. Given S1 at 360px width, Then rows are cards, there is no horizontal scroll, and there are no download, delete or run controls anywhere on the page.
40. `GET /v1/backups?status=failed` returns only failed rows. Then `status=foo` -> 422.

## 8. Open questions (assumption made so work is not blocked)
- **Q1 spatie/laravel-backup instead of our own command?** Assumed no: we need our own row, audit, alerts, prune rules and restore test, and the dump flags are a few lines. Revisit if the code grows past about 400 lines.
- **Q2 Download from S1?** Assumed no. A stolen Super Admin session would otherwise get every branch's data in one file, and restoring needs server access anyway. If the accountant wants an extra copy outside the server, a later item can add a download that is audited and needs password re-entry.
- **Q3 "Back up now" button?** Assumed no. Operators run `backup:run` before deploys (F6).
- **Q4 Who notices if cron itself is dead?** No code runs to send an alert. Assumed: the red S1 banner after 26 h, plus the go-live checklist item "schedule:run in cron". An external heartbeat ping (`BACKUP_HEARTBEAT_URL`) is a cheap follow-up if ops wants it.
- **Q5 Retention.** The backlog says 30 days. We keep 30 daily plus 12 month-end (first of month) backups, so last year's closed months can still be restored. The accountant should confirm 12 months is enough, or ask for 7 years for statutory needs (that would be a bucket rule only).
- **Q6 Archive size.** Every night is a full copy of all attachments (up to 25 MB per entry). Assumed acceptable for R1. Incremental file backups are for a later release if `files_bytes` grows past about 20 GB.
- **Q7 Off-server provider and bucket region** (data residency for Sri Lankan financial records). Ops and the business decide. The code is provider-neutral (S3 API).
