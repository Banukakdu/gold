# Spec: Scheduled email reports

## 1. Goal and scope
Backlog **AG-088** (E9 Reports & Dashboards, Could, R3, 3 points): "As a Super Admin, I want scheduled email reports (daily/weekly summary) so that I stay informed without logging in." AC: choose report, recipients, frequency; sent as PDF attachment via the queue. Project Document: R3 Enhancements, "scheduled email reports".

**A schedule only reads.** It posts nothing and never writes `transactions`, `ledger_entries` or `accounts.current_balance`. Every file is built by the existing report code, the same way as a PDF export.

Fixed decisions:
- **Reuse, no parallel machinery.**
  - Data and PDF: `ReportService::params` + `ReportExporter` (header, Blade PDF views, `countRows`, `raisePdfLimits`), read inside one `ReadSnapshot`, exactly as a synchronous PDF export. The header gets the AG-087 "Data extracted" line.
  - Queue: the build job runs on the AG-087 `exports` connection and queue (`retry_after` 900, the one exports worker). The emails go through the normal queue as queued notifications, like every other email.
  - Files: the AG-087 private `exports` disk (0600, not backed up). Only `exports:prune` deletes from it.
  - Owner alerts: AG-094 in-app notifications (bell), plus one email.
- **Reports offered:** Income & Expense (`income-expense`), Branch Comparison (`branch-comparison`), Transfers & Allocations (`transfers`), Daily Cash Book (`cash-book`). These are the four AG-082..085 reports, and all of them accept any day or week range.
- **Frequencies:** Daily, which covers the previous calendar day (D-1 in Asia/Colombo, every day of the week, weekends and holidays included; accounting review A1). Weekly, which covers the previous complete Monday-Sunday week. Times are Asia/Colombo (`BusinessDate`). Colombo is UTC+05:30 all year, with no daylight saving.
- **Who manages schedules:** Super Admin only, like the backlog persona. **No new permission key** (section 2).
- **Recipients are users of the system, never free email addresses.** Each recipient is checked **when the report is built and again when the email is sent** (R5). A recipient without access is skipped. Nobody ever gets a report narrowed to what they can see: the file is one file, the same for everyone who receives it.
- **Attach or link (R7):** the PDF is attached when it has at most 1,000 rows (`ReportService::PDF_EXPORT_CAP`) and at most 5 MB. Otherwise the email carries no file, only a link to the report screen, where the user must sign in.
- Nothing is hard-deleted. Schedules are paused or archived. Runs and deliveries are kept for ever, and the database refuses to delete them.

**Out of scope:**
- Monthly or custom frequencies, and several send times a day.
- Trial Balance, Cash Flow Summary (it needs whole months), Bank reconciliation and the Transactions list.
- Excel attachments. Report figures in the email body.
- External (non-user) addresses, CC/BCC, an unsubscribe link, or recipients managing their own subscriptions.
- "Send now" or a test send. Re-sending a past run.
- Downloading a past run's PDF from the app.
- Branch Admins managing schedules (Q1).
- "Skip when empty" and weekdays-only options.
- Bounce handling: "sent" means the mail server accepted the email (R9).

## 2. Personas and permissions
**No new permission key; `PermissionSeeder` is unchanged.** A new key such as `report.schedule` would be added automatically to every `*` (Branch Admin) role (`PermissionSeeder::run`). That would hand the right to send company data by email to every branch at once. The backlog asks for the Super Admin only.

| Persona | Manage schedules (S1-S3, API) | Can be a recipient (checked at build and at send, R5) |
|---|---|---|
| Super Admin (`is_super_admin`, active) | yes, every schedule, in any switcher mode | yes, any schedule |
| Branch Admin template (`*`) in branch X | no (403 "Super Admin only.", no menu) | only single-branch schedules of X: holds `report.view` + `report.export` in X |
| Accountant template in X | no | as Branch Admin (has `report.view` + `report.export`) |
| Viewer template | no | **no**: has `report.view` only, no `report.export` (Q4) |
| Cashier template | no | no |
| Custom role | no | when it holds `report.view` + `report.export` in that branch (active role) |
| Inactive user | no | never |

Why recipients need `report.export`: an emailed PDF is a file that leaves the system, which is exactly what `report.export` controls today. A user who cannot press "PDF" on the screen must not get the PDF by email.

Why multi-branch schedules go to Super Admins only: a non-Super Admin can only view one branch at a time (reports R1, branch mode). A multi-branch or All-branches file is something they cannot produce themselves.

## 3. User flows
**F1 Daily summary for one branch (Super Admin SA, any switcher mode).**
1. Reports > Scheduled reports > "New schedule".
2. Name "KDY daily income & expense". Report: Income & Expense, Type All. Branches: KDY. Frequency: Daily at 07:00.
3. Recipients: SA (ticked by default), KDY Accountant A. Perera.
4. The line under the form reads "Next email: Sat 21 Nov 2026 07:00, covering Fri 20 Nov 2026". Save. Toast "Schedule saved."
5. At 07:00 the next day, the dispatcher queues a run. The worker builds the PDF for 2026-11-20 in KDY branch mode. Each recipient gets an email with `income-expense_KDY_2026-11-20_2026-11-20.pdf` attached.

**F2 Weekly all-branches summary (SA).** Report Branch Comparison. Branches: none ticked (All branches). Weekly, Monday 07:00. Recipients: SA and SA2. The recipient picker offers only Super Admins (R5) and says so. The first email is sent Mon 23 Nov 2026 07:00 and covers 2026-11-16 to 2026-11-22.

**F3 Recipient loses access.** A. Perera's KDY role loses `report.export`. On the next run she is skipped (`missing_permission`). SA's bell shows "KDY daily income & expense: 1 recipient skipped". On S1 her name carries the tag "Cannot receive: no Export permission in KDY". She stays on the list. If she gets the permission back, she receives the next run.

**F4 Owner deactivated.** SA (the owner) is deactivated. The next run fails with `owner_lost_access`, no file is built, and the schedule is paused by the system. Every other active Super Admin gets a bell row and an email. SA2 opens the schedule and presses Resume, and becomes the owner (R3).

**F5 Report too large to attach.** The weekly cash book for KDY has 1,400 lines. No PDF is built. The email says it is too large to attach and links to `/reports/cash-book?from=...&to=...&branch=<KDY id>`. The recipient signs in and the screen opens in KDY (W1). From there they can use Excel, which is queued by AG-087 when it is large.

**F6 Failure.** The build fails twice (deadlock, then lost connection). The run is Failed. The owner gets the bell row and the email "Scheduled report KDY daily income & expense could not be sent". After 3 failed runs in a row, the schedule is paused by the system (R10).

**F7 Pause, resume, archive.** S1 row > Pause: no more runs. Resume: the next run is the next future time, and paused periods are not sent afterwards. Archive (optional reason): the schedule leaves the default list and cannot be edited. Restore puts it back as Paused.

**F8 Normal branch user.** There is no menu and no screen. A KDY Accountant who is a recipient only receives the emails. A Viewer cannot be added.

## 4. Screens
Nav: "Scheduled reports" (`class="subnav"`) under Reports, after Exports, shown when `auth.isSuperAdmin()`. Routes use `superAdminGuard` and work in every switcher mode; switching branch does not reload them. Times are shown as `colomboDateTime`, and dates use the date format setting (settings S3). Errors show the API `message` in `.alert`, and 422 field errors show under the field.

### S1 List `/scheduled-reports`
- Toolbar: "New schedule" · status tabs **Active & paused** (default) / Archived / All · search (name, client side).
- Table (sorted by name):

| Column | Content |
|---|---|
| Name | name; under it "Owner: <name>" |
| Report | title (`ReportExporter::TITLES`) + filter summary ("Type: Expense", "Types: Top-up, Fund return") |
| Branches | "All branches" or codes |
| Frequency | "Daily 07:00" / "Weekly, Monday 07:00" |
| Recipients | count; danger tag "N cannot receive" when R5 fails now for N (tooltip lists names + reason) |
| Next email | date time, or "-" when paused/archived |
| Last run | date time + badge: Sent (green), Partly sent (amber), Failed (red), Missed (red), Not delivered (red: none sent and at least one email failed), Nothing sent (grey: nobody could receive, R5); "Never" |
| Status | Active (green) / Paused (grey, tooltip reason) / Archived (grey) |
| Actions | Edit · Pause/Resume · Archive/Restore · History |

