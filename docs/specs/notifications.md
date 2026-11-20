# Spec: In-app notifications (bell, list, optional approval-request email)

## 1. Goal and scope
Backlog **AG-094** (E10, Should, R2, 3 points): "As a user, I want in-app notifications so that I know about approvals, transfers and finished exports." AC: bell icon with unread count; mark as read; optional email for approval requests.

The item wires the hooks the earlier R2 items left for it: `TransferApprovalRequested` / `TransferApprovalDecided` (AG-051 R11), `TransferReceiptRequested` / `TransferReceiptChanged` (AG-052 R11), `ExportFinished` (AG-087 R12), and adds an in-app copy of the backup failure alert (AG-093 R8). **It posts nothing and changes no accounting record.** A notification is a pointer to something that is already recorded and audited elsewhere.

What exists (2026-11-20): the five events are dispatched after commit; listeners `SendExportEmail` and `SendTransferApprovalEmail` send queued emails; `BackupAlerts::send` mails Super Admins with `sendNow`. There is no `notifications` table, no bell and no user preference.

**Out of scope:** push or websockets (polling, R9); browser/desktop notifications; SMS; in-app copies of security and account emails (invitation, password reset/changed, email changed, access changed, year-end, snapshot, restore: they stay email only, Q4); per-user settings for any email other than the approval request (existing emails keep their current rules, R7); mark as unread; deleting a notification from the UI; notifying about income/expense or void/reverse.

## 2. Personas and permissions
**No new permission key.** A notification belongs to one user (`notifiable`). Everyone signed in sees their own bell; nobody can read another user's notifications, Super Admin included (404).

Recipients are decided when the event fires, from the existing keys and roles (spatie teams, per branch):

| Persona | Key / role used to pick them |
|---|---|
| Approver | `transfer.approve` through a **MAIN** role (Branch Admin `*` template has it), plus every Super Admin (AG-051 R3) |
| Requester / sender | `transactions.created_by` of the transfer |
| Receiving branch confirmer | `transfer.acknowledge` through a role in the **receiving** branch (Branch Admin, Accountant, Cashier templates) |
| Sending branch reverser | `transfer.reverse` through a role in the **sending** branch; for a `fund_return`, Super Admins instead (only they may reverse it) |
| Export requester | `exports.user_id` |
| Super Admin | `is_super_admin = true`. Also the independent recipient of every receipt dispute and its settlement (accounting review 1) |

Only `status = 'active'` users are ever notified. The user who caused the event is never notified about their own action.

## 3. User flows
- **F1 Approver (MAIN Branch Admin, active branch KDY).** KDY Accountant requests T1 (KDY Cash -> GAL Cash 150,000.00). Within a minute the bell shows 1. Click bell > dropdown row "KDY-TRF-0012 needs approval" · "KDY -> GAL, 150,000.00, requested by A. Perera" · tag MAIN · "1 min ago" > click > the row is marked read, the branch switches to MAIN, `/transactions/<id>` opens with the Approval panel. If email is on (default), the same approver also gets the email "Transfer KDY-TRF-0012 needs your approval".
- **F2 Requester.** MAIN approves T1. The KDY Accountant's bell shows "Your transfer request KDY-TRF-0012 was approved and posted." (tag KDY). The existing email is still sent (unchanged). Other approvers' unread "needs approval" rows for T1 are marked read automatically (R6).
- **F3 Receiving branch.** With receipt confirmation on, T2 (KDY -> GAL 10,000.00) is posted. GAL Cashier and GAL Accountant get "Confirm receipt of KDY-TRF-0013 from KDY: 10,000.00" (tag GAL). Clicking switches to GAL and opens the transfer with the Receipt panel.
- **F4 Dispute.** GAL Cashier reports a discrepancy on T2. The KDY Accountant (creator), KDY users with `transfer.reverse` and the Super Admins get "GAL reported a discrepancy on KDY-TRF-0013 (10,000.00)" (tag KDY). When GAL later confirms receipt, the same people get "GAL confirmed receipt of KDY-TRF-0013 after a discrepancy".
- **F5 Export.** A background export finishes. The requester gets "Your export is ready" (or "Your export could not be prepared") with no branch tag; clicking opens `/exports?id=<id>` without switching branch.
- **F6 Super Admin, All branches.** Gets approval requests (tag MAIN), backup failures ("Backup FAILED: upload", no tag, opens `/backups`), and anything as creator/requester. Clicking a row with a branch tag switches from All branches to that branch (Super Admin has access to every branch); the Super Admin can switch back to All branches as usual.
- **F7 Lost access.** A user removed from GAL clicks an old GAL row: the row is marked read, the branch does **not** switch, toast "You no longer have access to GAL." (R5).
- **F8 Email preference.** An approver opens `/notifications` > section "Email" > untick "Email me when a transfer needs my approval" > "Preference saved." In-app notifications continue.
- **F9 Mark all.** Dropdown or page > "Mark all as read" > count goes to 0; rows that arrived after the list was loaded stay unread (R8).

