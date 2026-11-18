# Spec: Entry attachments (receipts and invoices)

## 1. Goal and scope
**AG-062** "As a Cashier, I want to attach receipts/invoices (image or PDF) so that there is evidence for each entry."
Backlog acceptance: up to 5 files per entry, max 5 MB each, jpg/png/pdf, stored privately and downloadable only by users with view permission.

Built on `feature/controls` (void with contra entries, cumulative period locks, read-only audit log). Fixed decisions:
- Attachments belong to **income and expense entries only** (`transactions.type` in `income`, `expense`). They are evidence, not entry data: adding or removing one never touches `ledger_entries`, `accounts.current_balance` or report figures, so it does not go through `LedgerService`.
- Files live on a **private disk** and are only ever streamed through an authorised API endpoint. No public URL, no signed URL, no `storage:link`.
- Nothing is hard-deleted. "Remove" marks the attachment removed (who, when, reason); the file stays on disk and in the audit trail.

Out of scope:
- Attachments on transfers, allocations, capital and reversals (Q3).
- An edit screen for entries. The web app has no edit form today (`PUT /transactions/{id}` is API-only); files are added after saving on the new entry detail screen (S2).
- The full transaction detail screen. S2 is a minimal read-only view that hosts attachments.
- Image re-encoding, thumbnails, OCR, virus scanning (Q5), S3 or other cloud storage, bulk download (zip), attachments in report exports.

## 2. Personas and permissions
No new catalogue key. Real keys (`PermissionSeeder::PERMISSIONS`): `transaction.view`, `income.create`, `expense.create`, `income.edit`, `expense.edit`, `audit.view`. `{type}` below is the entry's type (`income` or `expense`).

| Action | Rule (server side) |
|---|---|
| List and download active attachments | `transaction.view` |
| Add a file | `{type}.edit`, **or** `{type}.create` and the entry's `created_by` = the user |
| Remove a file | `{type}.edit`, **or** `{type}.create` and the user uploaded that file (`uploaded_by` = user) |
| Download a removed file | `audit.view` (Super Admin always) |

| Template | View/download | Add | Remove | Removed files |
|---|---|---|---|---|
| Super Admin (`is_super_admin`, `canDo` always true) | every branch | any income/expense | any | yes |
| Branch Admin (`*`) | own branch | any income/expense in branch | any | yes |
| Accountant (`income.edit`, `expense.edit`, `audit.view`) | own branch | any income/expense in branch | any | yes |
| Cashier (`transaction.view`, `income.create`, `expense.create`) | own branch | only entries they recorded | only files they uploaded | no |
| Viewer (`transaction.view`, `audit.view`) | own branch | no | no | yes |

Why no `attachment.*` key: the rights follow the entry rights the user already has. A new key would also be auto-granted to every Branch Admin by `PermissionSeeder::run` (`*` propagation), which gives nothing new. The "nobody grants a permission they do not hold" rule is unaffected.

## 3. User flows
**F1 Cashier records an expense with a receipt (KDY, phone).** Record expense -> fill the form -> "Attach receipt" -> phone offers camera / photos / files -> picks 2 files (shown with name and size, each removable before save) -> Save. The entry is posted, then the files upload one by one with progress. Result line: "Saved as EXP-000123. 2 of 2 files attached." On a file error: "Saved as EXP-000123. 1 file was not attached: bill.heic - Only JPG, PNG or PDF files can be attached. Open EXP-000123 to try again." (link to S2). The entry is never rolled back because of a file.
**F2 Cashier adds a late invoice.** Transactions -> click ref EXP-000123 (S2) -> Attachments -> "Add files" -> pick -> uploaded and listed.
**F3 Viewer/auditor checks evidence.** Transactions -> open entry -> Attachments -> Download (or Open on phone). A Viewer can tick "Show removed (1)" and download a removed file with its removal reason.
**F4 Accountant removes a wrong file.** S2 -> row "Remove" -> reason (required) -> confirm. The row moves under "Show removed" with "Removed by ACC on 2026-11-12: Wrong bill, belongs to EXP-000124".
**F5 Super Admin in All branches mode.** Transactions (all branches) -> open a GAL entry -> sees, downloads, adds and removes as in F2-F4. The attachment and its audit rows belong to the entry's branch (GAL), never to the header.
**F6 Month locked / entry voided.** S2 shows the note "October 2026 is locked: files can be added as evidence but not removed." or "This entry is void: files can be added but not removed." Remove buttons are hidden.