- Empty state: "No scheduled reports yet." and "Get a daily or weekly report by email as a PDF, without signing in."
- **390px:** stacked cards. Line 1 has the name and status badge. Line 2 has the report and branches. Line 3 has the frequency and next email. Line 4 has the last run badge and recipients count. The actions go in a "..." menu at least 44 px tall. There is no horizontal page scroll.

### S2 Form `/scheduled-reports/new`, `/scheduled-reports/:id/edit`
| Field | Type | Req | Validation |
|---|---|---|---|
| Name | text | yes | trimmed, 3-100 chars; unique among non-archived schedules, case-insensitive: "A schedule with this name already exists." |
| Report | select (4 reports, section 1) | yes | other values 422 "Choose one of the listed reports." |
| Type (I&E only) | All / Income / Expense | yes | default All |
| Movement types (Transfers only) | multi | no | `ReportService::MOVEMENT_TYPES`; none = all |
| Branches | multi-select from `GET /branches` (inactive tagged) | no | none = All branches, which includes branches added later (hint shown). Cash book: at least one, "Select at least one branch." Unknown id: "Unknown branch." |
| Frequency | radio Daily / Weekly | yes | |
| Day | select Monday..Sunday | weekly only | default Monday; required when weekly; must be empty (ignored) when daily |
| Time | select 00:00..23:30, 30-min steps | yes | default 07:00; other values 422 "Choose a time on the hour or half hour." |
| Recipients | checkbox list of active users (`GET /report-schedules/recipient-options`) | yes, 1-20 | R5. The owner is pre-ticked on New. Users who cannot receive are shown disabled, with the reason. |

- Live line (from the API preview fields, R2): "Next email: Sat 21 Nov 2026 07:00, covering Fri 20 Nov 2026". For weekly: "..., covering Mon 16 Nov 2026 - Sun 22 Nov 2026". There is also a link "Preview this report", which opens the report screen with that period and branches in a new tab (no new backend).
- Fixed note: "The report is emailed as a PDF to each recipient. An email cannot be recalled once sent. Recipients without access when it is sent are skipped."
- With more than one branch or none ticked: the note "Only Super Admins can receive reports covering more than one branch."
- Edit of an archived schedule redirects to S3 with the toast "Restore this schedule before editing it."
- **390px:** one column, full-width controls, and the Save button sticky at the bottom.

### S3 History `/scheduled-reports/:id`
- A summary card (all S1 fields, created by/at, last changed by/at). The recipients list shows tags "Inactive" / "Cannot receive: <reason>".
- Runs table (newest first, 25 per page): Planned (date time) · Period · Status (`queued`, `running`, `done`, `failed`, `missed`, shown as S1 badges) · Rows · Delivery ("PDF attached" / "Link only: too large" / "-") · Sent / Skipped / Failed counts · Data extracted (time) · reason (failed/missed).
- Expanding a row shows its deliveries: Recipient · Email used · Status (Queued, Sent, Skipped, Failed) · Reason (plain text, R5/R9) · Time.
- Empty state: "This schedule has not run yet."
- **390px:** runs as cards, with the deliveries inside the card.

### S4 Email to a recipient (`ScheduledReportMail`, queued)
- Subject: `<schedule name>: <report title>, <period label>`, for example "KDY daily income & expense: Income & Expense by Category, 20 Nov 2026".
- Body:
  - "<report title> for <branches label>, <period label>."
  - "Data extracted <date time> (Asia/Colombo)."
  - "PROVISIONAL - period not locked. Figures can still change." (only when the report is provisional)
  - "Sent late: this email was planned for <date time> (Asia/Colombo)." (only when the run started more than 60 minutes after `planned_at`; accounting review A5)
  - Then one of:
    - **attached:** "The report is attached as a PDF (<size>)."
    - **link:** "This report has <N> rows, which is too many to attach (limit 1,000 rows and 5 MB). Open it in Account-gold: <button>." (Over 5 MB with at most 1,000 rows: "This report is too large to attach (limit ...)". The limits shown are the configured ones.) The figures on screen are current, so the email adds "They may differ from the extracted time if entries in an unlocked period changed."
  - Footer: "You receive this because <owner> added you to the scheduled report "<name>". To stop it, ask a Super Admin. This email contains company financial information: do not forward it."
- No amounts, balances or names of accounts appear in the body. The words "as at" are not used.

### S5 Owner alerts
- **In-app (AG-094 bell)**, `branch = null`, `url = /scheduled-reports/<id>`. Kinds:
  - `report_schedule.run_failed`: "Scheduled report <name> could not be sent"
  - `report_schedule.missed`: "<name>: <n> scheduled emails were missed"
  - `report_schedule.recipients_skipped`: "<name>: <n> recipient(s) skipped"
  - `report_schedule.delivery_failed`: "<name>: email to <recipient> failed"
  - `report_schedule.paused`: "<name> was paused: <reason>". Sent to every active Super Admin (the owner included while they are one).
  - Not delivered (R15) uses the `report_schedule.run_failed` kind: "Scheduled report <name> was not delivered", body "No email of this run was confirmed as sent (<n> failed). An email that "may have been sent" must be checked with the recipient."
- **Owner no longer an active Super Admin** (QA D7): they cannot open the schedule, so the alerts meant for the owner go to the other active Super Admins instead; nothing goes to the former owner.
- **Email (queued `ReportScheduleProblemMail`)** only for run failed, missed, system pause and a run whose emails all failed ("not delivered", R15). Same text, a link to S3, no figures.

### W1 Report screens accept `?branch=<id|all>`
On load, if `branch` differs from the active branch: a Super Admin, or a member when the value is an id, is switched to it (the existing `switchBranch`), the param is dropped and the report loads. Otherwise the screen shows "You do not have access to <code>." when the branch is one the user can see, else "You do not have access to this branch." (the app must not reveal the code of a branch the user has no access to), and loads nothing. This is the only change to existing screens.

## 5. API
All routes are under `auth:sanctum` + `super` and **outside** `branch`, like `settings`: a schedule carries its own branch scope. A non-Super Admin gets 403 "Super Admin only." There is no DELETE route.

| Method, path | Request | Response / errors |
|---|---|---|
| `GET /v1/report-schedules` | `status=current\|archived\|all` (current = active + paused) | `{data:[schedule]}` |
| `GET /v1/report-schedules/{id}` | — | `schedule`; 404 "Schedule not found." |
| `POST /v1/report-schedules` | `name, report, params:{type?\|types?}, branches:int[], frequency:daily\|weekly, weekday:1-7\|null (ISO, 1 = Monday), send_time:"HH:mm", recipient_ids:int[]` | 201 `schedule`; 422 (S2 rules, R5, R11 "You can have at most 50 active schedules.") |
| `PUT /v1/report-schedules/{id}` | same fields | 200; 422 as POST; 422 "Restore this schedule before editing it." when archived |
| `POST /v1/report-schedules/{id}/pause` | — | 200; 422 "This schedule is not active." |
| `POST /v1/report-schedules/{id}/resume` | — | 200 (R3 owner change); 422 "This schedule is not paused." Before it resumes, the R5 check runs on the current list; if nobody can receive: 422 "None of the recipients can receive this report. Change the recipients first." |
| `POST /v1/report-schedules/{id}/archive` | `reason` (optional, max 500) | 200; 422 "This schedule is already archived." |
| `POST /v1/report-schedules/{id}/restore` | — | 200, status paused; 422 name clash (S2 message) |
| `GET /v1/report-schedules/{id}/runs` | `page` | `{data:[run with deliveries[]], meta}` |
| `GET /v1/report-schedules/recipient-options` | `branches[]` | `{data:[{id,name,email,is_super_admin,eligible:bool,reason:string\|null}]}`, active users only, by name |
| `GET /v1/report-schedules/preview-time` | `frequency, weekday, send_time` | `{next_run_at, period_from, period_to}` for S2's live line |

`schedule` resource:
- `id`, `name`, `report`, `title`, `params`, `branches:[{id,code}]` (empty = All), `branches_label`
- `frequency`, `weekday`, `send_time`, `status`, `paused_reason`
- `owner:{id,name}`, `created_by`, `created_at`, `updated_by`, `updated_at`, `archived_at/by/reason`
- `next_run_at`, `next_period:{from,to}`
- `recipients:[{id,name,email,status,eligible,reason}]`
- `last_run:{id,planned_at,status,sent,skipped,failed}|null`

