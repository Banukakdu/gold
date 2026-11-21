# Spec: Bank statement copy on a reconciliation statement

## 1. Goal and scope
**AG-033b** (follow-up to AG-033, from the bank-reconciliation accounting review finding 11 and sign-off row 11): "As an Accountant, I want to attach the bank statement copy (PDF or image) to a completed bank reconciliation statement so that the evidence for the statement balance sits with the statement." Until now: "Attachments (AG-062) are for income/expense entries only … the printed reconciliation is filed with the bank statement and signed."

Fixed decisions:
- **Reuse AG-062 as it is.** Use the same `attachments` table, the same private `attachments` disk, and the same `AttachmentFile::inspect` checks (magic bytes, extension match, 5 MB limit, filename cleaning R7). Also reuse the sha256 check on every download (409 / 410 with audit rows), the same download headers, the same DB immutability triggers, `attachments:verify`, and the same "remove, never delete" rule. Do not build a second table, disk or validator.
- The parent is a **`bank_statements` row** (a completed reconciliation). It is not the bank account and not a ledger line.
- **It posts nothing.** It never writes `ledger_entries`, `accounts.current_balance`, `bank_reconciliation_marks` or the stored figures of `bank_statements`. Adding or removing a file never makes a statement "Changed since completion" (AG-033 R8).
- **Backups:** these files live on the `attachments` disk, so the AG-093 archive already includes them (backups.md section 1, R3 "files/attachments/..."). Their rows are in the `attachments` row count, and `backup:verify` and `attachments:verify` check them by sha256. No change is needed in backups. Background exports (AG-087) use the separate `exports` disk and are not affected. Exports carry file metadata only, never the file.

Out of scope: attachments on draft reconciliations (before Complete), bank accounts, ledger lines, transfers, or cash and wallet accounts. Also out of scope: importing or parsing the statement file (CSV/OFX/OCR), embedding the file in the reconciliation PDF, a "copy required before Complete" rule (Q2), a statement picker for users without `account.reconcile` (Q4), virus scanning (AG-062 Q5), and thumbnails.

## 2. Personas and permissions
No new key. Real keys: `account.reconcile`, `report.view`, `audit.view`. The rights follow the statement rights a user already has, the same principle as AG-062 section 2.

