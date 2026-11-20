# Spec: Background exports

## 1. Goal and scope
**AG-087** "As a Super Admin, I want large report exports to run in the background so that the UI never times out."
Backlog acceptance: exports over 5,000 rows are queued; the user is notified with a download link when ready; links expire after 24 h.

Built on `feature/background-exports`. Fixed decisions:
- **Same buttons, same endpoints.** `POST /reports/{name}/export` and `POST /transactions/export` keep their paths and permissions. An Excel export of **5,000 rows or fewer** downloads at once, exactly as today. An Excel export of **5,001 to 100,000 rows** is queued and the endpoint returns **202** with an export record. Above 100,000 rows the request is refused (422). The row count is the one `ReportExporter::countRows` / the transaction query already compute.
- **PDF is never queued.** The PDF cap stays at **1,000 rows** (`ReportService::PDF_EXPORT_CAP`). dompdf holds the whole document in memory, so running it in a worker does not make a larger PDF safe. Above 1,000 rows the current 422 message ("... Use Excel or narrow the date range or branches.") stays.
- **Notification = the Exports page and an email.** There is a new Exports page (`/exports`), and the requester gets an email when the export is ready or has failed. The email links to the page, never to the file. The AG-094 hook is an `ExportFinished` event. AG-094 adds an in-app listener; nothing else changes.
- **Only the requester can download**, and only while signed in. Access is checked again at download. The file expires **24 h after it is ready**.
- Files are written to a new private disk, `exports`. It is set up like `attachments` (private, `serve => false`), is not part of the backup set, and only `exports:prune` deletes files from it.
- Rows in `exports` are never deleted. Only the files are removed when they expire.

Which exports are affected:
| Export | Async? | Why |
|---|---|---|
| Income & Expense, Transfers & Allocations, Daily Cash Book, Trial Balance (xlsx) | yes, when rows > 5,000 | detail rows can pass 5,000 |
| Branch Comparison (xlsx) | rule applies, never happens in practice | one row per branch |
| Cash Flow Summary (xlsx, pdf) | never | at most 24 rows |
| Transactions list (xlsx, AG-065) | yes, when rows > 5,000 | no date range limit, so it is the largest |
| Any PDF | never | dompdf memory (see above) |
| Audit log export (AG-090) | no (Q3) | not a report; stays 5,000, synchronous |

Out of scope: in-app bell and unread count (AG-094), scheduled or emailed report files (AG-088), PDF over 1,000 rows, cancelling a queued export, sharing an export with another user, the audit log export (Q3), and attaching the file to the email.

## 2. Personas and permissions
No new permission key, so nothing changes in `PermissionSeeder`.
| Persona | Can request async | Exports page |
|---|---|---|
| Super Admin (`is_super_admin`) | yes, in All or branch mode | yes |
| Branch Admin (`*`), Accountant (both hold `report.view` + `report.export`, and `transaction.view`) | yes, active branch only | yes |
| Viewer, Cashier (no `report.export`) | no (the export endpoint is already 403) | no nav link; the page shows only their own earlier exports, which they can no longer download (R6) |
| Custom role | as ticked | as above |

The backlog names the Super Admin, but the 5,000 cap applies to everyone who can export, so the same rule applies to every exporter (Q1).

The permissions required for each export are the ones its endpoint uses today: reports need `report.view` + `report.export`, and transactions need `transaction.view` + `report.export`.

## 3. User flows
**F1 Large export (Super Admin, All branches).**
1. Transactions, no filters (40,000 rows), then **Excel**.
2. The API counts 40,000 rows, which is over 5,000 and at most 100,000. It stores an `exports` row (status `queued`), writes the `export.requested` audit row, dispatches `RunExport` on the `exports` queue, and returns 202.
3. A blue notice under the button: "This export has 40,000 rows, so it is being prepared in the background. We will email you when it is ready. View exports".
4. The worker builds the file (R3, R4) and the row becomes `ready`. The `export.ready` audit row is written and the email is sent.
5. The user opens Exports, sees Ready with "Expires in 23 h", and clicks **Download**.
6. The file downloads and the `export.downloaded` audit row is written.

**F2 Branch user (KDY Accountant).** Same as F1. Branch scope is KDY because the request had `X-Branch-Id: KDY`. The job runs with KDY scope even if the user has switched to GAL in the meantime (R2).