`run` resource: `id`, `planned_at`, `period_from`, `period_to`, `status`, `failure_code`, `error`, `missed_count`, `rows`, `attached`, `size_bytes`, `data_as_at`, `finished_at`, `sent`, `skipped`, `failed`, `deliveries:[{user:{id,name}, email, status, reason, sent_at, failed_at}]`. The resource exposes no file path and no sha256.

**Schema** (new migration):
- `report_schedules`:
  - `id`, `name` (100), `report` (40), `params` json, `branch_ids` json (`[]` = All branches)
  - `frequency` (10), `weekday` tinyint null, `send_time` char(5)
  - `status` (`active|paused|archived`), `paused_reason` (`manual|owner_lost_access|repeated_failures|null`)
  - `owner_id`, `created_by`, `updated_by`
  - `next_run_at` (UTC, null unless active), `consecutive_failures` int default 0
  - `archived_at`, `archived_by`, `archive_reason`, timestamps
  - Index `(status, next_run_at)`, and an index on each foreign key.
- `report_schedule_recipients`: `id`, `schedule_id`, `user_id`, `added_by`, `added_at`, `removed_by`, `removed_at` (null = current). Index `(schedule_id, removed_at)`. A recipient is removed by setting `removed_at`, never by a delete. Adding the same user back creates a new row.
- `report_schedule_runs`:
  - `id` ulid, `schedule_id`, `planned_at` (UTC), `period_from`, `period_to` (date)
  - `status`, `failure_code`, `error` (user-safe), `missed_count`
  - `owner_id`, `report`, `params`, `branch_id` (null = All mode), `branch_ids` (resolved), `rows`, `attached` bool
  - `file_path`, `size_bytes`, `sha256`, `file_removed_at`
  - `data_as_at`, `started_at`, `finished_at`, `attempts`
  - `settled_at`, `delivery_outcome` (`delivered|not_delivered|nothing_sent|null`), added by migration `2026_12_05_000000_report_schedule_run_settlement` (R15). That migration marks existing `done` runs without a `queued`/`sending` delivery as settled (no outcome), so old runs are never counted afterwards.
  - Unique `(schedule_id, planned_at)`.
- `report_schedule_deliveries`: `id`, `run_id`, `user_id`, `email` (the address at build time), `status` (`queued|sent|skipped|failed`), `reason`, `attempts`, `queued_at`, `sent_at`, `failed_at`. Unique `(run_id, user_id)`.
- **Triggers** (MySQL and SQLite, as `exports`):
  - Every table: `BEFORE DELETE` aborts.
  - Runs: `BEFORE UPDATE` aborts a change to `id`, `schedule_id`, `planned_at`, `period_*`, `report`, `params`, `branch_ids`, and to `sha256` once it is set.
  - Deliveries: `BEFORE UPDATE` aborts a change to `run_id`, `user_id`, `email`.
  - `down()` drops the triggers.
- The models refuse `delete()` (`ImmutableRecordException`).

**Config `config/scheduled_reports.php`:** `attach_max_bytes` 5 MB, `max_recipients` 20, `max_active` 50, `late_minutes` `{daily: 360, weekly: 1440}`, `keep_file_days` 7, `failures_before_pause` 3, `delivery_stale_hours` 24, `sending_stale_minutes` 60, `email_owner` true. Tests may lower these.

**Schedule (`routes/console.php`):** `Schedule::command('reports:dispatch-scheduled')->everyMinute()->withoutOverlapping(10)->onOneServer();`. `exports:prune` (hourly, existing) gains the file step in R12.

## 6. Business rules
- **R1 Branch scope and run context.** Exactly one branch in `branch_ids` means the job runs in **branch mode** of that branch (`BranchContext` + spatie team = that branch), so the PDF equals what that branch's accountant exports. Otherwise it runs in **All mode** with `branches[] = branch_ids`, or with none, meaning every branch at run time, including inactive and newly added ones. Cash book needs at least one branch. The job re-runs `ReportService::params($report, {from, to, branches, type|types})` with the run's period. A 422 there fails the run (`invalid`, "The schedule's filters are no longer valid. Edit the schedule."), and a 403 fails it as `owner_lost_access`. It never calls `withoutGlobalScopes()` beyond the export path.

- **R2 Times and periods (Asia/Colombo, `BusinessDate`).** `next_run_at` is the next local `send_time` (on `weekday` for weekly) strictly after the moment of saving, resuming or the previous planned run. It is stored in UTC (07:00 Colombo = 01:30 UTC). An edit made while a planned time is already due but not yet picked up by the dispatcher keeps that planned time; the times after it follow the new timing (review N7).
  - Daily run planned on local date D: period D-1 to D-1.
  - Weekly run planned on local date D: `to` = the last Sunday strictly before D, `from` = `to` - 6 days.
  - Examples: a run planned Mon 2026-11-23 covers 2026-11-16..2026-11-22. A run planned Sun 2026-11-22 covers 2026-11-09..2026-11-15.
  - The period comes from the planned time, never from the time the worker picks the run up.

- **R3 Owner.** The owner is the Super Admin who created the schedule. Editing by another Super Admin does not change the owner. Resuming a schedule changes the owner to the resuming user only when the current owner is inactive or no longer Super Admin; this writes `report_schedule.owner_changed`. The owner is named in the PDF header and the email footer, and receives the S5 alerts.

- **R4 Dispatch (`reports:dispatch-scheduled`, every minute).** For each `active` schedule with `next_run_at <= now`:
  1. Lock the schedule row.
  2. Collect the planned times from `next_run_at` up to now.
  3. Planned times more than the late window (daily 6 h, weekly 24 h, see below) old make **one** `missed` run: `missed_count` = how many, the period runs from the first missed period's from to the last one's to, `planned_at` = the first. Write the audit row and S5 `missed` (in-app + email). Missed periods are never sent later.
  4. The latest planned time within the late window becomes a `queued` run, and `RunScheduledReport` is dispatched on the `exports` connection after commit.
  5. Set `next_run_at` to the first planned time after now.

  The unique `(schedule_id, planned_at)` plus the row lock stop a double run. The same command also:
  - fails runs `queued`/`running` for more than 120 min (`stale`, "The report was not built. Try again later.", counts as a failed run). This does everything the job's `failed()` does (R9): `report_schedule.run_failed` audit row with code `stale`, `consecutive_failures` + 1, R10 check, S5 `run_failed` bell + email, and its still-`queued` deliveries become `skipped` (`run_failed`) (accounting review A3);
  - fails deliveries still `queued` after `delivery_stale_hours` (`stale`), each with a `report_schedule.delivery_failed` audit row and S5 `delivery_failed` bell row (accounting review A3);
  - fails deliveries `sending` for more than `sending_stale_minutes` (60) as `unknown` (the worker died during the send, or recording `sent` failed): same audit row and bell row, and the email is never sent again (review S1).
  - Every stale UPDATE repeats the age condition, so a run a worker claims meanwhile is not failed (review S3).

  The late window depends on the frequency (accounting review A5): `late_minutes.daily` 360 (6 h), `late_minutes.weekly` 1440 (24 h). A weekly report a few hours late is still the latest complete week and must not be dropped.

- **R5 Recipient check (build and send).** A recipient can receive a run when:
  - (a) they are `active`, **and**
  - (b) they are a Super Admin, **or** the run is single-branch (R1), they belong to that branch, and an active role there holds `report.view` and `report.export`.

  Reasons, stored in `deliveries.reason` and shown on S1/S3:
  - `inactive`: "Inactive user"
  - `super_admin_only`: "Only Super Admins can receive reports covering more than one branch"
  - `not_member`: "Not a member of KDY"
  - `missing_permission`: "No View reports or Export permission in KDY"
  - `schedule_paused`: "Schedule paused or archived before sending"
  - `email_changed`: "Email address changed after the report was built" (at send only, accounting review A2)
  - `run_failed`: "The report run failed" (stale run, R4)
  - delivery failures (`failed`): `mail_error`, `damaged`, `missing`, `stale`, and `unknown`: "The email may have been sent, but the mail server did not confirm it. Check with the recipient." (review S1)

  The check runs at three points:
  - **At save (S2):** newly added recipients must pass (422 "<name> cannot receive this report: <reason>."). Recipients already on the list who fail now are kept, flagged in S1 and S3, and skipped at send. Changing the branches so that current recipients fail is allowed; they are flagged too.
  - **At build:** every current recipient gets a delivery row: `queued` if they pass, else `skipped` with the reason. If at least one was skipped, the owner gets one `recipients_skipped` bell row for the run. If nobody passes, no file is built and the run ends `done` with 0 sent ("Nothing sent"). This counts as a failure for R10.
  - **At send:** the mail channel (`ScheduledReportChannel`) reloads the user, the schedule and the delivery. It sends only if the delivery is still `queued`, the user still passes, the schedule is still `active`, **and the user's current email equals `deliveries.email`** (case-insensitive). The mail is addressed to `deliveries.email` only, never to an address read later. If the address changed, the delivery is `skipped` (`email_changed`); the next run uses the new address (accounting review A2). Otherwise the delivery becomes `skipped` with the reason, and no email is sent.
  - **Sent at most once (review S1):** before the mailer is called the delivery is claimed with a conditional UPDATE `queued -> sending` (`sending_at`). A retry or a second worker that finds `sending` or `sent` sends nothing. If the mailer throws, the claim is released (`sending -> queued`) and the queue retries. If recording `sent` fails after the mailer accepted the email, the job does not fail (no resend); the delivery stays `sending`.