## 4. Screens
### S1 Bell in the shell (`layout/shell.component.ts`, new `layout/notification-bell.component.ts`)
- Placed in `header.top`, before `.who`. Icon button, `aria-label="Notifications, N unread"`, min 44x44 px. Badge with the unread count, "99+" above 99, hidden at 0.
- Click toggles a dropdown (closes on outside click, Esc, and navigation). Header: "Notifications" · "Mark all as read" (disabled when 0 unread). Body: latest 10 (read and unread), newest first. Row: kind icon · title (bold when unread, with an unread dot) · body (1 line, ellipsis) · branch code tag (if any) · relative time ("5 min ago", tooltip with the full Asia/Colombo time). Footer: "See all" -> `/notifications`.
- Empty: "No notifications yet." Load error: "Could not load notifications. Try again." with a Retry link; the badge keeps its last value.
- **390px:** the dropdown becomes a full-width panel under the header (16px side gutter, max-height 70vh, own scroll); no horizontal page scroll; the bell stays visible next to the branch switcher; the user name may truncate first.

### S2 Notifications page `/notifications` (new; guard: signed in only, like `/exports`)
- Title "Notifications". Toggle "Unread" / "All" (default Unread if any unread, else All). "Mark all as read" button.
- List (paginated, 20 per page): When (relative + full time) · Branch (code or "-") · Message (title + body) · state (Unread dot / "Read <time>"). Whole row is the link (same open flow as S1, R5). Empty: Unread "You have no unread notifications."; All "No notifications in the last 90 days."
- Section "Email" (shown only when `can_approve` from the preferences endpoint is true): checkbox "Email me when a transfer needs my approval" · help "Emails about your own requests and exports are sent as before." Saves on change; toast "Preference saved."; on error the checkbox reverts with "Could not save. Try again."
- Footer note: "Notifications are kept for 90 days."
- **390px:** each row is a stacked card: line 1 title + unread dot; line 2 body (wraps); line 3 branch tag · time. No table, no horizontal scroll. Buttons wrap under the title.

### S3 Open flow (shared by S1, S2 and the email link `/notifications/:id`)
1. `POST /notifications/{id}/read`. 2. If `branch` is null: navigate to `url`. 3. Else if the user is Super Admin or `branch.id` is in `auth.me().branches` (fresh `/me` result, reloaded if older than the last poll): switch to that branch (existing `switchBranch`, same leave-page rules) and navigate to `url`. 4. Else: toast "You no longer have access to <code>.", stay. The target page still runs its own server checks; a 403/404 there shows the page's normal error state.
- Route `/notifications/:id` runs this flow on load and then replaces itself in history, so email links work regardless of the active branch. Not the user's notification -> page S2 with toast "Notification not found."

## 5. API (all under `auth:sanctum`, **outside** `branch`, like `exports`; inactive user -> 403 "Account inactive.")