**F3 Small export.** 4,000 rows downloads at once, as today. There is no `exports` row, and `report.exported` is written as today.

**F4 Failure.** The job fails (R9). The row becomes `failed` with a plain reason, and `export.failed` is written. The user gets a "could not be prepared" email, and the page shows the reason and **Run again**.

**F5 Expiry.** 24 h after ready, Download is replaced by "Expired" and **Run again**. The hourly `exports:prune` deletes the file and writes `export.expired`.

**F6 Run again.** This opens the original screen (report or Transactions) with the stored filters in the URL. The user presses Excel again, which is a new request. There is no new endpoint.

## 4. Screens
### S1 Export buttons (change `ag-export-buttons` and the Transactions Excel button)
- On 200: unchanged (blob download).
- On 202: the body is JSON. Read the blob as text and parse it. Show a blue info `.alert` under the buttons with the API `message` and a link "View exports" -> `/exports`. The alert stays until the filters change.
- On 422 and 429: show the API `message` in the existing red alert.
- While waiting: "Preparing..." (unchanged).

### S2 Exports `/exports` (new; guard: signed in. Nav link "Exports" under Reports, shown if Super Admin or `auth.can('report.export')`)
Title "Exports". Line under it: "Exports over 5,000 rows are prepared in the background. Files can be downloaded for 24 hours after they are ready."