- **R6 Build (`RunScheduledReport`).**
  1. Claim the run: a conditional UPDATE `queued -> running`. A finished run does nothing (idempotent). A run left `running` for longer than the job timeout (its worker died) may be claimed again by the job's next attempt (review N1).
  2. Check the owner: active and Super Admin. If not, the run fails with `owner_lost_access` and no retry, and the schedule is paused by the system (`paused_reason = owner_lost_access`, `report_schedule.paused` with `by_system`). S5 `paused` goes to every active Super Admin.
  3. Make the deliveries (R5).
  4. Inside one `ReadSnapshot`, as the owner: count rows (`countRows`). With rows <= 1,000, build the PDF with the existing header and views. The "Printed by" line becomes "Scheduled report "<name>", set up by <owner name>. Printed at <time> (Asia/Colombo)." It is followed by `BackgroundExports::extractedLine(data_as_at, planned_at)`. PROVISIONAL and the other header lines are unchanged.
  5. Write the file to `exports` as `scheduled/<run id>.pdf.part` (0600), rename it to `.pdf`, and record `size_bytes` and `sha256`.
  6. Set `attached` = (rows <= 1,000 and size <= `attach_max_bytes`).
  7. Status `done`, write the audit row, queue one mail per `queued` delivery.

  The developer adds a method on `ReportExporter` that returns the PDF bytes from the same `header`/`pdf` code (`pdf()` now returns a Response). Excel is never built.

- **R7 Attach or link.** When `attached` is true, the PDF is attached as `<report>_<branches>_<from>_<to>.pdf`, where `<branches>` is the branch code for a single branch, the codes joined by `-` for up to 3 branches, else `ALL` (for example `income-expense_KDY_2026-11-20_2026-11-20.pdf`), so that branch files cannot be confused when filed (accounting review A7). Synchronous exports keep `ReportExporter::fileName`. Otherwise (more than 1,000 rows, so no PDF was built, or the file is over 5 MB) the email is the S4 "link" text. The link goes to `FRONTEND_URL/reports/<report>?from=..&to=..` plus `&branch=<id>` (single branch) or `&branch=all&branches=..` (All mode), plus the schedule's own filter (`&type=` for I&E when not All, `&types=..` for Transfers; review N4). Why 5 MB: base64 adds about 37 %, so the email stays under 7 MB, below the common 10 MB server limit. Why no public file link: the same reason as AG-087 R6, a forwarded email must not open financial data without sign-in.

- **R8 Integrity at send.** Before attaching, the mail re-reads the file and compares its `sha256`:
  - mismatch: the delivery fails with `damaged`, nothing is sent, the audit row is written and the owner is alerted;
  - file gone: the delivery fails with `missing`.

- **R9 Retries and failures.**
  - `RunScheduledReport`: `$tries = 2`, `$backoff = 60`, `$timeout = 600`, `$failOnTimeout = true`. `owner_lost_access` and `invalid` do not retry.
  - The job's `failed()` sets the run to `failed` (`error`, `timeout`), deletes any `.part` file, writes `report_schedule.run_failed`, increments `consecutive_failures` and sends S5 `run_failed`.
  - Users never see exception text: "The report could not be built. It will run again at the next scheduled time."
  - `ScheduledReportMail`: `$tries = 3`, `backoff [60, 300, 900]`. On the final failure the delivery becomes `failed` (`mail_error`, "The mail server did not accept the email."), with the audit row and S5 `delivery_failed`. Other recipients are not affected.
  - `sent` means the mailer returned without error. Bounces are not tracked.
  - A run with at least one delivery sent resets `consecutive_failures` to 0.
  - Owner emails are themselves best effort: if mail is down, the bell row and S1/S3 are how the owner learns.

- **R10 Automatic pause.** After `failures_before_pause` (3) runs in a row that are `failed`, `missed`, "Nothing sent" (R5, at build) or "Not delivered" (R15), in any mix, the schedule is paused by the system (`repeated_failures`). S5 `paused` goes to the active Super Admins (see S5 for a former owner). When the missed run recorded by the dispatcher is the one that pauses the schedule, the planned time that was still on time is not sent; it is added to that missed run (count and period), so every planned time is recorded (review S2).

- **R11 States.**
  - `active` -> pause -> `paused` (`next_run_at` null); `paused` -> resume -> `active` (R2, no back-fill).
  - `active`/`paused` -> archive -> `archived`; `archived` -> restore -> `paused`.
  - An archived schedule cannot be edited, paused or resumed.
  - Pausing or archiving does not cancel a run already queued: its unsent deliveries are skipped at send (`schedule_paused`).
  - An edit while a run is queued does not change that run: the run stored its report, params and branches, and it reads the recipients at build.
  - `max_active` (50) counts active schedules only.

- **R12 Files.** `exports:prune` deletes a run's PDF `keep_file_days` (7) after `finished_at`, when it has no `queued` delivery. It sets `file_removed_at` and clears `file_path`, and writes one `report_schedule.files_removed` system row per pass with the run ids. Orphan `.part` files are removed by the existing 25 h orphan step. There is no download endpoint: the PDF is kept only so the mail retries have it.

- **R13 Audit** (`AuditLog::record`, the Reports group in `AuditLogController::GROUPS`, prefix `report_schedule.`):
  - **Branch:** `branch_id` follows `report.exported`: the branch when exactly one is in scope, else null.
  - **User actions** (actor = the Super Admin, with IP and user agent):
    - `report_schedule.created`, with all fields and the sorted `recipient_ids`;
    - `report_schedule.updated`, with old and new values of the changed fields only. Recipients appear as `recipient_ids` old/new. Saving the same values again writes no row;
    - `report_schedule.paused`, `.resumed`, `.archived` (with the reason), `.restored`, `.owner_changed`.
  - **System events** (`new_values.by_system = true`, `user_id` = owner, the audit screen shows "System"):
    - `report_schedule.run_built`: run id, period, rows, attached, size, sha256, data_as_at, provisional (bool), queued user ids, skipped `{user_id: reason}`, and the report's **control totals** as sent (accounting review A4): I&E `total_income`, `total_expense`, `net`; Branch Comparison Total row `opening`, `closing`; Transfers `net_moved`; Cash book per account `{account_id: {opening, receipts, payments, closing}}`. Amounts as `Money` strings. Built from the same snapshot as the PDF; for a link-only run (over 1,000 rows) they are computed in the same snapshot without the PDF;
    - `report_schedule.run_failed`: code, attempts;
    - `report_schedule.run_missed`: missed_count, period;
    - `report_schedule.delivered`: run id, recipient id, **email address used**, sha256 or "link";
    - `report_schedule.delivery_skipped` (at send) and `report_schedule.delivery_failed`: reason;
    - `report_schedule.run_not_delivered` (R15): run id, period, `failed` `{user_id: reason}`, `skipped` `{user_id: reason}`, `counted` (bool), `consecutive_failures` after it, `paused` (bool);
    - `report_schedule.paused` with `paused_reason`;
    - `report_schedule.files_removed`.
  - **Labels:** "Report schedule created / changed / paused / resumed / archived / restored / owner changed", "Scheduled report built / failed / not delivered / missed / emailed / not sent / email failed / files removed".
  - Status changes and their audit rows commit in one transaction (AG-087 sign-off C2).