| Method, path | Request | Response / errors |
|---|---|---|
| `GET /v1/notifications` | `filter=unread\|all` (default all), `page`, `per_page` (10 or 20; default 20) | `{data:[row], meta:{current_page,last_page,per_page,total}, unread_count, fetched_at, fetched_id}`. `fetched_id` (uuid, optional for clients) is a high-water mark taken at the list query, ordered like row ids (see Storage), to send back as `before_id`. Row: `id` (uuid), `kind`, `title`, `body`, `branch:{id,code}\|null`, `url` (app path, e.g. `/transactions/41`), `created_at`, `read_at`. Only the caller's rows, newest first |
| `GET /v1/notifications/unread-count` | — | `{unread_count}`. The poll endpoint (R9) |
| `POST /v1/notifications/{id}/read` | — | 200 `{id, read_at}`. Idempotent: an already-read row keeps its first `read_at`. Another user's id or unknown id -> 404 |
| `GET /v1/notifications/{id}` | — | 200 one row (same shape and R5 redaction as the list). Used by the email link (S3). Another user's id, unknown id or non-uuid -> 404 "Notification not found." (Super Admin included) |
| `POST /v1/notifications/read-all` | `before` (ISO time, optional; the `fetched_at` the client showed), `before_id` (uuid, optional; the `fetched_id` of the same list) | 200 `{marked: n}`. Marks the caller's unread rows with `created_at <= before` and `id <= before_id` (each condition only when sent; all unread when neither). `before_id` keeps a row written after the list in the same second unread. Invalid `before` or `before_id` -> 422 |
| `GET /v1/notifications/preferences` | — | `{approval_request_email: bool, can_approve: bool}`. `can_approve` = Super Admin or holds `transfer.approve` through a MAIN role |
| `PUT /v1/notifications/preferences` | `approval_request_email` (required, boolean) | 200 same shape. 422 "Approval request email must be true or false." Audit R10 |

No DELETE route. `fetched_at` is the server time of the list query. Row ids are ordered UUIDs (`Str::orderedUuid()`, time first), so `id <= fetched_id` means "written no later than the list query"; clients that send only `before` keep the second-level behaviour.

**Storage: Laravel's built-in database notifications** (`php artisan make:notifications-table`: `id` uuid, `type`, `notifiable_type/id`, `data` text, `read_at`, timestamps). No package, no custom table. One database-only class `App\Notifications\InAppNotification` (not queued, R3) whose `databaseType()` returns the kind, so `type` holds e.g. `transfer.approval_requested`; `data` = `{title, body, branch_id, branch_code, url, transaction_id?, export_id?}`. New column `users.approval_request_email` boolean default true (same migration).

**Schedule:** `notifications:prune` daily 02:30 Asia/Colombo, `withoutOverlapping`, `onOneServer` (R11).

## 6. Business rules
- **R1 Who is notified (kind, recipients, branch tag, link).** Text is built when created (a snapshot); amounts with `Money::format`. Free text typed by users (reject comments, dispute notes, export errors) is **never** copied into a notification; the link shows it.

| Event (condition) | kind | Recipients | Branch tag | url | Title / body |
|---|---|---|---|---|---|
| `TransferApprovalRequested` | `transfer.approval_requested` | approvers (section 2) except the requester | MAIN | `/transactions/{id}` | "<ref> needs approval" / "<from code> -> <to code>, <amount>, requested by <name>" |
| `TransferApprovalDecided` approved / rejected / expired (not cancelled) | `transfer.approval_decided` | requester | sending branch | `/transactions/{id}` | "Your transfer request <ref> was approved and posted." / "...was rejected by <name>." / "...expired after 30 days." |
| `TransferReceiptRequested` | `transfer.receipt_requested` | receiving branch confirmers except the creator | receiving branch | `/transactions/{id}` | "Confirm receipt of <ref> from <from code>: <amount>" |
| `TransferReceiptChanged` to `disputed` | `transfer.receipt_disputed` | creator + sending branch reversers + active Super Admins (fund return: creator + Super Admins); accounting review 1 | sending branch | `/transactions/{id}` | "<to code> reported a discrepancy on <ref> (<amount>)" |
| `TransferReceiptChanged` disputed -> `received` | `transfer.receipt_settled` | as disputed | sending branch | `/transactions/{id}` | "<to code> confirmed receipt of <ref> after a discrepancy" |
| `TransferReceiptChanged` awaiting -> received, or -> cancelled | none | | | | |
| `ExportFinished` ready / failed | `export.ready` / `export.failed` | requester | none | `/exports?id={id}` | "Your export is ready" / "Your export could not be prepared"; body = `BackgroundExports::summaryLine` (+ "Download before <expires>" when ready) |
| Backup or restore-test failure (`BackupAlertNotification`) | `backup.failed` | active Super Admins | none | `/backups` | the email subject / "Stage: <stage>, <time>" (no error text) |