Table (the user's own exports from the last 30 days, newest first, 25 per page; the same for a Super Admin):
| Column | Content |
|---|---|
| Requested | `YYYY-MM-DD HH:mm` (Asia/Colombo, date in the date format setting) |
| Export | Report title (`ReportExporter::TITLES`) or "Transactions" |
| Format | "Excel" |
| Branches | "All branches" / codes |
| Period | from to to, or "All dates" for transactions without dates |
| Rows | `12,345` (the final count when ready, otherwise the estimate) |
| Status | badge: Queued (grey), Preparing (blue), Ready (green), Failed (red), Expired (grey) |
| Data as at | `HH:mm` of the snapshot when ready (R3), else blank |
| Expires | Ready: "in 23 h" / "in 40 min"; else blank |
| Size | `3.4 MB` when ready |
| Actions | Ready: **Download**. Failed / Expired: **Run again** (F6). Failed: the reason below the badge. |

- **Polling:** while any row on the page is Queued or Preparing and the tab is visible, reload every 10 s. Stop when none are.
- **Download:** `GET /exports/{id}/download` as a blob, saved with the server's filename. Errors show the API `message` in an alert.
- **Deep link:** `/exports?id=<ulid>` (the email link) highlights that row. A user who is not signed in goes to login first and then comes back.
- **Empty:** "No background exports in the last 30 days."
- **Error:** the API message in `.alert`.

### Email (queued notifications, like `InvitationNotification`)
- **Ready.** Subject "Your export is ready". Body: "<Title> (Excel), <branches>, <period>, <rows> rows. Download it from Exports before <expires_at YYYY-MM-DD HH:mm> (Asia/Colombo)." Button "Open Exports" -> `APP_URL/exports?id=<ulid>`.
- **Failed.** Subject "Your export could not be prepared". Body: the same lines, the reason, and "Narrow the filters and run it again."
- Neither email contains figures or the file.
- Sent when `config('exports.email')` is true (the default).

## 5. API (all under `auth:sanctum` + `branch`)
| Method | Path | Notes |
|---|---|---|
| POST | `/v1/reports/{name}/export` | Changed. xlsx with rows > `exports.sync_rows` (5,000) and <= `exports.max_rows` (100,000) -> 202. Over max -> 422 "This export has N rows; the limit is 100,000. Narrow the date range or branches." PDF is unchanged. |
| POST | `/v1/transactions/export` | Changed in the same way. The over-max message is "... Narrow the filters." |
| GET | `/v1/exports` | The user's own exports, last 30 days, `?page=`, 25 per page |
| GET | `/v1/exports/{id}` | One export (own only, else 404) |
| GET | `/v1/exports/{id}/download` | Streams the file (R6) |

202 body: `{ "message": "This export has 12,345 rows, so it is being prepared in the background. We will email you when it is ready.", "export": {...} }`.

Export resource: `id` (ULID), `kind` (`report|transactions`), `name`, `title`, `format`, `branches_label`, `period_label`, `rows`, `status` (`queued|running|ready|failed|expired`), `error`, `requested_at`, `data_as_at`, `ready_at`, `expires_at`, `size_bytes`, `download_count`, `rerun_url` (web path + query). No file path, user id or params internals.

Download responses:
- 200: the file, `Content-Disposition: attachment; filename="<name>_<from>_<to>.xlsx"` (transactions: as today).
- 404: unknown id or someone else's export. This includes a Super Admin trying to download another user's export.
- 409: "This export is not ready yet."
- 410: "This export expired on <date time>. Run it again." or "This export failed and has no file."
- 403: "You no longer have access to this export."

Request errors added to both export endpoints:
- 429 "You already have 2 exports being prepared. Wait for one to finish."

**Table `exports`**:
- `id` ulid pk, `user_id`
- `branch_id` (nullable: the active branch at request, null = All mode)
- `kind`, `name`, `format`, `params` json (validated input), `branch_ids` json (resolved), `rows` int, `status`
- `error` (user-safe text), `failure_code`
- `file_path`, `size_bytes`, `sha256`
- `requested_at`, `started_at`, `data_as_at`, `ready_at`, `expires_at`, `downloaded_at`, `download_count`, `attempts`
- `ip`, `user_agent`
- Index on `(user_id, status)` and on `(status, expires_at)`.

**Queue:** a new connection `exports` (database driver, table `jobs`, queue `exports`, `retry_after` 900, `after_commit` true). The default connection's `retry_after` is 90 s, shorter than an export can take, so it would start a second copy of a running job.

**Worker (README + CLAUDE.md Commands):** `php artisan queue:work exports --queue=exports --timeout=600 --memory=1024`. Run it alongside the existing worker, so that emails are never stuck behind an export. Use one process.

**Config `config/exports.php`:** `sync_rows` 5000, `max_rows` 100000, `max_bytes` 50 MB, `ttl_hours` 24, `per_user_active` 2, `stale_minutes` 120, `email` true. Tests may lower these.

**Schedule (`routes/console.php`, after the backup lines):** `Schedule::command('exports:prune')->hourly()->withoutOverlapping(30)->onOneServer();`

## 6. Business rules and edge cases
- **R1 Threshold.** rows > 5,000 is queued; exactly 5,000 downloads at once. The decision uses the count at request time. Validation (dates, 366/31-day limits, branches, accounts) runs before counting, exactly as today, so an invalid request never creates a row.
- **R2 Scope and permissions captured at request.**
  - The row stores the active branch context: `branch_id` (null = Super Admin All mode), the validated params and the resolved branch ids.
  - At start, the job rebuilds `BranchContext` and the spatie team from the stored context. It then re-runs `ReportService::params` / `TransactionSearch::params` with the stored input. The job never reads a current header and never calls `withoutGlobalScopes()` beyond what the synchronous path does.
  - Before building, the job checks four things:
    - the user is `active`;
    - in All mode, the user is still Super Admin;
    - in branch mode, the user is still a member of that branch (`belongsToBranch`);
    - the user still holds the export's permissions in that branch team.
  - If any check fails, the job fails without a retry: `failure_code = access_revoked`, error "Your access changed after this export was requested."
- **R3 Snapshot.**
  - All reads for one file run inside a single read-only database transaction with a consistent snapshot (MySQL `REPEATABLE READ`, `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`; SQLite: one read transaction). This covers the count, the report data and the settings used in the header.
  - Totals, row lists and header therefore come from one moment. A posting or void that commits during the build is not in the file.
  - `data_as_at` is the time the snapshot opened, normally seconds after `requested_at`.
  - The header adds a line after "Printed by": "Data as at <YYYY-MM-DD HH:mm> (Asia/Colombo); requested <HH:mm>". If the period is not locked, the existing "PROVISIONAL - period not locked" line also applies.
  - A strict "as at the request time" is not possible, because void and reversal change `transactions.status` in place (Q2).
  - Updates to the `exports` row happen outside the snapshot transaction.
- **R4 Row count at run time.** The job recounts inside the snapshot.
  - Count > `max_rows`: fail, no retry, `over_limit`, "The export now has N rows; the limit is 100,000. Narrow the filters."
  - Count now <= 5,000: the export still completes in the background.
  - The stored `rows` becomes the final count.
- **R5 Building.**
  - Same builders as the sync path: `ReportExporter` (sheets + header), `TransactionExporter` (rows + header). The file content is identical apart from the R3 line. Typed cells, no formulas.
  - The "Printed by" name is the requester's.
  - The file is written to a temp name on the `exports` disk (`<user_id>/<ulid>.xlsx.part`) and renamed to `.xlsx` only after it succeeds.
  - Next, `size_bytes` and `sha256` are recorded, then status `ready`, `expires_at = ready_at + 24 h`, the audit row and the `ExportFinished` event.
  - The job raises `memory_limit` to 1024M like `raisePdfLimits` (it never lowers it). Excel rows should be generated in chunks (FromGenerator / chunked query) rather than one array of 100,000 rows. How is up to the developer, as long as AC 9 passes.
- **R6 Download.**
  - Only the requester can download (`user_id = auth id`); otherwise 404.
  - Status must be `ready` and `now < expires_at`. It is checked at the request, not only by prune.
  - Access is re-checked as in R2, against the export's stored branch context, not the current `X-Branch-Id`. A user who lost `report.export` or the branch gets 403.
  - The file's `sha256` must match. On a mismatch: 500 "This export file is damaged. Run it again.", the row is set to `failed`, and `report()` is called.
  - Repeat downloads within 24 h are allowed, and each one writes an audit row.
  - There is no signed public URL. A signed link that works without sign-in would let a forwarded email download financial data. The UI fetches with the user's token.
- **R7 Concurrency.** At most `per_user_active` (2) exports in `queued` or `running` per user; a third request gets 429, and no row is created.
  - An identical request (same user, kind, name, format, params and branch context) while one is queued or running returns 202 with the existing export. It creates no new row or audit row.
  - Across users, the single `exports` worker runs exports one at a time.
- **R8 Size limit.** If the finished file is bigger than `max_bytes` (50 MB), the file is deleted and the job fails without a retry: `too_large`, "The file is larger than 50 MB. Narrow the filters."
- **R9 Failures and retries.** `RunExport`: `$tries = 2`, `$backoff = 60`, `$timeout = 600`, `$failOnTimeout = true`.
  - An unexpected exception (deadlock, lost connection) retries once.
  - `access_revoked`, `over_limit` and `too_large` call `$this->fail()` (no retry).
  - A timeout fails at once (`timeout`, "The export took too long. Narrow the filters.").
  - `failed()` sets `failed`, deletes any `.part` file, writes `export.failed` (with `failure_code` and attempts) and fires `ExportFinished`.
  - Users never see the raw exception text: other errors read "The export could not be prepared. Try again later."
  - The job is idempotent: if the row is already `ready` or `failed` when the job starts, it does nothing.
- **R10 Stale and expired (`exports:prune`, hourly).**
  1. `ready` with `expires_at <= now`: delete the file, set `expired`, clear `file_path`, write `export.expired`.
  2. `queued` or `running` older than `stale_minutes` (120): set `failed` (`stale`, "The export did not run. Try again later."), delete any `.part` file, write `export.failed`. This covers a stopped worker.
  3. Files on the disk older than 25 h with no `ready` row: delete them (no audit; logged).
  - Prune is the only code that deletes from the `exports` disk.
- **R11 Audit rows.** All go in the audit screen's existing **Reports** group, with labels "Export requested / ready / failed / downloaded / expired".
  - `branch_id` follows the existing `report.exported` rule: the single branch when exactly one branch is in scope, else null.
  - `user_id` is the requester, with the request's IP and user agent passed as `$client` (as `SendPasswordResetLink` does), except `export.expired`, which has `user_id` null.
  - `new_values` holds `export_id`, `name`, `format`, `params`, `rows`, plus `size_bytes` and `sha256` (ready), `failure_code` and `attempts` (failed), and `download_count` (downloaded).
  - Async exports do not write `report.exported`. Sync exports still do, unchanged.
- **R12 AG-094 hook.** `ExportFinished($export)` is dispatched on ready and on failed. The email is sent by a listener for it. AG-094 adds a second listener (database notification); there is no other coupling.
- **R13 Branch and user edge cases.**
  - An inactive branch in scope is exported as today.
  - A user who is deactivated while their export is queued: the job fails with `access_revoked`, and no email is sent to an inactive user.
  - A Super Admin who is demoted: All-mode exports fail at run time and at download.
  - Changing the export's settings (company name, date format) after `ready` does not change the file.

## 7. Acceptance criteria
Fixture: Super Admin SA, and a KDY Accountant AK (also a GAL Viewer). Config `exports.sync_rows = 5`, `max_rows = 20`, `per_user_active = 2`. KDY has 8 posted incomes in October 2026 in 8 different sub-categories (so I&E has 8 lines) and 25 transactions in total. A fake clock is used where times matter. The `exports` queue is processed with `Queue::fake` or a sync worker, as the AC says.

**Threshold**
1. Given AK exports Transactions (KDY, October, 5 rows) to Excel, Then 200 with a file, `report.exported` is written, and no `exports` row exists.
2. Given AK exports Transactions filtered to 8 rows (KDY, October) to Excel, Then 202 with message "This export has 8 rows, so it is being prepared in the background. ...", an `exports` row (`queued`, `branch_id` KDY, rows 8), an `export.requested` audit row, and `RunExport` pushed on connection `exports`, queue `exports`. No `report.exported` row.
3. Given a request that would hold 21 rows, Then 422 "This export has 21 rows; the limit is 20. ...", and no row or audit is written.
4. Given an I&E export to PDF of 8 rows, Then 200 with the PDF at once and no `exports` row (PDF is never queued). Given 1,001 PDF rows, Then the existing PDF 422 message. A Cash Flow Excel export is never queued.
5. Given an invalid range (cash book over 31 days), Then 422 as today, and no `exports` row.

**Job and snapshot**
6. Given AC 2's export is processed, Then status `ready`, `data_as_at`/`ready_at` set, `expires_at = ready_at + 24 h`, and the file exists on the `exports` disk. Its sheet rows and totals equal the synchronous export of the same filters (run with `sync_rows` raised), apart from the "Data as at" header line. `export.ready` is written with `user_id` AK and the request's IP.
7. Given a test hook that posts a new KDY income after the job's snapshot opens and before it reads rows, Then the file contains neither the new row nor its amount in any total, and the header row count equals the data rows.
8. Given AK's export is queued, and AK switches to GAL before it runs, Then the file contains KDY rows only and the header reads "Branches: KDY ...".
9. Given `max_rows = 100000` and 100,000 KDY transactions (performance test, MySQL), When the job runs with `memory_limit` 1024M, Then it finishes within 600 s and the file has 100,000 data rows.
10. Given AK's KDY role loses `report.export` (or AK is removed from KDY, or deactivated) after the request, When the job runs, Then `failed` with code `access_revoked`, no file, a single attempt (no retry), and `export.failed` is written.
11. Given the data grew to 21 rows before the job runs, Then `failed` with `over_limit` and the message with 21. Given it shrank to 3 rows, Then `ready` with rows 3.
12. Given `max_bytes = 1 KB`, Then `failed` with `too_large`, and no file or `.part` file is left.
13. Given the builder throws a `QueryException` on the first attempt only, Then the job is retried once and ends `ready`, with `attempts` 2. Given it throws on both, Then `failed` with "The export could not be prepared. Try again later." (no exception text), and an email of failure is queued for AK.
14. Given a job for an export already `ready`, When it runs again, Then nothing changes and no second audit row is written.

**Download**
15. Given AC 6's export, When AK calls download, Then 200 with `filename="transactions_2026-10-01_2026-10-31.xlsx"`, bytes whose sha256 matches, `download_count` 1, and `export.downloaded` written. A second download gives 200, `download_count` 2, and a second audit row.
16. Given SA (or another KDY user) calls download for AK's export, Then 404. `GET /exports` as SA does not list it.
17. Given the export is `queued`, Then download returns 409 "This export is not ready yet.".
18. Given the clock is 24 h and 1 min after `ready_at` and prune has not run, Then download returns 410 "This export expired on ...". After `exports:prune`, the status is `expired`, the file is gone, `file_path` is null, the row still exists, and `export.expired` is written with `user_id` null.
19. Given AK loses `report.export` in KDY after the export is ready, When downloading (with any `X-Branch-Id`), Then 403 "You no longer have access to this export.". Given SA made an All-mode export and is no longer Super Admin, Then 403.
20. Given the file on disk was altered, Then download returns 500 "This export file is damaged. Run it again." and the row is `failed`.

**Concurrency and prune**
21. Given AK has 2 exports `queued`, When requesting a third (different filters), Then 429 "You already have 2 exports being prepared. Wait for one to finish." and no row is created. When repeating one of the queued requests exactly, Then 202 with that export's id and no new row or audit.
22. Given an export `queued` 121 minutes ago, When prune runs, Then `failed` with `stale` and `export.failed` written. Given a file without a ready row older than 25 h, Then it is deleted. Given one 1 h old, Then it is kept.
23. `php artisan schedule:list` shows `exports:prune` hourly.

**Notifications**
24. Given AC 6, Then one "Your export is ready" mail to AK is queued, with a link `APP_URL/exports?id=<ulid>`, the expiry time, no amounts and no attachment. With `exports.email = false`, Then no mail is sent, and the export still becomes ready.
25. Given a listener registered for `ExportFinished` in a test, Then it receives the export on ready and on failed.

**UI**
26. Given a 202 from Excel on the Transactions page, Then the blue notice shows the API message and a "View exports" link, and no file downloads.
27. Given `/exports` with one Queued row, Then it polls every 10 s, and the row changes to Ready with Download and "Expires in 23 h" without a reload. Polling stops when no rows are Queued or Preparing.
28. Given a Failed or Expired row, Then "Run again" opens the original screen with the stored filters in the URL. Given a Failed row, Then the reason shows under the badge.
29. Given a Viewer (no `report.export`), Then there is no "Exports" nav link. Given a Cashier who opens `/exports`, Then "No background exports in the last 30 days.".
30. Given the audit log screen filtered to the Reports group, Then rows "Export requested / ready / downloaded / expired / failed" appear for AC 2–22. A KDY user with `audit.view` sees KDY rows only.

## 8. Open questions (assumption in bold, so work is not blocked)
- **Q1 Who gets async exports.** The backlog says Super Admin. **Assumed: every user who can export**, because the 5,000 cap blocks branch users too and a per-persona rule would add complexity without protecting anything (scope and permissions are unchanged).
- **Q2 "As at request time".** A strict as-at-request report would need every report to filter by creation time and by status history, because void changes `status` in place. **Assumed: a consistent snapshot at job start (normally seconds after the request), with both times printed in the header.** In locked periods the two are identical. Accountant to confirm.
- **Q3 Audit log export.** **Assumed out of scope** (it is not a report and has its own query in `AuditLogController`). It can reuse the same `exports` mechanism later as a small follow-up.
- **Q4 PDF over 1,000 rows.** **Assumed still refused**, with the user directed to Excel. Raising the PDF cap needs a different renderer, which is not part of this item.
- **Q5 Email always on.** **Assumed: one global switch (`exports.email`)**, no per-user preference. Per-user notification preferences belong to AG-094.
- **Q6 Limits.** **Assumed: 100,000 rows, 50 MB, 2 active per user, 24 h after ready, list shows 30 days.** These are config values and can be changed without code.

## Accounting review
Reviewed against `feature/background-exports` as it stands: `LedgerService::void` (status set to `void` in place, contra entries dated on the **original** date, only while that month is open), `ReportService::provisional`, the "Printed by" header in `ReportExporter`/`TransactionExporter`, `report.exported` audit rows, `PermissionSeeder::TEMPLATES` and the attachments review (finding 2, integrity). No ledger, balance or double-entry effect: the job only reads, through the same builders, so the totals reconcile exactly as the synchronous export does (R5, AC 6). The private disk, re-checking access at download, requester-only download, no signed public link, no file or figures in the email, and rows that are never deleted are all right. Findings below.

**Answers to the open questions**
- **Q1 - Every user with `report.export`: Agreed.** Async exports give no new data and no new right. The same user can already pull the same rows in 5,000-row pieces, scope and permissions are unchanged, and exporting moves no money, so it creates no segregation-of-duties conflict. Restricting it to the Super Admin would only push branch accountants into many small exports, which are harder to reconcile and to audit. The Cashier and Viewer templates stay without `report.export` (fine as delivered in `PermissionSeeder`).
- **Q2 - Snapshot and "Data as at": the snapshot is right, the label is not. Print both times, with the snapshot time as the governing one, under a different label (finding 1).** In accounting, "as at" means the reporting cut-off date (for example "as at 31 October"). A line "Data as at 19:30", next to a period of October, invites the reader to take it as the cut-off. Also, the file does not show the data as it was at the request: a void committed between the request and the job start changes `status` in place, and its contra is backdated to the original date, so it is in the file and changes October's figures. The honest statement is "this file contains everything recorded up to time T", where T is the extraction time. A strict as-at-request report is not needed and not worth the cost: the books of record are the ledger, and a figure that must not move is produced after the month is locked. "In locked periods the two are identical" holds only while no unlock happens in between (an unlock is Super Admin only and audited), so it is fine as an assumption.
- **Q6 - Limits: Agreed** (finding 6), with the completeness check in finding 5.
- Q3, Q4, Q5: fine as assumed.

**Findings**
1. **MUST - Header wording for the snapshot (replaces the R3 header line and the S2 column label).**
   - The header line after "Printed by" reads: "Data extracted <YYYY-MM-DD HH:mm:ss> (Asia/Colombo). Includes every entry, void and reversal recorded before this time. Requested <YYYY-MM-DD HH:mm>." Both times carry the date, because a queued export can run after midnight (stale is 120 min). Seconds let the reader match the file against the audit log, which shows seconds.
   - `data_as_at` is taken immediately **before** the snapshot statement runs, so that "recorded before this time" is always true. The "Printed by ... at" time stays the build time, as in the sync file.
   - The term "as at" is not used for this time anywhere: the header, the S2 column ("Data extracted", showing `YYYY-MM-DD HH:mm`) or the email. The API field may keep the name `data_as_at`.
   - When the range is not fully locked, the existing "PROVISIONAL - period not locked" line stays, and nothing else is added. When it is fully locked, nothing else is added either.
   - Add to AC 6: "the header contains 'Data extracted <date time:ss>' equal to `data_as_at` and 'Requested <date time>' equal to `requested_at`". Add to AC 7: "Given a void of an October KDY income committed after the request and before the job starts, Then the file excludes that income from the totals and the extraction time is after the void's audit time."
2. **MUST - The snapshot must really be one snapshot (R3).**
   - Set the isolation level explicitly for that transaction (`SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` before `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`). Do not rely on the server default: under `READ COMMITTED`, the consistent-snapshot clause has no effect and totals and rows can come from different moments.
   - No locking read (`lockForUpdate`, `sharedLock`, `PeriodLock::lockedQuery(..., share: true)`) may run inside the export. A locking read in InnoDB reads the latest committed data, not the snapshot. The period-lock and settings reads used for the header and the PROVISIONAL line go through the snapshot too.
   - Chunked reads (R5) run on the same connection inside the same transaction, ordered by a unique key (for example `txn_date, id`), so no row is read twice or skipped between chunks.
   - Reviewer check plus AC 7. For SQLite tests, note that the read snapshot starts at the first read, not at `BEGIN`. The first statement in the transaction must therefore be the count.
3. **MUST - Audit the integrity failures, and record the download's own client.**
   - A sha256 mismatch at download (R6) writes `export.failed` with `failure_code = damaged` and `old_values.sha256` / `new_values.actual_sha256`. A `ready` row whose file is missing returns 410 "This export file is missing. Run it again." and writes `export.failed` with `failure_code = missing`. The Laravel log is not the audit trail (same rule as attachments finding 2).
   - `export.downloaded` records the IP and user agent of the **download** request, not those stored at request time (R11 currently says "the request's IP" for all rows).
   - Add to AC 20: "and one `export.failed` row with code `damaged` exists". New AC: "Given the file of a ready export is deleted from disk, When AK downloads, Then 410 and `export.failed` with code `missing`."
4. **MUST - Files must not be readable outside the controlled download.**
   - The download response sends `Cache-Control: no-store, private` and `X-Content-Type-Options: nosniff`, so no browser or proxy keeps a copy outside the 24 h control.
   - Files are written with mode `0600` (the `.part` file too). The `exports` disk root is outside `public/` and has no `storage:link`.
   - The `exports` disk is excluded from the backup set (fine as written). The Super Admin cannot download someone else's export (fine as written: oversight is through the audit log, not the file).
   - Add to AC 15: "the response has `Cache-Control: no-store`".
5. **MUST - A file must never look complete when it is not (completeness control for the chunked build).** Before renaming `.part` to `.xlsx`, the job checks that:
   - the number of data rows written equals the count taken inside the snapshot;
   - each total printed in the file equals the sum of the rows written for it (for the Transactions list: count and the in/out totals in the header).
   - On any difference, the job fails without a retry (`failure_code = incomplete`, "The export could not be prepared. Try again later.") and the `.part` file is deleted. A generator that ends early because of a memory or chunking bug must not produce a ready file that understates the totals.
   - New AC: "Given a test hook that makes the generator skip one chunk, Then `failed` with `incomplete`, no file and no `.part` file."
6. **SHOULD - Row limits: agreed.** 5,000 sync / 100,000 queued / 50 MB / 2 active per user, and PDF stays at 1,000. The recount at run time with fail-on-over-limit (R4) is right: it never truncates silently. Keep the limits in config as written. Changing them is an operational decision, not an accounting one.
7. **SHOULD - Make it clear who acted in the system rows.**
   - `export.ready`, `export.failed` (from the job or prune) and `export.expired` are system events. Add `new_values.by_system = true` to them. Keep `user_id` = requester on ready and failed, so that the existing user filter finds them. The audit screen shows "System" in the User column when `by_system` is true, so nobody reads them as the user acting at that time.
   - Add `requested_by` (user id) to `export.expired` (`user_id` null), so an expired export can still be traced to its requester.
   - Add `sha256` and `data_as_at` to every `export.downloaded` row. A file that turns up later outside the company can then be matched to the download, the person and the time.
   - Add `branch_ids` (resolved) to `export.requested`. In All mode `branch_id` is null, and `params` alone may say only "all".
8. **SHOULD - Audit a refused download of one's own export.** A 403 at download (access lost, R6) writes `export.download_denied` with the reason. Volume is tiny, and it is the one event that shows a removed user trying to get data out. Do not audit the 404 for someone else's id: it would be noise and would confirm nothing. Add it to the Reports group labels.
9. **SHOULD - Deduplicate only while `queued` (R7).** If an identical export is already `running`, its snapshot opened before the new request, so returning it would give the user data older than their request without saying so. In that case, create a new export (still subject to the 2-active limit). AC 21 uses `queued` and is unchanged. Add: "Given the identical export is `running`, Then a new row is created."
10. **SHOULD - 24 h retention: agreed, with one rule stated.** The export file is a convenience copy, not a book of record: the ledger is. It is reproducible from the ledger, so a short life and no backup copy is right, and fewer copies of financial data lying around is better control. 24 h from `ready` (not from request) is fair, and checking expiry at download as well as at prune is correct. Add one line to S2 and to the ready email: "Save the file if you need to keep it. A file run again later may differ if entries in an unlocked period were voided or changed." For month-end packs, the guidance stays to export after the month is locked, so that a re-run gives the same figures.
11. **SHOULD - Protect the `exports` rows like the other evidence tables.** Add a `BEFORE DELETE` trigger that always aborts, as for attachments. Add a `BEFORE UPDATE` trigger that blocks changes to `user_id`, `kind`, `name`, `params`, `branch_ids`, `branch_id` and `requested_at`, and blocks changes to `sha256` once it is set. The `exports` row and its `sha256` are what tie a file to a request.
12. **SHOULD - Same snapshot for the synchronous path.** Wrapping the existing sync export in the same read-only snapshot transaction (finding 2) is cheap, makes totals and rows consistent in small files too, and makes the AC 6 comparison exact. The sync header is otherwise unchanged.
13. Fine as written: permissions per export (section 2), re-checking access at job start and at download against the stored branch context (R2, R6), no `withoutGlobalScopes()` beyond the sync path, requester-only download with 404 for everyone else, the email with no figures and no attachment, no signed URL, the `ExportFinished` hook, rows never deleted, prune as the only code that deletes files, orphan clean-up at 25 h, and `report.exported` unchanged for sync exports.