- **R14 Edge cases.**
  - **Empty report:** sent as usual (for example "No income or expenses in this period.").
  - **Inactive branch in scope:** reported, as today.
  - **Branch added later:** an All-branches schedule includes it from the next run.
  - **User changes their email:** the next delivery uses the new address; the delivery row keeps the address used.
  - **Deactivated recipient:** skipped with `inactive`; reactivation brings them back. Removing someone from the list is the owner's choice, never automatic, so the list never changes without an audit row.
  - **Owner demoted from Super Admin:** treated as deactivated (R6 step 2).
  - **Super Admin as a recipient of any schedule:** always eligible while active.
  - **Load:** the one exports worker builds runs one at a time, alongside user exports. A run can therefore start a few minutes late. Its period is unaffected (R2).

- **R15 Email-only failures count towards the automatic pause** (accountant sign-off, accepted risk 3 follow-up).
  - **Settled.** A run is settled when its status is `done`, it has at least one delivery, and every delivery is final: `sent`, `skipped` or `failed`. A delivery `queued` or `sending` is not final. `failed` and `missed` runs are never settled: they were already counted when they failed (R9, R4).
  - **When it is checked.** After the commit of every change that makes a delivery final: `markSent`, a skip at send (R5) and every delivery failure (`mail_error`, `damaged`, `missing`, `stale`, `unknown`). The dispatcher (R4) also settles, every minute, any `done` run that is not settled and has no open delivery (the worker died between the commit and the check). The check is best effort for the caller: a mail job is never failed or retried because of it.
  - **Outcome** (`report_schedule_runs.delivery_outcome`, with `settled_at`):
    - `delivered`: at least one `sent`. `consecutive_failures` was already reset to 0 by the first `sent` (R9). Nothing else happens.
    - `not_delivered`: none `sent` and at least one `failed`, whatever the failure reason. Skipped deliveries do not change this (one failed and the rest skipped is still `not_delivered`). This is one more failure for R10 (`consecutive_failures` + 1 and the R10 check, which can pause the schedule). The `report_schedule.run_not_delivered` audit row is written, and the owner gets one bell row and **one email** for the run (S5), in addition to the existing `delivery_failed` bell row per email.
    - `nothing_sent`: every delivery `skipped`. Not counted here. When nobody passed at build, the run was already counted as "Nothing sent" when it was built (R5); it is settled at once with this outcome and never counted again. When the recipients passed at build but were all skipped at send (access lost, schedule paused, email changed), the run is not a failure, as before.
  - **`unknown` counts as failed.** A delivery `unknown` ("may have been sent") might have reached the recipient, but nothing confirms it. For R10 it is treated as failed: the owner is alerted and three such runs pause the schedule, so a person checks. The S3 reason text still says it may have been sent, and it is never resent.
  - **Order of runs.** The streak is runs in a row. A `not_delivered` run that settles after a later run of the same schedule already sent an email (for example its deliveries went `stale` after 24 h) is recorded and alerted as above, but not counted (`counted` false in the audit row), so it does not undo the reset.
  - **Exactly once.** In one transaction: lock the run row (`SELECT ... FOR UPDATE`), read its deliveries with a locking read (MySQL REPEATABLE READ: the latest committed rows, not an older snapshot), then a conditional UPDATE `SET settled_at, delivery_outcome WHERE id = ? AND status = 'done' AND settled_at IS NULL`. Only the caller whose UPDATE changes the row counts, audits and alerts; a second call, concurrent or later, changes nothing and does nothing. Each delivery change commits before its check starts, so the last delivery to become final always sees the others as final. Lock order run -> deliveries -> schedule. `failRun` also locks the run first, and `markSent`/`deliveryFailed` never lock a run, so there is no lock cycle. The later-run check runs after the schedule lock as the transaction's first non-locking read. A reset committed after that lock lands after the increment.
  - Nothing is deleted. The run row only gains `settled_at` and `delivery_outcome`, and the status change and its audit row commit together (R13).

## 7. Acceptance criteria
Fixture:
- Clock fixed at **Fri 2026-11-20 10:00 Asia/Colombo**. Branches MAIN, KDY, GAL.
- Users: Super Admins SA and SA2; KDY Branch Admin KBA (also a GAL Viewer); KDY Accountant AK; KDY Viewer KV; GAL Accountant GA; inactive KDY Accountant AX.
- Data: the reports E-dataset (reports.md section 7), plus KDY incomes on 2026-11-20.
- Schedules: **D1** = "KDY daily I&E", Income & Expense, Type All, branches [KDY], daily 07:00, recipients SA + AK, owner SA. **W1** = "Weekly comparison", Branch Comparison, All branches, weekly Monday 07:00, recipients SA + SA2.
- Queues run with `Queue::fake`/`Notification::fake` or a sync worker, as each AC says.

**Create and validate**
1. Given SA, When POST D1, Then 201, status `active`, `next_run_at` = 2026-11-21T01:30:00Z (07:00 Colombo), `next_period` 2026-11-20..2026-11-20. One `report_schedule.created` row has `branch_id` KDY and `recipient_ids` [SA, AK].
2. Given SA, When POST W1, Then `next_run_at` = Mon 2026-11-23 07:00 Colombo and `next_period` 2026-11-16..2026-11-22. `GET /preview-time` with weekly / weekday 7 / 07:00 returns 2026-11-22 07:00 with 2026-11-09..2026-11-15.
3. Given POST bodies with each of the following, Then 422 with the quoted message and nothing is stored:
   - a second schedule named "kdy DAILY i&e": "A schedule with this name already exists.";
   - `send_time` "07:15": "Choose a time on the hour or half hour.";
   - weekly without `weekday`;
   - `report` "trial-balance": "Choose one of the listed reports.";
   - `cash-book` with no branches: "Select at least one branch.";
   - 21 recipients;
   - 0 recipients.
4. Given D1, When SA adds KV, Then 422 "<KV name> cannot receive this report: No View reports or Export permission in KDY.". When SA adds GA, Then 422 "... Not a member of KDY.". When SA adds AX, Then 422 "... Inactive user.". When SA adds AK to W1, Then 422 "... Only Super Admins can receive reports covering more than one branch.". `GET /recipient-options?branches[]=KDY` lists KV, GA and AX with `eligible: false` and these reasons, and AK with `eligible: true`.
5. Given KBA or AK, When calling any `/v1/report-schedules` route, Then 403 "Super Admin only.". The "Scheduled reports" menu is hidden for them and shown for SA in All mode and in branch mode.
6. Given D1, When `DELETE /v1/report-schedules/<id>`, Then 405. Deleting a schedule, run or delivery row directly in the database is refused, and so is changing a run's `params`, `period_from` or a set `sha256`.

**Dispatch and build**
7. Given D1 and the clock at 2026-11-21 07:00, When `reports:dispatch-scheduled` runs, Then exactly one run (`queued`, planned 07:00, period 2026-11-20..2026-11-20) exists, `RunScheduledReport` is pushed on connection `exports`, queue `exports`, and `next_run_at` = 2026-11-22 07:00. Running the command again at 07:00 and 07:01 creates no second run.
8. Given AC 7's run processed, Then:
   - the run is `done` with `rows` > 0, `attached` true, `size_bytes`, `sha256` and `data_as_at` set;
   - the file is on the `exports` disk with mode 0600;
   - the PDF totals equal a synchronous PDF export by SA in KDY branch mode for 2026-11-20 (income, expense, net);
   - the header has "Scheduled report "KDY daily I&E", set up by <SA name>" and "Data extracted <date time:ss> ... Requested 2026-11-21 07:00";
   - two deliveries (SA, AK) are `queued`, and one `report_schedule.run_built` row with `by_system` true exists.
9. Given AC 8 with the mail queue processed, Then SA and AK each receive one mail:
   - subject "KDY daily I&E: Income & Expense by Category, <2026-11-20 in the date format>";
   - one attachment `income-expense_KDY_2026-11-20_2026-11-20.pdf` whose sha256 equals the run's;
   - a body with no amount and no "as at".

   Both deliveries are `sent`, and two `report_schedule.delivered` rows carry the email address used.
10. Given the clock is 2026-11-21 08:30 because the worker started late, When the D1 run builds, Then its period is still 2026-11-20..2026-11-20.

**Access at build and send**
11. Given AK's KDY role loses `report.export` before 07:00, When the run builds, Then AK's delivery is `skipped` (`missing_permission`), no mail goes to AK, SA's mail is sent, SA has one `report_schedule.recipients_skipped` bell row, and S1 shows "1 cannot receive" on D1. AK is still in `recipients`. After the permission is restored, the next run sends to AK.
12. Given AK is deactivated before the build, Then AK's delivery is `skipped` (`inactive`).
13. Given the run is built with AK `queued`, When AK is removed from KDY before the mail job runs, Then no mail is sent to AK, the delivery is `skipped` (`not_member`), and a `report_schedule.delivery_skipped` row is written.
14. Given every D1 recipient fails R5, When the run builds, Then no file is written, the run is `done` with 0 sent, and S1 shows "Nothing sent".