- **R2 Dedupe and exclusions.** One notification per user per event, even if a user qualifies twice (e.g. Super Admin who is also the creator). Never the actor (`userId` of the event or `auth()->id()`); never inactive users; Super Admins are not added as receipt confirmers (they see every open receipt in All mode).
- **R3 Delivery.** New listener `App\Listeners\SendInAppNotifications` handles the five events and writes rows **synchronously** (`notifyNow`, database channel only): the events already fire after commit, the write is a few inserts, and in-app rows must not wait for the queue worker. Any exception is reported and swallowed (as AG-051 review m4): a failed notification never fails the request or the export job. The existing email listeners are **not** changed.
- **R4 Backup alerts.** `BackupAlerts::send` keeps mailing exactly as today. **After** the mail attempt, in its own try/catch, it writes the in-app row for each active Super Admin. It is not added to `via()` (a database error there would make the mail look failed and trigger the fallback emails twice). When the database is down no in-app row is written; the email is the alert.
- **R5 Branch and access.** A notification belongs to a user, not a branch; the list is never filtered by `X-Branch-Id` and shows every branch's rows with their tag. Opening a row re-checks access on the client (S3) **and** the target endpoint re-checks on the server (existing `SetBranchContext`, transaction `show` rules). No notification grants access to anything. A user removed from a branch keeps the old rows until pruned, but **redacted** (accounting review 2): when the list or the count is served, a row whose `branch_id` is not among the user's current branches (Super Admin excepted) is returned with `body = null`, `title` = "Notification for <code> (no longer available)"; the stored row is not changed. Amounts, names and account names are never shown for a branch the user can no longer see.
- **R6 Stale requests.** When `TransferApprovalDecided` fires (any state, cancelled included), all unread `transfer.approval_requested` rows for that `transaction_id` are marked read (`read_at = now`). When `TransferReceiptChanged` fires from `awaiting`, all unread `transfer.receipt_requested` rows for that transaction are marked read. Only `read_at` changes.
- **R7 Email.** New queued `TransferApprovalRequestedMail` (mail only, `afterCommit`), sent by the same listener to each approver recipient whose `approval_request_email` is true. Subject "Transfer <ref> needs your approval"; body: from -> to (branch code, account name), amount, requested by, requested for, expires on (requested_on + 30 days), action "Open the transfer" -> `<frontend_url>/notifications/<uuid of that user's in-app row>` (S3). No note text, no "available" or any other balance, no bank account number (accounting review 3). When the queued mail is sent, the recipient is re-checked: still `active` and still an approver (section 2); otherwise the mail is dropped (accounting review 4). The preference affects **only** this email. Unchanged: approval-decided email (always), export ready/failed (global `exports.email` config), backup alerts, invitation, password and access emails. The email needs the in-app row id, so if that row write fails the email is skipped and the error reported (the dashboard widget still lists the request).
- **R8 Mark as read.** Per row, per user. "Mark all as read" sends `before = fetched_at` so a row created after the list loaded stays unread. Opening a row marks it read before navigating. There is no mark-unread.
- **R9 Polling.** The shell calls `GET /notifications/unread-count` on load, every **60 s** while `document.visibilityState === 'visible'`, immediately when the tab becomes visible again, and after the user's own approve / reject / receipt actions. Polling stops on logout and on a 401. The dropdown loads `GET /notifications?per_page=10` only when opened. Cost: one indexed `COUNT` per signed-in visible tab per minute (about 0.5 req/s for 30 users), on a table kept small by R11. No new index beyond Laravel's `notifiable` morph index; add `(notifiable_id, read_at)` only if the count query shows up slow.
- **R10 Audit.** `PUT /notifications/preferences` writes `AuditLog::record('user.notification_preferences_updated', $user, {approval_request_email: old}, {approval_request_email: new}, null)` only when the value changes. **No audit row** for creating, reading, marking all read, auto-read (R6) or pruning notifications: they are a per-user inbox view, not a change to business data; the business events they point to are already audited (`transfer.approval_requested`, `transfer.approved`, `transfer.receipt_disputed`, `export.*`, `backup.*`). Auditing each read would add a row per click and bury real changes. Decided by the accounting review (Q2).
- **R11 Retention.** `notifications:prune` permanently deletes notification rows with `created_at` older than **90 days** (read or unread), in chunks, and logs the count (`Log::info`). This is the one hard delete in the item and is allowed because a notification is not an accounting record or audit evidence (R10); decided by the accounting review (Q1). The command deletes from the `notifications` table only, by `created_at` only, and never cascades. The exception is recorded in CLAUDE.md under the no-hard-delete rule in the same pull request (accounting review 5).
- **R12 Edge cases.** Approval request with no approver but Super Admins: Super Admins are notified. No active confirmer in the receiving branch: nobody is notified; the dashboard widget is unchanged. Deactivated user: no new rows; existing rows stay. Transfer later voided/reversed: old rows are not changed; the link shows the current state. A user with two tabs: both poll (acceptable). A row whose `url` target no longer exists opens the target page's normal 404 state.