## 4. Screens
### S1 Record income / expense `/transactions/new/:kind` (change to `EntryFormComponent`)
- New field after Description: **Attachments** (`<input type="file" multiple accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">`), label "Attach receipt / invoice (JPG, PNG or PDF, up to 5 files, 5 MB each)". Optional.
- Selected files list: name, size (KB/MB), "x" to drop it. Client checks before save (the server is the authority): more than 5 -> "At most 5 files."; > 5 MB -> "<name> is larger than 5 MB."; extension not jpg/jpeg/png/pdf -> "<name>: only JPG, PNG or PDF." Save is blocked while a client error shows.
- After the entry is saved, files are sent one per request to `POST /transactions/{id}/attachments` in order, each with a progress bar. Save stays disabled until all finish. Then the selection is cleared together with the form (date and account kept, as today).
- The save response already returns the entry id (`incomeOrExpense` returns the transaction).

### S2 Entry detail `/transactions/:id` (new, guard `permissionGuard('transaction.view')`)
- Reached from the Transactions list: the Ref cell becomes a link; the list gets a "Files" column (count of active attachments, blank when 0).
- Header (read-only, from `GET /transactions/{id}`): Ref, type, status tag, date, branch, account, category > sub-category, amount, payee/payer, reference, description, created by (with tag). `<ag-lock-banner />` as on the list. Legacy void note as on the list.
- **Attachments card** (income and expense only; other types show no card):
  - Title "Attachments (3 of 5)".
  - Table: Name | Type (JPG/PNG/PDF) | Size | Uploaded by (name + tag) | Uploaded at (Asia/Colombo, `YYYY-MM-DD HH:mm`) | actions: **Download**, **Open** (opens the blob in a new tab), **Remove** (only when `can_remove`).
  - "Add files" button when `can_add`; disabled with tooltip "5 files attached. Remove one first." at the limit.
  - "Show removed (N)" toggle, shown when N > 0: removed rows are struck through with "Removed by <name> on <date>: <reason>"; Download only when the user has `audit.view` or is Super Admin.
  - Remove dialog: "Remove <name> from <ref>? The file is kept for the audit trail and can still be seen by auditors." Reason (textarea, required, 3-500 chars).
  - Notes per F6 when the month is locked or the entry is not `posted`.
  - Empty state: "No files attached." Errors: the API `message` in `.alert`, per file for uploads.
- Download uses `HttpClient` with `responseType: 'blob'` (the API needs the bearer token and `X-Branch-Id`; a plain `<a href>` cannot send them), then an object URL with the server's filename, as `audit-log.component.ts` does for exports. Blob error bodies are parsed as in `report-common.ts`.

### Phone layout (< 760px, the existing breakpoint)
- S1: the file input is a full-width button; `accept` lets the phone offer the camera. Selected files stack one per line.
- S2: the header is a stacked definition list. The attachments table becomes cards: line 1 name (wraps, never truncated without a title tooltip), line 2 "PDF · 1.2 MB · CSH · 2026-11-12 10:05", line 3 buttons Download / Open / Remove, each at least 44px tall. "Add files" is full width at the top of the card.
- No horizontal scroll on S2 at 360px width.

## 5. API (all under `auth:sanctum` + `branch`)
| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `GET /v1/transactions/{transaction}/attachments` | `perm:transaction.view` | `include_removed` (0/1, default 0) | `{data:[Attachment], can_add, max: 5}` |
| `POST /v1/transactions/{transaction}/attachments` | `perm:transaction.view` + R2 rule, `throttle:30,1` | multipart, one `file` | 201 `Attachment`. 403 / 422 per R2, R3, R4 |
| `GET /v1/transactions/{transaction}/attachments/{attachment}/download` | `perm:transaction.view` (+ `audit.view` if removed) | — | streamed file (R6). 403 "Only auditors can download removed files." 410 "The file is missing from storage. Tell your administrator." |
| `POST /v1/transactions/{transaction}/attachments/{attachment}/remove` | `perm:transaction.view` + R2 rule | `reason` (req., 3-500) | 200 `Attachment`. 403 / 422 per R2, R5 |
| `GET /v1/transactions`, `GET /v1/transactions/{id}` | unchanged | — | each row gains `attachments_count` (active only) |