**Owner**
15. Given SA is deactivated (or loses the Super Admin flag), When D1's next run starts, Then:
    - the run is `failed` (`owner_lost_access`) after one attempt, and no file exists;
    - D1 is `paused` with `paused_reason` `owner_lost_access`;
    - `report_schedule.paused` is written with `by_system` true;
    - SA2 gets one `report_schedule.paused` bell row and one email.
16. Given AC 15, When SA2 resumes D1 at 2026-11-22 10:00, Then 200, `owner` = SA2, one `report_schedule.owner_changed` row exists, and `next_run_at` = 2026-11-23 07:00. No run is created for 2026-11-21 or 2026-11-22.

**Size**
17. Given `ReportService::PDF_EXPORT_CAP` would be passed (test: a D1-like cash-book schedule whose period has 1,001 lines), When the run builds, Then no PDF is written, `attached` false, and each mail has no attachment, contains "too many to attach" and a link ending `/reports/cash-book?from=2026-11-20&to=2026-11-20&branch=<KDY id>`.
18. Given `attach_max_bytes` = 1 KB, When D1 runs, Then the PDF is built (sha256 set) but not attached, and the mail is the link version.
19. (UI, W1) Given KBA with active branch GAL opens the AC 17 link, Then the switcher changes to KDY and the cash book loads for 2026-11-20. Given GA opens it, Then "You do not have access to this branch." is shown and no report request is sent. (Amended after QA D1: GA has no access to KDY, so the app must not reveal its code.)

**Failures and retries**
20. Given the build throws a `QueryException` on the first attempt only, Then the run ends `done` with `attempts` 2. Given it throws on both, Then:
    - the run is `failed` with "The report could not be built. It will run again at the next scheduled time." (no exception text);
    - no recipient mail is sent;
    - `consecutive_failures` = 1;
    - SA gets one bell row and one `ReportScheduleProblemMail`.
21. Given 3 failed runs in a row, Then D1 is `paused` (`repeated_failures`) and SA and SA2 are alerted. Given 2 failures and then a run with a sent delivery, Then `consecutive_failures` = 0.
22. Given the mail transport throws for AK on all 3 tries, Then AK's delivery is `failed` (`mail_error`), one `report_schedule.delivery_failed` row exists, SA gets a `delivery_failed` bell row, and SA's own delivery is `sent`.
23. Given the run's PDF is changed on disk before the mail job runs, Then the delivery is `failed` (`damaged`) and no mail is sent. Given the file is deleted, Then it is `failed` (`missing`).
24. Given a run `queued` for 121 minutes, When the dispatcher runs, Then it is `failed` (`stale`). Given a delivery `queued` for 25 h, Then it is `failed` (`stale`), and a later mail job for it sends nothing.

**Missed runs**
25. Given D1 and no dispatcher between 2026-11-21 06:00 and 2026-11-23 09:00, When it runs at 2026-11-23 09:00, Then:
    - one `missed` run exists with `missed_count` 2, period 2026-11-20..2026-11-21 and `planned_at` 2026-11-21 07:00;
    - one `queued` run exists for planned 2026-11-23 07:00 with period 2026-11-22..2026-11-22;
    - `next_run_at` = 2026-11-24 07:00;
    - SA gets the `missed` bell row and an email.

**States and audit**
26. Given D1, When SA pauses it, Then `paused` (`manual`), `next_run_at` null, and no run is created at 07:00. Pausing again gives 422 "This schedule is not active.".
27. Given a D1 run built with AK `queued`, When SA pauses D1 before the mail job runs, Then AK's delivery is `skipped` (`schedule_paused`).
28. Given D1, When SA archives it with reason "Branch closed", Then it is `archived`, it is missing from `GET /report-schedules` (default) and present with `status=all`, PUT returns 422 "Restore this schedule before editing it.", and restore gives `paused`. Given a new schedule named "KDY daily I&E" was created meanwhile, Then restore gives 422 "A schedule with this name already exists.".
29. Given D1, When SA changes `send_time` to 06:30, Then `next_run_at` = 2026-11-21 06:30, and one `report_schedule.updated` row has old/new `send_time` only. The same PUT again writes no audit row. Removing AK writes old/new `recipient_ids`, sets `removed_at` on AK's recipient row, and keeps the row.
30. Given 50 active schedules, When SA creates another, Then 422 "You can have at most 50 active schedules.". A paused one does not count.
31. Given the audit screen's Reports group, Then the rows from AC 1-29 show the R13 labels; system rows show "System" as the user. A KDY user with `audit.view` sees D1's rows (branch KDY) but not W1's (branch null).
32. Given 8 days after a run finished with no queued delivery, When `exports:prune` runs, Then the PDF is gone, `file_removed_at` is set, the run row is unchanged otherwise, and one `report_schedule.files_removed` row exists. A 6-day-old file is kept.
33. Given all flows in AC 7-32, Then `transactions`, `ledger_entries` and every `accounts.current_balance` are unchanged.

**UI**
34. Given SA on S2, When choosing Daily 07:00, Then the line reads "Next email: Sat 21 Nov 2026 07:00, covering Fri 20 Nov 2026" (default date format). With weekly Monday it reads "..., covering Mon 16 Nov 2026 - Sun 22 Nov 2026". With Branches KDY + GAL, the recipient list disables everyone except SA and SA2 and shows "Only Super Admins can receive reports covering more than one branch.". "Preview this report" opens `/reports/income-expense?from=2026-11-20&to=2026-11-20&branch=<KDY id>` in a new tab.
35. Given S1 with D1 after AC 11, Then the row shows Last run "Partly sent" (amber) and "1 cannot receive". Given S3, Then expanding the run shows AK "Skipped - No View reports or Export permission in KDY" and SA "Sent".
36. (390px) Given a 390px-wide viewport, Then S1 shows stacked cards, S2 is one column with a sticky Save, S3 shows runs as cards, there is no horizontal page scroll, and every action is at least 44 px tall.
37. `php artisan schedule:list` shows `reports:dispatch-scheduled` every minute. `php artisan test`, `./vendor/bin/pint --test` and `npx ng build` pass.

**Email-only failures (R15, accepted risk 3 follow-up)**
44. Given D1 (SA + AK) and a mail server that refuses both addresses on all 3 tries, When the run is built and its emails run, Then both deliveries are `failed` (`mail_error`), the run is settled with `not_delivered`, `consecutive_failures` = 1, one `report_schedule.run_not_delivered` row exists (`by_system`, `counted` true, branch KDY, both failure reasons), and SA gets the two `delivery_failed` bell rows, one "Scheduled report KDY daily I&E was not delivered" bell row and one email.
45. Given D1 after AC 44 (`consecutive_failures` 1), When the next run sends SA's email and AK's fails, Then the run is `delivered`, `consecutive_failures` = 0, and no second `run_not_delivered` row, bell row or owner email exists.
46. Given D1 with AK as the only recipient and AK without `report.export`, When the run is built, Then it is "Nothing sent" as before: AK `skipped`, outcome `nothing_sent`, `consecutive_failures` = 1 (counted once at build; settling again changes nothing), a `recipients_skipped` bell row, no owner email and no `run_not_delivered` row. Given AK passes at build but loses `report.export` before the email runs, Then AK is `skipped` (`missing_permission`), outcome `nothing_sent`, and `consecutive_failures` is unchanged.
47. Given D1 and every email refused on 21, 22 and 23 Nov, Then after the third run D1 is `paused` (`repeated_failures`), `next_run_at` null, `consecutive_failures` 3, three `run_not_delivered` rows and one `report_schedule.paused` row (`by_system`) exist, SA and SA2 each get one `paused` bell row, SA2 one email, SA three "not delivered" emails plus the pause email, and no run is created on 24 Nov.
48. Given a D1 run whose deliveries stay `sending` for more than 60 minutes, When the dispatcher runs, Then they are `failed` (`unknown`), the run is `not_delivered` and `consecutive_failures` = 1. Given the next run's deliveries stay `queued` for 25 h, Then they are `failed` (`stale`) and `consecutive_failures` = 2.
49. Given AC 44's settled run, When the settlement runs again (directly, the dispatcher sweep and the dispatcher command), Then `consecutive_failures` stays 1, and there is still one `run_not_delivered` row, one bell row and one owner email. Given a run whose deliveries became final without the check after the commit, Then the dispatcher sweep settles it once.
50. Given run A whose deliveries are still `sending` and a later run B whose email is sent (`consecutive_failures` 0), When A's deliveries then fail as `unknown`, Then A is `not_delivered` with `counted` false, `consecutive_failures` stays 0, and the owner still gets the bell row and the email.