## 7. Acceptance criteria
Data: MAIN, KDY, GAL; approval threshold 100,000.00; receipt confirmation on; today 2026-11-20. KDY Accountant (A. Perera, `transfer.create`, `transfer.reverse`); KDY Branch Admin; MAIN Branch Admin (`transfer.approve` in MAIN); MAIN Accountant (no `transfer.approve`); GAL Cashier and GAL Accountant (`transfer.acknowledge`); GAL Viewer; Super Admin S1; inactive MAIN Branch Admin 2. T1 = KDY Cash -> GAL Cash 150,000.00 requested by the KDY Accountant. T2 = KDY Cash -> GAL Cash 10,000.00 posted by the KDY Accountant.

1. Given T1 is requested, Then exactly the MAIN Branch Admin and S1 each have one unread `transfer.approval_requested` row with `data.transaction_id = T1`, `branch_code = 'MAIN'`, `url = '/transactions/<T1>'`; the KDY Accountant, MAIN Accountant and MAIN Branch Admin 2 (inactive) have none.
2. Given AC 1 with the MAIN Branch Admin's `approval_request_email = true` and S1's false, Then one `TransferApprovalRequestedMail` is queued, to the MAIN Branch Admin, whose action link ends with `/notifications/<that user's row id>`.
3. Given AC 1, When the MAIN Branch Admin approves T1, Then the KDY Accountant has one unread `transfer.approval_decided` row "Your transfer request <ref> was approved and posted." with `branch_code = 'KDY'`; the existing approval-decided email is still queued; S1's `transfer.approval_requested` row for T1 now has `read_at` set; the MAIN Branch Admin (actor) gets no decided row.
4. Given T1 rejected with comment "Split this across two weeks please", Then the requester's row body contains "rejected by" and the approver's name and does **not** contain the comment.
5. Given T1 cancelled by the requester, Then no `transfer.approval_decided` row is created and the approvers' requested rows are marked read.
6. Given T1 expires (`transfers:expire-approvals`), Then the requester gets "...expired after 30 days." and approvers' requested rows are marked read.
7. Given T2 is posted, Then the GAL Cashier and GAL Accountant each get one `transfer.receipt_requested` row with `branch_code = 'GAL'`; the GAL Viewer, the KDY Accountant and S1 get none.
8. Given AC 7, When the GAL Cashier reports a discrepancy, Then the KDY Accountant, the KDY Branch Admin and S1 each get one `transfer.receipt_disputed` row (`branch_code = 'KDY'`), the GAL Cashier gets none, and both GAL `receipt_requested` rows for T2 are read.
9. Given AC 8, When the GAL Accountant confirms receipt, Then the same KDY users and S1 get `transfer.receipt_settled`. Given a fresh transfer confirmed directly from awaiting, Then no row is created for the sender.
10. Given a GAL -> MAIN fund return disputed by MAIN, Then the GAL creator and S1 get `transfer.receipt_disputed`; GAL users with `transfer.reverse` who are not the creator get none.
11. Given a background export by the KDY Accountant becomes ready, Then they get one `export.ready` row with `branch = null`, `url = '/exports?id=<id>'`; with `exports.email` off, the row is still created and no email is sent. A failed export gives `export.failed`.
12. Given a backup run fails at stage upload and two active Super Admins, Then the failure emails are sent as before and each Super Admin has one `backup.failed` row with `url = '/backups'` whose body has no error text. Given the database is unreachable, Then the fallback email is sent once and no exception escapes.
13. Given the in-app write throws (e.g. table missing in a test), When T1 is approved, Then the approval still returns 200 and posts, and the error is reported.
14. Given the KDY Accountant has 3 unread and 2 read rows, When they `GET /notifications?filter=unread`, Then 3 rows newest first, `unread_count = 3`, and no row of another user; `GET /notifications/unread-count` returns 3. The response is the same with any `X-Branch-Id` or none.
15. Given a row of the GAL Cashier, When the KDY Accountant POSTs `/notifications/<that id>/read`, Then 404 and the row is unchanged; S1 also gets 404.
16. Given an unread row, When its owner POSTs `/read` twice, Then both return 200 and `read_at` keeps the first value.
17. Given 3 unread rows and `fetched_at = t`, and a 4th row created after t, When the user POSTs `/read-all` with `before = t`, Then `marked = 3` and the 4th stays unread.
18. Given any notification endpoint, When called by a user whose `status = 'inactive'` (token still valid), Then 403 "Account inactive.".
19. Given no `DELETE` route, Then `DELETE /v1/notifications/<id>` returns 405 and the row remains.
20. Given the MAIN Branch Admin, When they `GET /notifications/preferences`, Then `{approval_request_email: true, can_approve: true}`; the GAL Cashier gets `can_approve: false`. When the MAIN Branch Admin PUTs false, Then 200, one audit row `user.notification_preferences_updated` with old true / new false; PUTting false again writes no second audit row; PUT `"yes"` returns 422.
21. Given marking read, read-all, auto-read (R6) and prune, Then no audit row is written.
22. Given rows created 91 and 89 days ago (read and unread), When `notifications:prune` runs, Then only the 91-day rows are removed; no `transactions`, `ledger_entries`, `audit_logs` or `exports` row changes.
23. (UI) Given 0 unread, Then the bell shows no badge; given 120 unread, Then the badge shows "99+" and the aria-label says "Notifications, 120 unread".
24. (UI) Given a new row is created while the tab is visible, Then the badge updates within 60 s without reload; while the tab is hidden no `unread-count` request is sent (network log), and one is sent when it becomes visible.
25. (UI) Given the MAIN Branch Admin with active branch KDY, When they click the T1 row in the dropdown, Then the row is marked read, the switcher shows MAIN and `/transactions/<T1>` shows the Approval panel.
26. (UI) Given the GAL Cashier was removed from GAL after AC 7, When they click the GAL row, Then it is marked read, the branch does not change and the toast says "You no longer have access to GAL.".
27. (UI) Given S1 in All branches mode, When S1 clicks a `backup.failed` row, Then `/backups` opens and the mode stays All branches; clicking a MAIN-tagged row switches to MAIN.
28. (UI) Given the email link `/notifications/<id>` opened by its owner while active branch is GAL, Then the S3 flow runs (switch to MAIN, open the transfer) and Back does not return to `/notifications/<id>`. Opened by another user, Then `/notifications` with "Notification not found.".
29. (UI) Given the GAL Cashier, Then `/notifications` has no "Email" section; for the MAIN Branch Admin it shows the checkbox ticked, and unticking it shows "Preference saved." and survives reload.
30. (UI, 390px) Given a 390px-wide viewport, Then the bell is visible in the header, the dropdown opens as a full-width panel with its own scroll, `/notifications` shows stacked cards, and there is no horizontal page scroll; the bell and each row are at least 44 px tall.
30a. Given T2 was posted by the KDY Branch Admin (the only KDY user with `transfer.reverse`), When the GAL Cashier reports a discrepancy, Then S1 still gets a `transfer.receipt_disputed` row (accounting review 1).
30b. Given the GAL Cashier has a T2 `transfer.receipt_requested` row and is then removed from GAL, When they `GET /notifications`, Then that row has `body = null` and a title without amount or names, the stored `data` is unchanged; after they are re-added to GAL the full row is shown again. S1 always sees full rows (accounting review 2).
30c. Given AC 2, When the MAIN Branch Admin is deactivated (or loses `transfer.approve` in MAIN) before the queue runs, Then the mail is not sent. The sent mail's body contains the amount and branch codes and contains no balance, no "Available" figure and no note text (accounting review 3, 4).
30d. Given every notification flow in AC 1-22 (create, read, read-all, auto-read, prune), Then `ledger_entries`, `transactions` and `accounts.current_balance` are unchanged (accounting review 6).
31. Given `php artisan test` and `./vendor/bin/pint --test`, Then both pass; `npx ng build` passes.