| Action (in the statement's branch) | Rule (server side) |
|---|---|
| List and download active files | `account.reconcile` **or** `report.view` |
| Add, replace or remove a file | `account.reconcile` |
| Download a removed or replaced file | (`account.reconcile` or `report.view`) **and** `audit.view` |

| Template | List / download | Add / replace / remove | Removed files |
|---|---|---|---|
| Super Admin | every branch, All mode included | yes | yes |
| Branch Admin (`*`) | own branch | yes | yes |
| Accountant (`account.reconcile` on new installs; on upgraded databases only after the Super Admin grants it, AG-033 Q6) | own branch (`report.view`) | only with `account.reconcile` | yes |
| Viewer (`report.view`, `audit.view`) | own branch | no | yes |
| Cashier | no (has neither key) | no | no |

**Why download is "`account.reconcile` or `report.view`" and not "`account.view`".** The bank statement copy shows every movement and the balance of the account. That makes it at least as sensitive as the reconciliation statement it supports, and the statement reprint (S3 with `statement_id`) already needs `report.view`. With `account.view` the Cashier would get it, and the Cashier cannot reconcile. The independent checker, who is the compensating control in AG-033 Q4 (Viewer or owner, "Checked by"), must be able to open the copy next to the reprint. So `report.view` is enough, and `account.reconcile` is not required.

## 3. User flows
**F1 Accountant attaches after completing (KDY, desktop).** S1 (`/accounts/:id/reconcile`) -> Complete reconciliation -> the toast "KDY Bank reconciled to 31 Oct 2026." now has the button "Attach bank statement". The button opens the History row's Files panel (S1a). The Accountant picks `KDY-Bank-Oct-2026.pdf`, it uploads with a progress bar, and the row shows "1 file".

**F2 Later upload.** S1 -> History -> the row "31 Oct 2026" -> "Files (0)" -> "Add files" -> pick 2 scans (page 1, page 2) -> both are listed.

**F3 Wrong month uploaded (replace).** In the Files panel, on row `Sep.pdf`, the Accountant clicks Replace, picks `Oct.pdf` and enters the reason "September statement uploaded by mistake" -> confirm. `Oct.pdf` becomes active. `Sep.pdf` moves under "Show removed" with "Replaced by Oct.pdf, by ACC on 2026-11-03: September statement uploaded by mistake".

**F4 Checker (Viewer, KDY).** The Viewer opens the link to `/reports/bank-reconciliation?statement_id=…` that the preparer sent or that is on file. The statement section shows "Bank statement attached: Oct.pdf (PDF, 1.2 MB), uploaded by ACC on 2026-11-03 10:05, sha256 3fa9…". The Viewer clicks Download, compares the copy with the reprint and signs the printed PDF.

**F5 Super Admin, All branches.** S1 picker -> GAL Bank -> History -> Files: works as F1 to F3. The file and its audit rows belong to GAL, the statement's branch, never to the header.

**F6 Locked month or closed year.** The statement date 31 Oct 2026 is in a locked month. The Files panel shows "October 2026 is locked: files can be added as evidence but not removed or replaced." Remove and Replace are hidden. A file added now gets the tag "Added after close".

**F7 Undone statement.** The Files panel of an undone statement is read-only. It shows "This statement was undone. Attach the bank statement to the statement that replaces it." Its files stay listed and downloadable, and its reprint still lists them. After the account is completed again for the same date, the Accountant uploads the copy to the new statement. If the bytes are the same, the note "This file is also attached to the undone statement of 31 Oct 2026." is shown, for information only.

## 4. Screens
### S1a Files panel on S1 History (`reconcile.component.ts`, history row)
- History gets a new column **Bank statement**. It shows "N file(s)", or the warning tag "No copy" when the statement is `completed` and has 0 active files. When some files were removed it adds "(M removed)". The cell is a button that expands the row's Files panel. One panel is open at a time.
- Panel title: "Bank statement copy: KDY Bank, 31 Oct 2026 (2 of 5)".
- Table columns: Name, Type (JPG/PNG/PDF), Size, Uploaded by (name and tag), Uploaded at (Asia/Colombo, `YYYY-MM-DD HH:mm`), sha256 (first 12 characters, full value in a tooltip, with a copy button). Then the tags "Added after close" and "Also on <date> statement", and the actions **Download**, **Open**, **Replace** and **Remove**.
- **Add files** (`<input type="file" multiple accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">`). Hint: "PDF or photo of the bank statement for this period. JPG, PNG or PDF, up to 5 files, 5 MB each." The client checks match AG-062 S1, and the server is the authority. Files are sent one per request, each with a progress bar. At 5 active files the button is disabled with the tooltip "5 files attached. Remove one first."
- **Replace dialog**: File (required, a single file), Reason (textarea, required, 3-500 characters). Text: "Replace <name>? The old file is kept for the audit trail."
- **Remove dialog**: Reason (required, 3-500). Text: "Remove <name> from the statement of 31 Oct 2026? The file is kept for the audit trail and can still be seen by auditors."
- "Show removed (N)": removed rows are struck through, with "Removed by <name> on <date>: <reason>" or "Replaced by <new name>, by <name> on <date>: <reason>". Download needs `audit.view`.
- Buttons are shown only when the action is allowed: Add, Replace and Remove need `can_add` or `can_remove` from the API. The F6 and F7 notes are shown when they apply.
- Empty: "No bank statement copy attached. Attach it, or file the paper bank statement with the printed reconciliation."
- Errors: the API `message` in `.alert`, per file for uploads.
- Download and Open use `HttpClient` with `responseType: 'blob'` and the server's filename, as AG-062 S2 does.

### S3 Reprint (`/reports/bank-reconciliation?statement_id=…`, screen, PDF and Excel)
A new block **"Bank statement copy"** goes after "Balance per bank statement / Difference" and before the late entries:
- One line per active file: "Bank statement attached: <name> (<TYPE>, <size>), uploaded by <name> on <YYYY-MM-DD HH:mm>, sha256 <64 hex>". The tag " - added after close" is added when it applies. On the screen the sha256 is shortened to 12 characters with a tooltip and the name is a Download link (needs the permissions in section 2). The PDF and Excel print the full sha256, so the checker can compare it with the file.
- One line per removed or replaced file: "Removed: <name>, sha256 <64 hex>, by <name> on <date>: <reason>" (or "Replaced by <new name> …").
- None: "No bank statement copy attached. File the paper bank statement with this printout."
- The block is shown for completed and undone statements. It is not shown on the plain As-at report (without `statement_id`).

### 390px layout
- The History rows are already cards (AG-033). Each card gets a line "Bank statement: 2 files" or "No copy" with a full-width "Files" button.
- The Files panel becomes a full-screen sheet with a sticky header that shows the title and a close button (44px).
- Each file is a card:
  - line 1: the name (wraps, with a title tooltip);
  - line 2: "PDF · 1.2 MB · ACC · 2026-11-03 10:05";
  - line 3: "sha256 3fa9c01b77de…" (monospace, tap to copy) and its tags;
  - line 4: the buttons Download, Open, Replace and Remove, in a 2x2 grid, each at least 44px tall.
- "Add files" is a full-width button at the top. The file input lets the phone offer the camera.
- Dialogs are full width with a 16px gutter.
- On S3 the block stacks one card per file.
- There is no horizontal page scroll at 360px or 390px.

## 5. API (all under `auth:sanctum` + `branch`)
`{account}` is bound through `BranchScoped`, as in AG-033. `{statement}` and `{attachment}` use scoped binding (`->scopeBindings()`). A statement that is not on `{account}` returns 404, and an attachment that is not on `{statement}` returns 404. A non-bank account returns 422 "Only bank accounts can be reconciled." These routes go **outside** the existing `perm:account.reconcile` group, because list and download also accept `report.view`. The controller checks the section 2 rules with `canDo`.

| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `GET /v1/accounts/{account}/reconciliation/statements/{statement}/attachments` | `account.reconcile` or `report.view` | `include_removed` (0/1) | `{data:[Attachment], can_add, max: 5, note}` |
| `POST /v1/accounts/{account}/reconciliation/statements/{statement}/attachments` | `account.reconcile`, `throttle:attachments-upload` | multipart `file`. For a replace, also `replaces_id` and `reason` (3-500) | 201 `Attachment` (+ `replaced: Attachment` on a replace). 403 / 422 per R2 to R5 |
| `GET …/statements/{statement}/attachments/{attachment}/download` | as list (+ `audit.view` if removed), `throttle:attachments-download` | none | the streamed file. 403 "Only auditors can download removed files." 409 / 410 as AG-062 |
| `POST …/statements/{statement}/attachments/{attachment}/remove` | `account.reconcile` | `reason` (required, 3-500) | 200 `Attachment`. 422 per R4 and R5 |
| `GET /v1/accounts/{account}/reconciliation` (existing) | unchanged | unchanged | each `statements[]` item gains `attachments_count` (active) and `attachments_removed_count` |
| `GET /v1/reports/bank-reconciliation` and `/export` with `statement_id` (existing) | unchanged | unchanged | `statement` gains `attachments:[Attachment incl. removed]`. No file bytes |

`Attachment` is the AG-062 shape with `transaction_id` replaced by `bank_statement_id`: `{id, bank_statement_id, original_name, mime, extension, size_bytes, sha256, added_after, also_on:[{statement_id, statement_date, status}], uploaded_by:{id,name,tag}, created_at, removed_at, removed_by, removal_reason, replaced_by_id, can_remove}`. It has no `path` and no URL. There is no DELETE route.
`bootstrap/app.php`: the 413-to-422 rule ("Each file may be at most 5 MB.") also matches `api/v1/accounts/\d+/reconciliation/statements/\d+/attachments`.

**Schema (one migration `…_bank_statement_attachments.php`, ADD only)**
- `attachments.transaction_id` becomes **nullable**.
- New column `bank_statement_id` (FK `bank_statements`, nullable, **restrict** on delete).
- New column `replaced_by_id` (FK `attachments`, nullable, restrict). It is set in the same update as the removal fields.
- Index (`bank_statement_id`, `removed_at`).
- New `BEFORE INSERT` trigger `attachments_one_parent`. It aborts unless exactly one of `transaction_id` and `bank_statement_id` is set. Use MySQL `SIGNAL` and SQLite `RAISE(ABORT)`. Do not use a CHECK constraint, because SQLite cannot add one with ALTER.
- Rebuild `attachments_no_update` so that `bank_statement_id` is frozen and `replaced_by_id` joins the removal columns. On SQLite, the column change rebuilds the table and drops its triggers, so the migration must recreate all three triggers after the change. `MigrationPreflight::assertCanCreateTriggers()` runs first.
- Rollback is refused while any row has `bank_statement_id` set.
- Model `Attachment` gains the `bankStatement()` relation, and `REMOVAL_COLUMNS` gains `replaced_by_id`. The model guards are updated to match the triggers.
- The disk path is `{branch_id}/bank-statements/{statement_id}/{uuid}.{ext}`. Files are `0440`, as in AG-062.

**Code that assumes `transaction_id` is set, and must change:**
- `attachments_count`, the Files filter and `also_on` for entries filter `transaction_id is not null`.
- `PeriodLockController` review counts (`files_removed`) take only rows whose `auditable_type` is Transaction. Today it looks up `auditable_id` as a transaction id.
- `attachments:verify` prints the Ref column as "KDY Bank 2026-10-31 (statement #12)" for statement files.
- `AuditLogController::subject` gets a `BankStatement::class` case, "<account code> <account name>, statement <date>".

## 6. Business rules
- **R1 Branch isolation.**
  - `{account}`, `BankStatement` and `Attachment` are all `BranchScoped`. A statement or file of another branch returns 404, including a forged id.
  - `attachments.branch_id` is always the statement's `branch_id`, never the header's.
  - Super Admin in All mode: every branch. With X-Branch-Id KDY: KDY only.
  - A user who is deactivated or removed from the branch is refused by `SetBranchContext` (403) before any of this code runs.
- **R2 Who.** Section 2.
  - Without either key: 403 "Missing permission: report.view".
  - Add, replace or remove without the key: 403 "Missing permission: account.reconcile".
  - Any `account.reconcile` holder may remove or replace any file of the statement. There is no uploader-only rule, because only reconcilers hold the key.
- **R3 Limits** (inside a DB transaction with `lockForUpdate` on the `bank_statements` row; the stored figures are read only):
  - at most 5 active files: 422 "A statement can have at most 5 files. Remove one first.";
  - at most 10 in total, removed included: 422 "This statement already had 10 files uploaded (including removed ones). No more can be added.";
  - the same sha256 active on the same statement: 422 "This file is already attached to this statement.";
  - the same sha256 active on another statement of the **same account**: accepted, with `also_on` filled and the S1a tag shown. Not checked across accounts or branches.
  - A replace counts as one new file. It is allowed at 5 active files, because the count does not grow.
- **R4 Statement state.**

  | Statement | Add | Replace / remove |
  |---|---|---|
  | `completed`, statement date open | yes | yes |
  | `completed`, statement date locked (`PeriodLock::isLocked($st->branch_id, $st->statement_date, share: true)`, cumulative) or in a closed fiscal year (`YearEnd::closedYearOf`) | **yes**, `added_after = "close"` | no: 422 "The period 2026-10 is locked." / "The fiscal year 2026 is closed." (Super Admin too, as AG-062) |
  | `undone` | no: 422 "This statement was undone. Attach the bank statement to the statement that replaces it." | no: 422 "Files of an undone statement cannot be removed." |

  - The lock is checked against the **statement date**, not today and not the upload date. It is re-checked with a locking read inside the transaction.
  - A file that is already removed: 422 "This file has already been removed."
  - Undoing a statement keeps all its files. The undo is not blocked by files.
  - There is no attachment before Complete, because no `bank_statements` row exists yet.
- **R5 Files.** AG-062 R4 (validation, in its order, with its messages), R6 (write order: file first, then the row and audit row in one transaction, roll the file back on DB failure; download headers, sha256 on every download, 409/410) and R7 (filenames) apply unchanged.
  - A replace is one transaction. It inserts the new row, then sets `removed_at/by`, `removal_reason` and `replaced_by_id` on the old row.
  - `replaces_id` must be an active file of the same statement, else 422 "Choose an active file of this statement to replace."
- **R6 Audit** (`AuditLog::record`, auditable = the **BankStatement**, `branch_id` = the statement's branch). Reuse the AG-062 events in the existing "Attachments" group, and add `parent: "bank_statement", account_id, statement_date, statement_status` to the values:
  - `attachment.added`: new values `attachment_id, original_name, mime, size_bytes, sha256, added_after, also_on, replaces_id|null`;
  - `attachment.removed`: old values `attachment_id, original_name, sha256, uploaded_by, uploaded_at, added_after`; new values `reason, removed_at, replaced_by_id|null, self`;
  - `attachment.downloaded`, `attachment.missing`, `attachment.integrity_failed`: as AG-062.
  - A replace writes one `added` row and one `removed` row.
  - List calls and failed validation write no audit row.
- **R7 Nothing else changes.** The stored statement figures, `max_entry_id`, "Changed since completion", the `ledger:verify` V10 check, marks, the undo rules and the period lock review figures stay as they are.

## 7. Acceptance criteria
Setup: as AG-033 section 8 (MAIN, KDY, GAL; KDY Bank, GAL Bank, KDY Cash; SA, ACC with `account.reconcile` and `report.view`, BA, VW, CSH, GACC). S1 = completed KDY Bank statement 2026-10-31. `Storage::fake('attachments')`. Files as AG-062 AC setup (`r1.jpg`, `inv.pdf`, `max.pdf`, `big.pdf`, `fake.pdf`, `evil.jpg`, `empty.pdf`), plus `oct.pdf` and `sep.pdf` (real PDFs).

1. Given ACC, when uploading `oct.pdf` to S1, then 201. The response has `bank_statement_id` S1, `mime` application/pdf, a 64-char `sha256`, `added_after` null and no path or URL. The file is at `{KDY}/bank-statements/{S1}/<uuid>.pdf` with mode 0440. One `attachment.added` row (branch KDY, auditable S1, `parent` bank_statement) holds the R6 values.
2. Given `max.pdf`, then 201. Given `big.pdf`, `empty.pdf`, `evil.jpg` or `fake.pdf`, then 422 with the AG-062 R4 message for each, and nothing is stored. A body over `post_max_size` returns 422 "Each file may be at most 5 MB.", not 413.
3. Given 5 active files on S1, a 6th upload returns 422 "A statement can have at most 5 files. Remove one first." A replace at 5 files returns 201. After 10 uploads in total, the next returns 422 with the R3 total message.
4. Given `oct.pdf` active on S1, uploading the same bytes to S1 returns 422 "This file is already attached to this statement.". Given S1 is undone and S1' is completed for the same date, the same bytes on S1' return 201 with `also_on` [S1, undone], and S1a shows the tag.
5. Given two parallel uploads to S1 with 4 active files, then exactly 5 active files exist afterwards and the other request gets 422.
6. Given ACC replaces `sep.pdf` with `oct.pdf` and the reason "Wrong month", then 201. `oct.pdf` is active. `sep.pdf` has `removed_at`, `removed_by` ACC, the reason and `replaced_by_id` = the new id. Both files stay on disk. One `added` audit row (with `replaces_id`) and one `removed` audit row (with `replaced_by_id`) exist. A reason under 3 characters, or a `replaces_id` that is removed or belongs to another statement, returns 422 and changes nothing.
7. Given ACC removes `oct.pdf` with a reason, then 200 and `attachments_removed_count` is 1 on S1's history item. Removing it again returns 422 "This file has already been removed.".
8. Given VW, list and download of an active file return 200, and add, replace and remove return 403 "Missing permission: account.reconcile". Given CSH, every route returns 403 "Missing permission: report.view". Given a removed file, VW and BA get 200 and a user with `report.view` but without `audit.view` gets 403 "Only auditors can download removed files.".
9. Given ACC (KDY header), any route for a GAL Bank statement or a GAL file returns 404. A KDY file requested under another KDY statement or another account returns 404. Given SA in All mode, all routes work on a GAL statement, and the new row and every audit row have `branch_id` GAL. Given SA with X-Branch-Id KDY, a GAL statement returns 404.
10. Given ACC removed from KDY or deactivated, any route returns 403 and returns no bytes.
11. Given KDY October locked (or only KDY November locked, under the cumulative rule), when ACC uploads to S1, then 201 with `added_after` "close", and S1a and the reprint show "Added after close". When ACC or SA removes or replaces a file of S1, then 422 "The period 2026-10 is locked.". The same applies when 2026-10-31 is in a closed fiscal year, with the fiscal-year message.
12. Given S1 undone, upload returns 422 "This statement was undone. Attach the bank statement to the statement that replaces it.", remove returns 422 "Files of an undone statement cannot be removed.", and list and download still return 200. The undo itself succeeded with files attached.
13. Given any file action on S1, then `ledger_entries`, `accounts.current_balance`, `bank_reconciliation_marks` and the stored `bank_statements` figures are unchanged, `changed_since_completion` stays false, and `ledger:verify` exits 0.
14. Given a file whose bytes on disk were overwritten, download returns 409 with no bytes and writes one `attachment.integrity_failed` row (auditable S1). Given a deleted file, download returns 410 and writes one `attachment.missing` row. `attachments:verify` lists both by "KDY Bank 2026-10-31 (statement #id)" and attachment id, and exits 1.
15. Given a download of `Fáktura 11.pdf`, the body has the same sha256 and the headers match AG-062 AC 24. One `attachment.downloaded` row is written. A list call writes none.
16. Given a raw query that deletes a statement file row, changes its `bank_statement_id` or `sha256`, or inserts a row with both `transaction_id` and `bank_statement_id` set (or neither), then it throws and nothing changes. This holds on SQLite and in the `api-mysql` job. `bank_statements` rows with files cannot be deleted (FK restrict).
17. Given the migration on a database with AG-062 entry attachments, then the existing rows, their triggers' behaviour and all AG-062 tests are unchanged. Rollback is refused once a statement file exists.
18. Given a KDY entry attachment removed in October and a statement file removed for S1, when SA opens the Lock confirm for KDY October, then `files_removed` counts only the entry file. The Transactions list `attachments_count` and the Files filter ignore statement files.
19. Given S1 with `oct.pdf` active and `sep.pdf` replaced, the S3 reprint (screen, PDF, Excel) shows "Bank statement attached: oct.pdf (PDF, …), uploaded by ACC on …, sha256 <64 hex>" and the "Replaced by oct.pdf …" line. On the screen the sha256 is shortened with a tooltip. Given a statement without files, the reprint shows "No bank statement copy attached. File the paper bank statement with this printout.". The As-at report without `statement_id` has no such block.
20. Given S1 History, the Bank statement column shows "1 file (1 removed)", and a completed statement with no file shows "No copy". After Complete, the toast's "Attach bank statement" button opens S1a for the new statement.
21. Given the audit log, the "Attachments" group returns the statement file rows. Their Subject reads "11002 KDY Bank, statement 2026-10-31" and their values have `parent` bank_statement.
22. Given a 390px viewport, the Files sheet and the S3 block show one card per file, with Download, Open, Replace and Remove at least 44px tall and the sha256 line wrapping, and there is no horizontal scroll.

## 8. Open questions (assumption in bold, work proceeds on it)
- **Q1 (product owner)** Are 5 MB per file and 5 files per statement enough? A busy account's monthly e-statement PDF can be larger. **Assumption: yes, the same limits as AG-062. A larger statement is split or scanned in parts.** Raising the limit would be one constant per parent type, plus the PHP and web-server limits.
- **Q2 (product owner / accountant)** Must a copy be attached before Complete, or before the month can be locked? **No. It is optional, and "No copy" is visible on History and on the reprint.** A blocking rule, or a line on the lock review, would be a later story.
- **Q3 (accountant)** May evidence be added to a statement whose date is locked or in a closed year? **Yes, add only, tagged "Added after close"; never remove or replace (as AG-062 Q1).**
- **Q4 (product owner)** A Viewer can open the reprint only from a link, because S1, which has the statement list, needs `account.reconcile`. **Accepted for this story. A statement list for `report.view` users is a separate story.**
- **Q5** Who may download? **`account.reconcile` or `report.view`, not `account.view` (section 2). Removed files also need `audit.view`.**