`Attachment` = `{id, transaction_id, original_name, mime, extension, size_bytes, sha256, uploaded_by:{id,name,tag}, created_at, removed_at, removed_by:{id,name}|null, removal_reason, can_remove}`. No `path`, no URL.
Remove is a POST action like void and unlock; there is no DELETE route. `{attachment}` uses scoped binding (`->scopeBindings()`): an attachment of another entry -> 404.

**Schema (one migration)** `attachments`:
- `id`, `branch_id` (FK branches, copied from the transaction), `transaction_id` (FK transactions, **restrict** on delete), `disk` (string 20), `path` (string, unique), `original_name` (string 150), `mime` (string 50), `size_bytes` (unsigned int), `sha256` (char 64), `uploaded_by` (FK users), timestamps, `removed_at` (timestamp null), `removed_by` (FK users null), `removal_reason` (text null).
- Index (`transaction_id`, `removed_at`).
- Model `Attachment` uses `BranchScoped`. `deleting` throws `ImmutableRecordException`; `updating` throws unless the only changed columns are `removed_at`, `removed_by`, `removal_reason` and `updated_at`, and `removed_at` was null.

**Disk** (`config/filesystems.php`): new disk `attachments` - driver `local`, root `storage_path('app/attachments')`, `visibility` private, `serve` false, no `url`, `throw` true. Do not reuse the `local` disk (it has `serve => true`). Code never calls `url()` or `temporaryUrl()` on it. Path on disk: `{branch_id}/{transaction_id}/{uuid}.{ext}`; the user's filename never reaches the path.
**Server limits (deploy note):** PHP `upload_max_filesize` >= 6M and `post_max_size` >= 8M (PHP's default 2M would refuse most 5 MB files); web server body limit >= 8 MB. `storage/app/attachments` must be in the backup set.

## 6. Business rules and edge cases
**R1 Branch isolation.**
- `{transaction}` resolves through `BranchScoped`: an entry of another branch -> 404 (same as `show`). `Attachment` is `BranchScoped` too, so a forged attachment id from another branch -> 404.
- `attachments.branch_id` is always the transaction's `branch_id`, never the `X-Branch-Id` header.
- Super Admin in All mode: no scope; every branch's attachments can be listed, downloaded, added and removed. Super Admin with X-Branch-Id KDY: KDY only, like everyone else.
- A user removed from KDY or deactivated is refused by `SetBranchContext` (403) before any attachment code runs.

**R2 Who may add or remove** (table in §2). Checked in the controller with `canDo`, after the type check:
- Entry not income/expense -> 422 "Files can only be attached to income and expense entries." (before permissions, like void).
- Add with only `{type}.create` on someone else's entry -> 403 "You can attach files only to entries you recorded."
- Remove with only `{type}.create` on a file someone else uploaded -> 403 "You can remove only files you uploaded."
- Neither key -> 403 "Missing permission: {type}.edit".

**R3 Count limits** (checked inside a DB transaction holding `lockForUpdate` on the transaction row, so two parallel uploads cannot make 6):
- At most **5 active** (not removed) attachments per entry -> 422 "An entry can have at most 5 files. Remove one first."
- At most **10 attachments in total**, removed included, so remove-and-re-add cannot grow storage without bound -> 422 "This entry already had 10 files uploaded (including removed ones). No more can be added."
- The same content twice on one entry (same `sha256`, active) -> 422 "This file is already attached to EXP-000123."

**R4 File validation** (server side, in this order; the first failure is the message):
1. `file` present and uploaded without a PHP error. A file refused by PHP limits -> 422 "Each file may be at most 5 MB."
2. Size 1 to 5,242,880 bytes (5 MB). 0 bytes -> 422 "The file is empty."; larger -> 422 "Each file may be at most 5 MB."
3. **Content type by magic bytes**, never by the client's `Content-Type` or extension: `finfo` (Laravel `mimetypes:image/jpeg,image/png,application/pdf`) **and** a signature check on the first bytes: JPEG `FF D8 FF`, PNG `89 50 4E 47 0D 0A 1A 0A`, PDF `%PDF-` at offset 0. Anything else (GIF, WEBP, HEIC, SVG, HTML, Office, ZIP, text renamed `.jpg`) -> 422 "Only JPG, PNG or PDF files can be attached."
4. The extension of the original name must be one of `jpg`, `jpeg`, `png`, `pdf` (case-insensitive) and match the detected type (`jpg`/`jpeg` = JPEG). Mismatch, e.g. a PNG named `bill.pdf` -> 422 "bill.pdf is a PNG image, not a PDF. Rename it or choose the right file."
- The stored `mime` and `extension` come from the detected type. Bytes are stored exactly as received (no re-encoding, no EXIF stripping), so the evidence is the original; `sha256` is computed on those bytes.

**R5 Locked month, void and reversed entries (evidence-friendly).**
| Entry state | Add | Remove |
|---|---|---|
| posted, month open | yes | yes |
| posted, month locked (cumulative, `PeriodLock::isLocked`) | **yes** | no: 422 "The period 2026-10 is locked." |
| `void` or `reversed` (any month) | **yes** | no: 422 "Files of a voided or reversed entry cannot be removed." |
- Adding to a locked month is allowed because it changes no figure, no balance and no entry field; it only adds evidence (e.g. the supplier's invoice arrives after month-end). The audit row flags it (`period_locked: true`). See Q1.
- Removal re-checks the lock inside the DB transaction with a locking read (`isLocked(..., share: true)`), like `TransactionController::update`.
- An already removed file -> 422 "This file has already been removed."

**R6 Storage and download.**
- Write order: store the file under a new uuid path, then insert the row and the audit row in one DB transaction. If the DB step fails, the just-written file is deleted (it was never recorded, so this is not a record deletion).
- Download streams from the `attachments` disk with headers: `Content-Type` = stored `mime`; `Content-Disposition: attachment` with an ASCII fallback `filename=` and an RFC 6266 `filename*=UTF-8''…` (Symfony `HeaderUtils::makeDisposition`); `X-Content-Type-Options: nosniff`; `Cache-Control: private, no-store`; `Content-Security-Policy: sandbox`. Never `inline`.
- File missing on disk -> 410 (message in §5) and a Laravel error log line with the attachment id. No audit row is changed.

**R7 Filename handling** (`original_name`, display and download only):
In this order:
1. Take the part after the last `/` or `\`.
2. Split off the extension at the last dot and replace it with the canonical one of the detected type (`jpg`, `png`, `pdf`), so `Invoice.PDF` -> `Invoice.pdf`, `photo.jpeg` -> `photo.jpg`.
3. In the stem: remove control and format characters (`\p{C}`, which includes bidi overrides such as U+202E); replace `/ \ : * ? " < > |` with `_`; collapse whitespace; trim spaces and dots at both ends.
4. Cut the stem to 120 characters (multibyte-safe). Empty stem -> `attachment`. Names need not be unique per entry.

**R8 Audit events** (`AuditLog::record`, auditable = the **Transaction**, so the existing Ref no filter and Subject column work; `branch_id` = the transaction's branch):
| Event | Label | old_values | new_values |
|---|---|---|---|
| `attachment.added` | Attachment added | null | `attachment_id, ref_no, original_name, mime, size_bytes, sha256, txn_status, period_locked` |
| `attachment.removed` | Attachment removed | `attachment_id, original_name, sha256, uploaded_by, uploaded_at` | `reason, removed_at, self` (`self` = remover is the uploader) |
| `attachment.downloaded` | Attachment downloaded | null | `attachment_id, original_name, removed` (bool) |
- Audit log screen (S1 of controls): new event group **Attachments** (`attachment.` prefix) and the three labels in `AuditLogController::LABELS`.
- No audit row for list calls or for failed validation.

**R9 Entry flows unchanged.** Create, edit, void and reverse ignore attachments. Void and reverse keep every attachment. `attachments_count` counts active rows only.

## 7. Acceptance criteria
Setup: branches MAIN, KDY, GAL; users SA (Super Admin), ACC (Accountant KDY), VW (Viewer KDY), CSH and CSH2 (Cashiers KDY), BA (Branch Admin GAL). `Storage::fake('attachments')`. Files built with real bytes: `r1.jpg` (JPEG, 200 KB), `inv.pdf` (PDF, 1 MB), `max.pdf` (PDF, exactly 5,242,880 bytes), `big.pdf` (PDF, 5,242,881 bytes), `fake.pdf` (PNG bytes), `evil.jpg` (`<?php` text), `x.gif`, `x.svg`, `x.heic`, `empty.pdf` (0 bytes). Entry E = KDY expense recorded by CSH on 2026-11-10.

**Upload and validation**
1. Given CSH, when uploading `r1.jpg` to E, then 201, the response has `mime` image/jpeg, `size_bytes`, a 64-char `sha256` and no path or URL, the file exists on the `attachments` disk under `{KDY id}/{E id}/<uuid>.jpg`, and one `attachment.added` row (branch KDY, auditable E) holds the values in R8.
2. Given `max.pdf`, then 201. Given `big.pdf`, then 422 "Each file may be at most 5 MB." Given `empty.pdf`, then 422 "The file is empty." Nothing is stored for the refused files.
3. Given `evil.jpg`, `x.gif`, `x.svg` or `x.heic` (any client `Content-Type`), then 422 "Only JPG, PNG or PDF files can be attached." and nothing is stored.
4. Given `fake.pdf`, then 422 "fake.pdf is a PNG image, not a PDF. Rename it or choose the right file."
5. Given E has 5 active attachments, when a 6th is uploaded, then 422 "An entry can have at most 5 files. Remove one first." After one is removed, a new upload is 201.
6. Given E had 10 uploads in total (some removed, fewer than 5 active), when another is uploaded, then 422 "This entry already had 10 files uploaded (including removed ones). No more can be added."
7. Given `inv.pdf` is attached to E, when the same bytes are uploaded again to E (any name), then 422 "This file is already attached to <E ref>." The same bytes on another entry are accepted.
8. Given the original names `../../etc/passwd.PDF`, `a‮gnp.pdf`, `   .pdf` and a 300-character name (all real PDFs), then `original_name` is `passwd.pdf`, `agnp.pdf`, `attachment.pdf` and a 120-character stem + `.pdf`; no stored path contains any part of the original name.
9. Given E has 4 attachments, when two uploads for E run at the same time, then exactly 5 active attachments exist afterwards and the other request gets 422.

**Permissions and isolation**
10. Given CSH2 (recorded nothing), when uploading to E, then 403 "You can attach files only to entries you recorded."; when removing CSH's file on E, then 403 "You can remove only files you uploaded."
11. Given ACC, then ACC can upload to E and remove CSH's file on E. Given VW, then VW can list and download but upload and remove return 403.
12. Given a KDY transfer, capital or reversal T, when uploading to T, then 422 "Files can only be attached to income and expense entries."
13. Given BA (X-Branch-Id GAL) or any KDY user sending X-Branch-Id GAL, when calling any attachment route for E or one of its attachments, then 404 (or 403 from `SetBranchContext` for a non-member).
14. Given attachment A1 of E and entry F (also KDY), when `GET /transactions/{F}/attachments/{A1}/download`, then 404.
15. Given SA in All mode, when listing, downloading, uploading to and removing from a GAL entry, then all succeed, and the new attachment and every audit row have `branch_id` GAL. Given SA with X-Branch-Id KDY, a GAL entry returns 404.
16. Given CSH is removed from KDY (or deactivated), when CSH calls any attachment route, then 403 and no file is returned.
17. Given a user without `transaction.view`, when calling list or download, then 403 "Missing permission: transaction.view".

**Locked month, void, removal**
18. Given KDY October locked and October KDY expense O, when CSH (its recorder) uploads to O, then 201 and the `attachment.added` row has `period_locked: true`; balances, `ledger_entries` and the October income-expense report are unchanged.
19. Given O has a file, when ACC removes it, then 422 "The period 2026-10 is locked." and the row is unchanged.
20. Given E is voided (or an expense is reversed), when ACC uploads to it, then 201 with `txn_status` void (or reversed); when ACC removes any of its files, then 422 "Files of a voided or reversed entry cannot be removed."
21. Given ACC removes A1 from E with reason "Wrong bill", then 200, A1 has `removed_at`, `removed_by` ACC and the reason, the file is still on disk, the default list no longer shows A1, `include_removed=1` shows it, `attachments_count` drops by 1, and `attachment.removed` holds the R8 values with `self: false`. Removing A1 again -> 422 "This file has already been removed." A reason under 3 characters -> 422.
22. Given any attachment, then no route deletes it (DELETE on attachment URLs -> 404/405), `$attachment->delete()` throws, and updating any column other than the removal fields throws.
23. Given A1 removed, when VW or ACC downloads it, then 200; when CSH downloads it, then 403 "Only auditors can download removed files."

**Download**
24. Given `inv.pdf` uploaded as `Fáktura 11.pdf`, when downloaded, then the body is byte-identical (same sha256), `Content-Type: application/pdf`, `Content-Disposition` is `attachment` with an ASCII `filename=` and `filename*=UTF-8''F%C3%A1ktura%2011.pdf`, and `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store` and `Content-Security-Policy: sandbox` are present.
25. Given any download, then one `attachment.downloaded` row is written with the user, branch of the entry and `removed` flag. A list call writes no audit row.
26. Given the file was deleted from disk outside the app, when downloaded, then 410 "The file is missing from storage. Tell your administrator."
27. Given the `attachments` disk config, then it has no `url`, `serve` is not true, its root is not under `storage/app/public`, and no code path calls `url()` or `temporaryUrl()` on it (reviewer check).

**Web**
28. Given CSH on S1 picks `r1.jpg` and `inv.pdf` and saves, then the entry is posted, both files upload with progress, and "Saved as EXP-… 2 of 2 files attached." shows. With `x.heic` added (forcing the server check), the entry is still saved and the message names the file that failed with the server message and links to S2.
29. Given S1 with 6 files picked, or one over 5 MB, then Save is blocked with the client message from S1 and nothing is sent.
30. Given the Transactions list, then the Ref links to `/transactions/:id`, and the Files column shows the active count. Given S2 for a transfer, then no Attachments card is shown.
31. Given S2 for CSH on an entry recorded by CSH2, then no "Add files" button and no Remove buttons; for ACC both show. At 5 files "Add files" is disabled with its tooltip. On a locked-month or void entry, the F6 note shows and no Remove buttons appear.
32. Given S2 at 360px width, then attachments show as cards with Download / Open / Remove buttons at least 44px tall, and there is no horizontal scroll.
33. Given the audit log, then the "Attachments" event group returns only `attachment.*` rows, and filtering Ref no = E's ref returns E's create, void and attachment rows.

## 8. Open questions (assumption in bold)
- Q1 (accountant) May evidence be **added** to an entry in a locked month, or to a voided/reversed entry? **Yes, add only; never remove** (R5). It changes no figure, and late supplier invoices are common. If the lock must freeze evidence too, adding becomes 422 "The period … is locked." with no other change.
- Q2 (accountant) May a Cashier remove a file they uploaded themselves while the month is open, without `{type}.edit`? **Yes**, so a wrong photo can be fixed at once; the removal is soft, needs a reason and is audited with `self`. Alternative: removal needs `{type}.edit` only.
- Q3 Do transfers, allocations or capital need attachments (e.g. bank slips)? **Not in AG-062** (story says "entry", the Cashier persona records income/expense). The table and endpoints are keyed by transaction, so extending later is a rule change, not a schema change.
- Q4 (accountant) Is it right that only `audit.view` holders (Viewer, Accountant, Branch Admin, Super Admin) can download removed files, while their metadata and reason are visible to every `transaction.view` user? **Yes.**
- Q5 Virus scanning of uploads (e.g. ClamAV). **Not now:** only JPG/PNG/PDF pass magic-byte checks and files are served as downloads with `nosniff` and `sandbox`. Revisit if files are shared outside the app.
- Q6 Should downloads be audited? **Yes** (`attachment.downloaded`), like report exports, because receipts can hold personal data. If the volume is a concern, keep only downloads of removed files.
- Q7 Phone photos keep their EXIF data (may include GPS). **Kept as is** so the evidence is the original file.