## 8. Open questions (assumption in bold)
- **Q1 (accountant) Retention: may notifications be hard-deleted?** CLAUDE.md says nothing is hard-deleted, but that rule protects accounting records and their audit trail. A notification is a derived copy of an audited event. **Decided (accounting review): delete after 90 days (R11), with conditions in finding 5.** Alternative considered: keep forever (table grows ~a few thousand rows a month; harmless but the list gets long) or soft-archive. Please confirm or set N.
- **Q2 (accountant) Is it acceptable not to audit reads?** **Decided (accounting review): yes (R10).** A "who saw the approval request when" trail is not evidence of anything the system relies on; the approval and receipt rows already record who acted and when. Only the email preference change is audited.
- **Q3 Default of the approval-request email?** **On** for everyone (only approvers ever receive it). Approvers are few, requests expire in 30 days and the email shortens waiting. Users turn it off in S2.
- **Q4 In-app copies of security/account emails (invitation, password, email changed, access changed, year-end)?** **No.** They concern sign-in or are sent to people not signed in; the backlog asks for approvals, transfers and exports. Backup failures are included because the task brief asks for Super Admins to see them.
- **Q5 Notify the sender when a transfer is confirmed without a dispute?** **No** (noise; the Transfers report filter covers it, as transfer-receipt Q6). Only disputes and their settlement.
- **Q6 Email content.** The approval-request email carries the amount and branch codes, like the existing approval-decided email. **Decided (accounting review): acceptable**, with the limits in findings 3 and 4; unlike export emails it carries no report figures.
- **Q7 Polling interval.** **60 s** while visible. Push is out of scope; revisit only if users ask for faster.