## 8. Open questions (assumption in bold, work is not blocked)
**For the product owner (they change scope):**
- **Q1 Who manages schedules?** **Super Admin only**, as the backlog says, with no new key. Letting Branch Admins schedule their own branch would need a new key (`report.schedule`) given to `*` roles, and a branch-scoped list. That would be a follow-up story.
- **Q2 External recipients (auditor, owner's personal email)?** **No: users of the system only.** A free address cannot be checked for access at send time, nor deactivated when someone leaves. If needed, the auditor gets a user account (for example a Viewer, once Q4 is decided) or a follow-up story adds external addresses with Super Admin-only scope.
- **Q3 Monthly frequency, Trial Balance and Cash Flow?** **Not now.** The backlog says daily/weekly, and these reports need whole months or one fiscal year. They can be added later on the same tables (a new frequency value).

**Decided by assumption (accountant to confirm):**
- **Q4 Viewer as recipient.** **Not allowed:** receiving the PDF needs `report.export`, as pressing PDF does. A branch can tick `report.export` on a custom role for a manager who should receive reports.
- **Q5 Email body without figures.** **Yes:** figures only in the PDF. The body is the part most often previewed or forwarded.
- **Q6 Limits.** **1,000 rows (the PDF cap), 5 MB attachment, 20 recipients, 50 active schedules, late window 6 h daily / 24 h weekly (accounting review A5), 7-day file retention, pause after 3 failures.** All are in config.
- **Q7 Missed periods are not sent later** (R4): they are recorded as one missed run, and the owner is alerted. A late daily report that arrives days later is noise; the period can be viewed on screen.

## Accounting review
Reviewed against CLAUDE.md, reports.md (incl. its Accounting review and sign-off), background-exports.md, notifications.md, users-and-roles.md and the delivered `PermissionSeeder::TEMPLATES` (Accountant view + export, Viewer view only, Cashier neither). The changes marked "accounting review Ax" in sections 1-8 above are folded in.

**Fine as written (one line each):**
- Read-only: no postings, no balance changes; AC 33 proves it. Inter-branch: the Branch Comparison and matrix reuse the signed-off report code, so transfers never appear as income/expense and the subset-of-branches note carries into the PDF.
- Weekly = previous complete Monday-Sunday week, period taken from `planned_at` not the pick-up time (R2, AC 10): correct. The S2 live line makes it visible that a weekly run on a later weekday covers a week that ended days earlier.
- PROVISIONAL marker + "Data extracted" line: correct. A daily report of yesterday will almost always be provisional, and entries back-dated after 07:00 appear only in later reports; the email says figures can still change. Accepted.
- Confidentiality: user recipients only, `report.view` + `report.export` in the single branch, multi-branch to Super Admins only, re-check at build and at send, one identical file for all, no figures in the body, sign-in link above 1,000 rows / 5 MB, no public file link. Correct. Email changes are already audited and alerted to Super Admins (`UserController` `user.updated` + `AccessAlerts`), and a Branch Admin cannot change a Super Admin.
- Viewers excluded (Q4): agreed. Receiving a PDF is an export; the Viewer template has no `report.export`.
- No figures in the email body (Q5): agreed.
- Empty report still sent (R14): agreed; a nil report is information, a missing email is ambiguous.
- Audit: every user action, build, send, skip, failure, miss, pause and file removal is recorded; `by_system` follows AG-087; runs/deliveries/schedules cannot be deleted (triggers + model). Correct.
- Owner visibility: bell + email for failed, missed and system pause; system pause also to every active Super Admin; auto-pause after 3 failures; "Nothing sent" counts as a failure. Correct.
- Limits: 1,000 rows, 5 MB, 20 recipients, 50 active schedules, 7-day file retention (enough for 24 h delivery retries and a damaged/missing investigation; the disk is not backed up, so exposure stays short), pause after 3: confirmed. 6 h late window: confirmed for daily only (A5).

**Scope choices (controls view):**
- Super Admin only manages schedules (Q1): agreed. Sending company data out of the system is an owner-level decision, and no new key avoids `*` roles inheriting it.
- No external addresses (Q2): agreed. An external address cannot be re-checked at send or deactivated. An external auditor must **not** be made a Super Admin to receive consolidated reports (that would give full write rights); if consolidated reports for an auditor are needed, that is a follow-up story (a read-only, export-only consolidated recipient type).
- No monthly / Trial Balance / Cash Flow Summary (Q3): agreed for this story (backlog says daily/weekly). Recommended follow-up: a monthly management pack sent **after the month is locked** (AG-091), so the emailed month-end figures are final, not provisional.

### Findings
1. **MUST - A1 Daily period is the previous calendar day.** Section 1 said "previous business day", R2 says D-1. "Business day" would make Monday's email cover Friday and silently skip Saturday/Sunday takings. Rule (now in section 1): daily covers D-1 in Asia/Colombo for every day of the week, including weekends and holidays. AC 39.
2. **MUST - A2 Send only to the address checked at build, and only if it is still the user's address.** `deliveries.email` is fixed at build and cannot change (trigger); without a check the mail would go to the old address after an email change (e.g. a compromised or former mailbox) for up to 24 h of retries. Rule (now in R5 "At send"): send only when the user's current email equals `deliveries.email`; otherwise skip with `email_changed`. AC 40.
3. **MUST - A3 Stale runs and stale deliveries are audited and alerted like any other failure.** R4 said they "count as a failed run" but the dispatcher, not the job's `failed()`, marks them, so no audit row or alert was specified. Rule (now in R4): stale run = `report_schedule.run_failed` (code `stale`), `consecutive_failures` + 1, R10 check, S5 `run_failed` bell + email, its queued deliveries `skipped` (`run_failed`); stale delivery = `report_schedule.delivery_failed` + S5 `delivery_failed`. AC 41 (extends AC 24).
4. **SHOULD - A4 Record the control totals that were sent.** The PDF is removed after 7 days and an unlocked period can still change (void, back-dated entries). Without the figures in the audit row nobody can later show what management was told, or spot that a period changed after it was reported. Rule (now in R13 `run_built`): store the report's headline totals and the provisional flag. They are internal (audit screen, branch-scoped as R13), not in the email. AC 42.
5. **SHOULD - A5 Late window per frequency, and say when an email is late.** 6 h is right for daily (a daily report arriving in the afternoon is still useful, the next day it is noise). For weekly it would drop the whole week's report after a half-day outage. Rule (now in R4 and config): daily 360 min, weekly 1440 min; a run started more than 60 min after `planned_at` adds the S4 "Sent late" line. AC 38.
6. **SHOULD - A6 Reconciliation of an All-branches scheduled run.** AC 8 checks only I&E in branch mode. Add AC 43 for W1 (Branch Comparison, All branches): per row Closing = Opening + Capital introduced + Funds received + Income - Expenses + Transfers in - Transfers out; sum of branch rows = Total; Total (Funds received + Transfers in - Transfers out) = 0.00; equal to a synchronous PDF export by SA for the same period.
7. **SHOULD - A7 Branch in the attachment name.** Two daily schedules for KDY and GAL both produced `income-expense_2026-11-20_2026-11-20.pdf`, an easy misfiling. Rule (now in R7, F1, AC 9): `<report>_<branches>_<from>_<to>.pdf`.

### Acceptance criteria to add
38. (A5) Given W1 and no dispatcher between Mon 2026-11-23 06:00 and 2026-11-23 20:00, When it runs at 20:00, Then one `queued` run for planned 2026-11-23 07:00 (period 2026-11-16..2026-11-22) is created, no `missed` run, and the emails contain "Sent late: this email was planned for <23 Nov 2026 07:00>". Given the gap lasts until 2026-11-24 08:00, Then a `missed` run (`missed_count` 1) is recorded instead and the owner is alerted.
39. (A1) Given D1, When the run planned Mon 2026-11-23 07:00 is built, Then its period is 2026-11-22..2026-11-22 (Sunday); runs planned Sun 2026-11-22 and Sat 2026-11-21 cover 2026-11-21 and 2026-11-20.
40. (A2) Given a D1 run built with AK `queued` (email used `ak@...`), When AK's email is changed before the mail job runs, Then no mail is sent (to either address), the delivery is `skipped` (`email_changed`), a `report_schedule.delivery_skipped` row is written, and the next run's delivery uses the new address.
41. (A3) Given a run `queued` for 121 minutes, When the dispatcher runs, Then a `report_schedule.run_failed` row (code `stale`, `by_system`) exists, `consecutive_failures` is + 1, the owner gets a `run_failed` bell row and email, and any `queued` deliveries are `skipped` (`run_failed`). Given a delivery `queued` for 25 h, Then a `report_schedule.delivery_failed` row and a `delivery_failed` bell row exist.
42. (A4) Given AC 8's run, Then `report_schedule.run_built` holds `provisional` true and `total_income`, `total_expense`, `net` equal to the PDF totals; given AC 17's link-only cash-book run, Then it holds per-account opening, receipts, payments, closing, and each closing = opening + receipts - payments.
43. (A6) Given W1's run planned 2026-11-23 is processed, Then the PDF Branch Comparison satisfies the closing formula on every row, the branch rows sum to Total, Total (Funds received + Transfers in - Transfers out) = 0.00, and every figure equals a synchronous PDF export by SA in All mode for 2026-11-16..2026-11-22.

### Verdict
Spec accepted for development once MUST findings A1-A3 are in the build (they are folded into the body above). No product-owner decision blocks the work.

## Accountant sign-off
2026-11-20. Build reviewed: `git diff feature/recurring-expenses...HEAD` (e02194b build, 0dbab2c review/QA fixes). Test results as reported by the team (not re-run here): SQLite 957 passed, MySQL 958 passed, pint, `ng build` and `ng test` green.

### Findings A1-A7
1. **A1 (MUST) DONE.** `ScheduledReports::period()` daily = D-1 in Asia/Colombo with no weekday logic; period taken from `planned_at`. Tests `test_ac39_daily_covers_every_calendar_day` (Sat/Sun/Mon runs cover Fri/Sat/Sun), `test_ac10_period_from_planned_time_not_pickup`.
2. **A2 (MUST) DONE.** `ScheduledReportRuns::prepareSend()` skips with `email_changed` when the current email differs from `deliveries.email`; the mail is routed to `deliveries.email` only (`Notification::route('mail', $d->email)`); the address is frozen by the deliveries update trigger. Test `test_ac40_email_changed_after_build`.
3. **A3 (MUST) DONE.** `failStaleRuns()` -> `failRun()` writes `run_failed` (code `stale`), skips queued deliveries (`run_failed`), `countFailure()` (R10), bell + email. `failStaleDeliveries()` writes `delivery_failed` + bell for `stale` and `unknown`. Every stale UPDATE repeats the age condition. Tests `test_ac24_ac41_stale_run_and_stale_delivery`, `test_ac41_stale_running_run_skips_its_queued_deliveries`, `test_s3_a_run_claimed_after_the_stale_select_is_not_failed`.
4. **A4 (SHOULD) DONE.** `ReportExporter::controlTotals()` from the same snapshot data as the PDF, stored with `provisional` in `run_built`, also for link-only runs. Tests `test_ac8_ac9_ac42_build_and_send` (3,750.00 / 400.00 / 3,350.00), `test_ac17_ac42_too_many_rows_is_link_only` (closing = opening + receipts - payments per account).
5. **A5 (SHOULD) DONE.** Config `late_minutes` daily 360 / weekly 1440; "Sent late" when `started_at` > `planned_at` + 60 min. Tests `test_ac38_weekly_late_window`, `test_ac38_weekly_gap_over_24_hours_is_missed`, `test_ac10_...` (90 min late).
6. **A6 (SHOULD) DONE.** `test_ac43_weekly_all_branches_comparison_reconciles`: closing formula per row, branch rows = Total, Total (funds received + transfers in - transfers out) = 0, audit totals = report Total, scheduled PDF text identical to a synchronous All-mode export apart from the printed line.
7. **A7 (SHOULD) DONE.** `ScheduledReportRuns::attachmentName()`: `income-expense_KDY_...`, `branch-comparison_ALL_...` (tests AC 9, AC 38).

### Fixes with control impact
- **Sent at most once (queued -> sending -> sent).** Accepted. The conditional claim stops a retry or second worker from re-sending; a delivery left `sending` > 60 min becomes `failed` / `unknown` with "may have been sent ... check with the recipient", is audited and belled, and is never resent (`test_s1_recording_sent_fails_after_the_email_went_out_no_resend_then_unknown`). For a confidential report, "possibly not delivered, ask a person" is the right failure direction; a duplicate or a resend to a stale address would be worse.
- **Alerts when the owner lost Super Admin** go to the other active Super Admins (`alertOwner()`, `systemPause()`; `test_d7_...`). Correct: a demoted owner must not keep receiving schedule status.
- **AC 19 message** stays "You do not have access to this branch."; `branchDenied()` only looks up codes among the user's own branches, so no code of an inaccessible branch is revealed (web spec "GA (no KDY access) ...", no report request sent). Correct.
- **No figures in email bodies:** body asserts no amount pattern and no "as at" (AC 9); link email has text only. Correct.
- **Access re-checked at build and at send** (`recipientProblem()` in `build()` and `prepareSend()`), plus schedule-active check at send. Correct.
- **Nothing posts money:** no LedgerService, `current_balance`, transactions or ledger writes in the diff; AC 33 compares the books before and after. Correct.
- **Audit on every change:** user actions (created/updated/paused/resumed/archived/restored/owner_changed) and system events (built/failed/missed/delivered/skipped/failed/files_removed), status + audit row in one transaction. Correct.
- **No hard deletes:** BEFORE DELETE triggers on every table (MySQL + SQLite), frozen-column UPDATE triggers on runs/deliveries, no DELETE route (AC 6). Correct.

### Release notes
- Super Admins can schedule daily (previous calendar day, weekends included) or weekly (previous Monday-Sunday) PDF emails of Income & Expense, Branch Comparison, Transfers & Allocations and Daily Cash Book, sent at 07:00-style local times (Asia/Colombo).
- Recipients are system users only; multi-branch reports go to Super Admins only; single-branch reports need View reports + Export in that branch. Access and email address are re-checked when the email is sent.
- Emails contain no figures. Reports over 1,000 rows or 5 MB arrive as a sign-in link. Daily reports are normally marked PROVISIONAL (period not locked).
- Each built report stores its control totals in the audit log (Reports group), so what was reported can be compared with later figures after the PDF is removed (7 days).
- Failed, missed and stuck runs alert the owner (bell + email); 3 in a row pause the schedule. A delivery marked "may have been sent" must be checked with the recipient; it is never resent automatically.
- Operations: run the exports worker and the normal queue worker; `reports:dispatch-scheduled` runs every minute from the scheduler.

### Accepted risks
1. **Delivery outcome "unknown".** If the worker dies mid-send or recording `sent` fails, the recipient may or may not have the email; a person must check. Accepted (preferred to a duplicate send).
2. **Possible duplicate on an ambiguous SMTP error.** If the mail server accepted the message but the transport still threw, the claim is released and the retry sends again, to the same verified recipient and identical file. Low impact; accepted.
3. **Mail-only failures do not count towards auto-pause.** A run that is built but whose every delivery fails (`mail_error`/`stale`/`unknown`) is not a "failed run" under R10; the owner gets bell rows per delivery. Recommended follow-up (SHOULD, next story): count a run with zero `sent` and at least one `failed` delivery as a failure for R10.
   **Closed by R15 (follow-up).** A settled run with no email sent and at least one failed delivery (any reason, `unknown` included) now counts once towards R10, is audited (`report_schedule.run_not_delivered`) and emails the owner. ACs 44-50. What remains: a run whose recipients pass at build but are all skipped at send (access lost, schedule paused, address changed) is still not a failure. That is a deliberate access decision, not a delivery fault. An `unknown` delivery counts as failed although it may have been delivered, so the pause can be stricter than needed; it is never looser.
4. **Provisional figures.** Daily/weekly emails report unlocked periods; later back-dated entries or voids change the figures. Mitigated by the PROVISIONAL marker, the extracted time and the A4 control totals. Month-end packs after period lock remain a follow-up.
5. **Emailed PDFs leave the system.** Once delivered they cannot be recalled or re-checked; controlled by Super Admin-only scheduling, user-only recipients, access re-check at send and the "do not forward" line.

### Verdict
**SIGNED OFF.** A1-A3 (MUST) and A4-A7 (SHOULD) are implemented and tested; the review/QA fixes strengthen control. No blockers.