## Accounting review (spec, 2026-11-20)
Verdict: **approved with changes.** The changes below have been written into the spec text above (R1, R5, R7, R11, section 2, F4, AC 8, 9, 30a-30d, Q1/Q2/Q6).

**Decisions on the flagged questions**
- **Q1 Retention, hard delete after 90 days: agreed.** The no-hard-delete rule protects accounting records and their audit trail. A notification is a derived pointer to an event that is already recorded and audited (`transfer.*`, `export.*`, `backup.*` audit rows, the transaction itself); deleting it loses no evidence and changes no figure. Statutory record retention applies to the books and source documents, not to an inbox. 90 days is longer than the 30-day approval expiry, so no live request loses its pointer. Conditions in finding 5.
- **Q2 Reads not audited: agreed.** Nothing in the control framework depends on who saw a notification. The evidence of the controls is who approved, rejected, confirmed, disputed or reversed, and when; those are audited by AG-051/AG-052. Auditing reads would add noise to the audit log. Auditing the email preference change (R10) is right: switching off the approval email is a change to how a control reaches its owner.
- **Q6 Amount in the approval-request email: agreed.** The approver cannot judge the request without the amount, the recipients are only people who may approve it, and the approval-decided email already carries the amount. Limits in findings 3 and 4.

**Findings**
1. **MUST: a receipt dispute must reach someone independent of the sending branch.** As drafted, a dispute went only to the creator and the sending branch's reversers. In a small branch these can be one person (e.g. the Branch Admin sent the cash and is the only `transfer.reverse` holder), who would then be the only one told that cash went missing, and could reverse and re-post quietly. A shortage in transit is a possible loss or misappropriation and the owner must hear of it. Rule: `transfer.receipt_disputed` and `transfer.receipt_settled` also go to every active Super Admin, for every transfer type (actor still excluded, R2 dedupe applies). AC 8, 9 updated; AC 30a added.
2. **MUST: no amounts or names for branches the user can no longer see.** R5 let a user removed from a branch keep reading amounts, names and account names of that branch for up to 90 days. Access removal must take effect at once. Rule: redact at read time (R5 text); stored rows unchanged, no new audit. AC 30b.
3. **MUST (Q6 limit): the approval-request email carries the amount, branch codes, account names, requester and dates only.** No "available now" or other balance, no bank account number, no free text (note, comment). Email leaves the system and cannot be withdrawn after access is removed. R7 text; AC 30c.
4. **SHOULD: re-check the recipient when the queued approval email is sent.** A user deactivated or removed as approver between the request and the queue run must not get it. R7 text; AC 30c.
5. **MUST (Q1 conditions): the prune is narrow and documented.** Deletes from `notifications` only, by `created_at`, no cascade; it never touches `transactions`, `ledger_entries`, `audit_logs` or `exports` (AC 22 already tests this). Record the exception in CLAUDE.md under "Nothing is hard-deleted" in the same pull request ("Exception: `notifications` rows are pruned after 90 days, AG-094"), so it is not read later as a precedent for deleting anything else.
6. **Fine: no posting.** Nothing in the item calls `LedgerService`, writes `ledger_entries`, or changes `accounts.current_balance`, `transactions.status` or amounts; R6 changes only `read_at` on notification rows. AC 30d makes this testable for the whole flow, not only the prune.
7. **Fine: segregation in the approval path.** The requester is never notified as approver (R2, actor excluded), approvers are exactly the AG-051 R3 set (MAIN `transfer.approve` holders and Super Admins), stale requests are closed on any decision (R6), and a notification grants no access (R5, server re-checks on the target page).
8. **Fine: other recipients.** Receipt requests go only to `transfer.acknowledge` holders in the receiving branch, never the creator (matches transfer-receipt R5) and never Viewers; decisions only to the requester; exports only to the requester; backup failures only to Super Admins, with no error text. User-typed text (reject comments, dispute notes) is never copied (R1). Inactive users get no rows and no endpoint access (R2, AC 1, 18).
9. **Fine: audit of the preference change (R10)** with old/new values, only on a real change.

## Accountant sign-off (build, 2026-11-20)
Checked `git diff feature/transfer-approvals...feature/notifications` at 50e16d2 (code, tests, QA and review reports). `php artisan test`: 733 passed, 1 skipped; `pint --test` passed; `npx ng build` passed.

| Finding | Status | Evidence |
|---|---|---|
| 1 MUST dispute reaches someone independent | DONE | `SendInAppNotifications::handleReceiptChanged` adds every active Super Admin to disputed and settled rows, all transfer types, actor excluded. `test_ac30a_a_dispute_reaches_a_super_admin_when_the_sender_is_the_only_reverser`, AC 8-10 tests |
| 2 MUST no amounts or names after access is removed | DONE | `InAppNotifications::present` redacts at read time (title without amount or names, `body = null`, stored row unchanged) in the list and in `GET /notifications/{id}`. Also redacts `transfer.approval_requested` rows once the user is no longer an approver (they are tagged MAIN but describe another branch's transfer): this closes the gap the reviewer found and goes beyond what I asked. `test_ac30b_...`, `test_review1_approval_requests_are_redacted_once_the_user_is_no_longer_an_approver`, `test_r5_redaction_helper_never_changes_super_admin_rows` |
| 3 MUST approval email content | DONE | `TransferApprovalRequestedMail::toMail`: ref, branch codes and account names, amount, requester, requested-for date, expiry, link. No balance, "available", bank account number or note. `test_ac30c_...` (text and HTML bodies) |
| 4 SHOULD re-check at send time | DONE | `TransferApprovalRequestedMail::shouldSend`: recipient fresh, active, still an approver, email preference still on, and the request still pending. `test_ac30c_...`, `test_review2_queued_approval_email_is_dropped_when_decided_or_switched_off` |
| 5 MUST narrow, documented prune | PARTLY (open condition) | Narrow: `NotificationsPrune` deletes from `notifications` only, by `created_at` only, in chunks, no cascade, logs the count; its docblock records the exception and says it is no precedent. `test_ac22_...`, `test_ac30d_...`. CLAUDE.md is intentionally not edited in this branch: the project owner has been asked to allow the 90-day delete |
| 6 no posting (AC 30d) | DONE | The only `LedgerService` change passes the actor id into the two after-commit events; no balance, line or status change. `test_ac30d_no_notification_flow_touches_the_ledger` (ledger state, every `current_balance`, `ledger:verify`) |
| 7-9 segregation, recipients, preference audit | DONE (as reviewed) | Requester/actor never notified as approver (`test_review7_...`, AC 1); receipt requests only to receiving-branch `transfer.acknowledge` holders; free text never copied (AC 4); preference change audited with old/new only on change, reads not audited (AC 20, 21, `test_r10_...`) |

**Mark all as read (`before_id`).** Fine from a control view: it only narrows which rows get `read_at`, changes nothing else and writes no audit row (R10). Section 5 now documents `fetched_id` and `before_id`, and the `GET /notifications/{id}` route the reviewer accepted.

**Figures and role templates.** No figure, report or role template changes in this item. Amounts in notifications and the email use `Money::format` from the stored transaction (e.g. "150,000.00").

**Verdict: SIGNED OFF, with one open condition.** Nothing in the code must change. Before the pull request is merged, the project owner must decide on the 90-day delete (Q1, finding 5):
- If they agree, add to CLAUDE.md under "Nothing is hard-deleted", in this pull request: "Exception: `notifications` rows are pruned after 90 days, AG-094."
- If they do not agree, remove the `notifications:prune` schedule (or the command) and change R11 to "kept", before merge. No other change is needed: notifications are not accounting records either way.
