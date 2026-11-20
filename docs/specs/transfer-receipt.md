# Spec: Transfer receipt acknowledgement (receiving branch confirms the cash handover)

## 1. Goal and scope
Backlog **AG-052** (E6, Could, R2, 3 points): "As a receiving branch, I want to acknowledge receipt of a transfer so that physical cash handover is confirmed." AC: status flow Sent -> Received (optional per setting); unacknowledged transfers are listed on the receiving branch's dashboard.

Raised in priority by the fund return sign-off (fund-return.md R10, accountant SHOULD 4): today a branch can record a return that never reached Main's bank, and Main only finds out at its weekly bank-to-Transfers-report reconciliation.

**Applies to every transfer between two branches:** `opening_allocation`, `fund_allocation` (Top-up), `fund_return`, `branch_transfer`. Not to `internal_transfer` (same branch), `capital`, `income`, `expense`, `reversal` or `year_end_close`.

**It is a control and confirmation step only. It posts nothing.** The money is posted on send (`LedgerService::transfer`, unchanged). Acknowledging or disputing never writes a ledger line, never changes `accounts.current_balance`, `transactions.status` or `transactions.amount`, and never changes a report figure. Decided (accounting review, Q1): no posting and no cash-in-transit account for any of the four types, fund returns included. Money in transit at a month-end is a reconciling item (a deposit in transit on the bank reconciliation, a line on the cash count sheet), so the list must be producible **as at a date** (R14).

What exists today (`feature/transfer-receipt`, 2026-11-20): `transactions` has `branch_id` (sending branch), `related_branch_id` (receiving branch) and `status` (`posted|reversed|void`; `pending|rejected` reserved for AG-051/AG-064). `approved_by`/`approved_at` are reserved for AG-051 and are **not** reused here. Settings are `App\Support\Settings` + `SettingsController::EDITABLE`. The dashboard is `DashboardController` (`perm:report.dashboard`).

**Out of scope:** notifications (AG-094; only a hook, R11), transfer approval (AG-051), a "cash in transit" account or any posting (Q1), an amount-counted field (Q4), acknowledging reversals (Q3), undoing a confirmation (Q5), backfilling transfers posted before this release (R3).

## 2. Personas and permissions
**New key `transfer.acknowledge`** in `PermissionSeeder::PERMISSIONS` (after `transfer.reverse`). Meaning: may confirm receipt of, or report a discrepancy on, a transfer **received by** the active branch.

| Template | Has it | How |
|---|---|---|
| Branch Admin (`*`) | yes | added automatically to `*` templates by `PermissionSeeder::run` |
| Accountant | yes | added to `TEMPLATES['Accountant']` (new installs). Existing databases: Super Admin adds it through the Roles screen (release note; users-and-roles finding 7: new keys never reach other templates or branch copies automatically). The release note and the S1 info text say to grant it to the Accountant and Cashier roles of each branch **before** turning the setting on (accounting review finding 6) |
| Cashier | yes | as Accountant. The cashier is the person who physically counts the cash |
| Viewer | no | |
| Super Admin | yes (all keys) | any branch, All mode included |

Other keys used: `report.dashboard` (widget), `transaction.view` (list, detail, receipts endpoint), `report.view` (Transfers report), `transfer.reverse` (unchanged), Super Admin `super` (setting).

**Segregation (R5):** the user who created the transfer can never acknowledge it, Super Admin included. The confirmer cannot move money with it (it posts nothing) and cannot reverse the transfer (reversal belongs to the sending branch; fund-return reversal stays Super Admin only).

**Existing installs (migration).** The schema migration also inserts `transfer.acknowledge` where it is missing and gives it to the `*` templates (Branch Admin), exactly as re-running `PermissionSeeder` would, so an existing database works without a re-seed. A fresh install gets it from the seeder. Its rollback removes the key again (and every role or user grant of it).

## 3. User flows
- **F1 Setting (Super Admin).** First grant "transfer.acknowledge" to the Accountant and Cashier roles of each branch (Roles screen). Then Settings > Transfers > tick "Receiving branch must confirm receipt of transfers between branches" > Save > "Settings saved.". From now on each new between-branch transfer starts as "Awaiting receipt". Default off; the release note recommends turning it on at go-live after granting the key. Until it is on, the fund-return weekly bank-to-Transfers-report reconciliation (fund-return R10) stays in force.
- **F2 Branch transfer (KDY -> GAL).** KDY Accountant posts KDY Cash -> GAL Cash 10,000.00 (unchanged flow). Success text adds "GAL must confirm receipt.". The cash goes by hand.
- **F3 Confirm (GAL Cashier).** Dashboard > widget "Transfers to confirm" lists KDY-TRF-… 10,000.00, age 0 days > "Confirm receipt" > dialog: Received on (default today), Note (optional) > Confirm > row leaves the widget; the entry shows "Received on 20 Nov 2026 by <name>".
- **F4 Discrepancy (GAL Cashier).** Same row > "Report a discrepancy" > Note (required, e.g. "Envelope held 9,500.00, counted with K. Silva") > Report > row stays in GAL's widget as "Discrepancy reported" and appears in KDY's widget under "Disputed by the receiving branch". A dispute blocks nothing (no posting, lock or close waits for it). Resolutions (R2): (a) the missing money arrives -> GAL confirms receipt with a note saying how it was settled; (b) wrong amount sent -> KDY reverses the transfer (receipt becomes "Cancelled (reversed)") and posts the correct amount; (c) money lost or stolen in transit -> KDY reverses, posts the amount actually delivered, and records the shortfall as an expense of the **sending** branch (category "Cash shortage / loss") with the dispute ref in the note.
- **F5 Fund return (GAL -> MAIN).** A MAIN user with `transfer.acknowledge`, or the Super Admin (not the creator), confirms in MAIN's widget after checking Main's bank.
- **F6 Super Admin, All branches.** The widget lists every open receipt in every branch, with From and To columns. The Super Admin may confirm any transfer they did not create, but from All mode a note (10-500) is required saying what was checked (for a fund return into MAIN's bank: the bank statement date), because nobody at the branch counted it. The Receipt panel then shows "Confirmed by Super Admin"; the audit row carries the receiving branch.
- **F7 Month-end.** Before locking a month, the Period locks review shows "N transfers (amount) were in transit at the end of <month>, of which M (amount) have a discrepancy reported" (information only, as at the month-end, R14). The accountant runs the Transfers report with Receipt = "In transit at end date" and To = the month-end as the list of reconciling items; it still lists a transfer confirmed after the month-end. The Year-end preview shows the same as at the fiscal year end.

## 4. Screens
**S1 Settings** `/settings` (existing). New section "Transfers" after "Fiscal year":

| Field | Type | Rules |
|---|---|---|
| Receiving branch must confirm receipt of transfers between branches | checkbox | default off. Info: "Applies to transfers posted after you turn it on. It does not change balances or reports. Grant 'transfer.acknowledge' to the Accountant and Cashier roles in each branch before turning this on." "Last changed by …" as other fields |

**S2 Dashboard widget "Transfers to confirm"** (`features/dashboard`). Shown when the user has `report.dashboard` and `transaction.view`, and (the setting is on, or the endpoint returns at least one row).
- **Ageing strip** (from `summary.buckets`): four tiles "0-1 days", "2-3 days", "4-7 days", "Over 7 days", each with count and amount; "Over 7 days" uses the danger colour when count > 0.
- **Table "To confirm"** (incoming, oldest first): Date · Age (days) · Ref (link to `/transactions/:id`) · Type · From (branch · account) · To account · Amount · Receipt (tag "Awaiting" / "Discrepancy" with the note on hover/expand) · Actions. All mode adds To branch. Rows aged 4-7 days amber, over 7 red (with the text tag too, not colour only).
- **Actions** (only when `can_acknowledge`): "Confirm receipt" and "Report a discrepancy" (the latter hidden when already disputed). When the user is the creator, no buttons and the hint "Sent by you".
- **Table "Disputed by the receiving branch"** (outgoing disputed rows, branch mode only, hidden when empty): Date · Ref · To (branch · account) · Amount · Note · Reported by/at. No actions (resolution is a receipt by the other branch or a reversal here).
- Footer link "View all in Transactions" -> `/transactions?receipt=awaiting,disputed`. The widget shows at most 50 rows per table, then "and N more".
- Empty: "No transfers waiting for confirmation."
- **Confirm dialog:** Received on (date, required, default today, min = transfer date, max = today), Note (optional, max 500; **required, 10-500**, when the receipt is "Discrepancy reported" (label "How was it settled?") or when the user is in All branches mode (label "What did you check? e.g. bank statement date")). Buttons Confirm / Cancel. Success toast "Receipt of <ref> confirmed.".
- **Discrepancy dialog:** text "Report that the cash or deposit does not match <ref> (<amount>). Balances do not change. The sending branch sees your note." Note (required, 10-500). Buttons Report / Cancel. Success "Discrepancy on <ref> reported.".
- Errors: API `message` in the dialog's `.alert`; on 422 the widget reloads.

**S3 Transaction detail** `/transactions/:id` (existing), for the four transfer types. New "Receipt" panel: Status label (R2) · Received on (business date) · By / at (system time, so a back-dated confirmation is visible) · Note; "Confirmed by Super Admin" when the confirmer is a Super Admin. Same two buttons and dialogs as S2 when `can_acknowledge`. Panel hidden when `receipt_status` is null ("Not required").

**S4 Transactions list** `/transactions` (existing). Filter "Receipt": checkboxes Awaiting / Received / Discrepancy / Cancelled (URL `receipt`, comma list; chip "Receipt: Awaiting ×"). Status column: second tag with the receipt label for rows with a receipt status. Mobile card: the tag on line 3.

**S5 Transfers & Allocations report** `/reports/transfers` (existing). Filter "Receipt" (multi, same four values, plus "In transit at end date"; default all). "In transit at end date" cannot be combined with the other four; it lists every transfer in transit at To (R14), whatever its date before To, so From does not limit it, and shows the line "N transfers in transit at <To>: <amount>". The Receipt filter narrows the Movements list only: Net moved and the matrix stay the money figures of the period (R1). Movements column "Receipt" after Status (label, or "—" when null).

**S7 Period locks review and Year-end preview** (existing, Super Admin). Information line (never blocking): "N transfers (amount) in transit at <period end>, of which M (amount) with a discrepancy reported. Run the Transfers report with Receipt = In transit at end date." Hidden when N is 0.

**S6 Reverse dialog** (existing). For a transfer with receipt "Received": warning line "<BR> confirmed receipt on <date>. The reversal sends the money back; arrange the physical return." Not blocking.

## 5. API
| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `GET /v1/transfer-receipts` (new) | `branch`, `perm:transaction.view` | `state` optional comma list of `awaiting,disputed` (default both) | `{required: bool (the setting), summary:{buckets:[{key:'0_1'\|'2_3'\|'4_7'\|'8_plus', count, amount}], count, amount}, incoming:{data:[row], total}, outgoing_disputed:{data:[row], total}}`. Row: `id, ref_no, type, txn_date, age_days, from:{branch_code, account}, to:{branch_code, account}, amount, receipt_status, receipt_note, receipt_by:{id,name}\|null, receipt_at, created_by:{id,name}, can_acknowledge, note_required`. 50 rows per list, oldest first. Summary covers `incoming` only |
| `POST /v1/transactions/{id}/receipt` (new) | `branch`, `perm:transfer.acknowledge` | `action` = `received`\|`discrepancy`; `received_on` (Y-m-d; required for `received`, >= transfer date, <= `BusinessDate::today()`); `note` (`received`: optional, max 500, but required 10-500 from `disputed` or in All mode; `discrepancy`: required, 10-500, trimmed) | 200 with the transaction's receipt fields. Errors in R6 |
| `GET /v1/settings`, `PUT /v1/settings` | `super` (unchanged) | + `transfer_receipt_required` (boolean) | + `transfer_receipt_required: bool`. Written with the existing single `settings.updated` row |
| `GET /v1/transactions`, `POST /v1/transactions/export`, `GET /v1/transactions/{id}` | unchanged | list/export: + `receipt` (comma list of `awaiting,received,disputed,cancelled`; other values 422 "Unknown receipt status: <value>.") | rows + `receipt_status, received_on, receipt_note, receipt_by:{id,name}\|null, receipt_at`; detail + `can_acknowledge`. Excel adds columns Receipt · Received on (date cell) · Receipt by · Receipt note after Status, and `Receipt: …` in the Filters header line |
| `GET /v1/reports/transfers`, `POST /v1/reports/transfers/export` | unchanged | + `receipt[]` (same values plus `in_transit`, 422 as above; `in_transit` alone only, else 422 "In transit at end date cannot be combined with other receipt values.") | movements + `receipt_status`, `received_on`; + `in_transit: {as_at, count, amount}` when `in_transit` is chosen; export adds column "Receipt" and the filter in its header |
| `GET /v1/period-locks/review` | `super` (unchanged) | — | + `transfers_awaiting_receipt` (int), `transfers_awaiting_receipt_amount`, `transfers_disputed` (int), `transfers_disputed_amount`: transfers in transit at the period end (R14), sent or received by a branch the lock newly closes; disputed = of those, the ones whose receipt is now `disputed`. Information only |
| `GET /v1/fiscal-years/{y}/preview` | `super` (unchanged) | — | + the same four fields, in transit at the fiscal year end, every branch. Information only, not a `check` |
| `POST /v1/transfers`, `POST /v1/allocations` | unchanged | — | response + `receipt_status` |

**Schema** (one migration after the latest one): `transactions` gains `receipt_status` string(20) null, `received_on` date null, `receipt_note` text null, `receipt_by` FK users null, `receipt_at` timestamp null; index `(related_branch_id, receipt_status)`. Settings row `transfer_receipt_required` = `0` inserted where missing; `Settings::transferReceiptRequired(): bool`. Permission `transfer.acknowledge` inserted where missing and given to the `*` templates (section 2). No backfill. MySQL: `receipt_by` keeps its own index (`transactions_receipt_by_fk`), and the rollback first gives `related_branch_id` its own index when no other index would serve its foreign key. Rollback (`down()`) stops before any change while a transaction has a receipt status (the receipt evidence would be lost); otherwise it drops the index, foreign key and columns, the settings row and the permission.

## 6. Business rules
- **R1 No postings.** Acknowledgement and discrepancy update only the five receipt columns, through a new `App\Services\TransferReceipts` service (not `LedgerService`, which owns money postings). They never touch `ledger_entries`, `accounts.current_balance`, `status` or `amount`. All reports, the dashboard money figures, the trial balance and `ledger:verify` are unchanged by them.
- **R2 States** (`receipt_status`; labels on screen, PDF and Excel):
  - `null` "Not required" (setting off at send, other types, all transfers before this release);
  - `awaiting` "Awaiting receipt" (Sent);
  - `received` "Received" (final);
  - `disputed` "Discrepancy reported";
  - `cancelled` "Cancelled (reversed)".
  - Allowed moves: `awaiting -> received`, `awaiting -> disputed`, `disputed -> received` (with a settlement note, R6 item 7), `awaiting|disputed -> cancelled` (reversal only, R7). Nothing else. "Received" is final (Q5).
  - A discrepancy blocks nothing and changes no figure. It is resolved by (a) confirming when the money arrives (the note says how it was settled), (b) the sender reversing and posting the correct amount, or (c) money lost or stolen in transit: the sender reverses, posts the amount actually delivered and records the shortfall as an expense of the **sending** branch (category "Cash shortage / loss") with the dispute ref in the note.
  - `receipt_status` is separate from `status`: a transfer stays `posted` while awaiting receipt (reports R2 rely on `status`).
- **R3 When a transfer gets `awaiting`.** In `LedgerService::transfer`, inside the same DB transaction that creates it: if the type is one of the four between-branch types and `Settings::transferReceiptRequired()` is true at that moment, `receipt_status = 'awaiting'`; otherwise null. Turning the setting off does not change existing rows: awaiting and disputed ones stay listed and can still be confirmed. Turning it on does not touch earlier transfers.
- **R4 Who may act.** The receiving branch is `related_branch_id`. Resolve the transaction with `withoutGlobalScopes()` (it belongs to the sending branch), then: in branch mode the active branch must equal `related_branch_id`, else 404 (also for the sending branch); in All mode the Super Admin may act on any. The user needs `transfer.acknowledge` in the active branch (route). `can_acknowledge` = all of these + R5 + state allows it.
- **R5 Not the sender.** `created_by` = current user -> 403 "You sent this transfer. Someone in <BR code> must confirm receipt." (Super Admin included; a Super Admin top-up is confirmed by the branch).
- **R6 Errors on `POST …/receipt`** (checked in this order; nothing is written and no audit row on any of them):
  1. 404 not visible (R4).
  2. 422 "This entry does not need a receipt." (`receipt_status` null, or not a between-branch type).
  3. 422 "This transfer was reversed. No receipt is needed." (`cancelled`, or `status` = `reversed`).
  4. 403 R5.
  5. 422 "Receipt was already confirmed by <name> on <received_on>." (`received`).
  6. 422 "A discrepancy is already reported. Confirm receipt when the money arrives, or ask <BR code> to reverse the transfer." (`discrepancy` on `disputed`).
  7. 422 field errors (dates, note). The note is required (10-500, trimmed) for `discrepancy`, for `received` on a `disputed` transfer ("Say how the discrepancy was settled (10 to 500 characters).") and for `received` in All mode ("From All branches, say what you checked, e.g. the bank statement date (10 to 500 characters).").
  - Concurrency: the update is conditional (`where receipt_status in (allowed from-states) and status = 'posted'` under `lockForUpdate`); the loser of two simultaneous requests, or of a request racing a reversal, gets the matching 422 above.
- **R7 Reversal.** `LedgerService::reverse` of a transfer with `awaiting` or `disputed`, in the same DB transaction: `receipt_status = 'cancelled'` (receipt_by/at/note kept as they were), audit `transfer.receipt_cancelled`. `received` stays `received`; the reverse dialog warns (S6). The reversal row itself has `receipt_status` null (Q3). Reversal rights are unchanged (fund return reversal stays Super Admin only).
- **R8 Period lock and year-end.** Confirming or disputing posts nothing, so it is **allowed when the transfer's month is locked or its fiscal year is closed**, and `received_on` may fall in a locked month. A lock never blocks it and it never needs an unlock; an open or disputed receipt never blocks a lock or the close (one branch could otherwise hold back the whole close). Information only: Period locks review shows count and amount in transit at the period end, disputed separately (F7, S7); the Year-end preview shows the same as at the fiscal year end (fields `transfers_awaiting_receipt`, `transfers_awaiting_receipt_amount`, `transfers_disputed`, `transfers_disputed_amount`, not a blocking `check`). Reopening a year changes no receipt.
- **R9 Ageing.** `age_days` = `BusinessDate::today()` − `txn_date` in calendar days (Asia/Colombo). Buckets 0-1, 2-3, 4-7, 8+. Amounts via `Money` (integer cents), dates via `whereDate`.
- **R10 Scope of the widget.** Branch mode: `incoming` = `related_branch_id` = active branch and `receipt_status in (awaiting, disputed)` and `status = 'posted'`; `outgoing_disputed` = `branch_id` = active branch and `disputed`. All mode: `incoming` = every open receipt, `outgoing_disputed` empty.
- **R11 Hook for AG-094.** After commit, dispatch `App\Events\TransferReceiptRequested` (a transfer posted as `awaiting`) and `App\Events\TransferReceiptChanged` (`received`, `disputed`, `cancelled`; payload: transaction id, old and new state, user id). No listener in this item; AG-094 adds them (e.g. tell the sender about a discrepancy).
- **R12 Audit** (`AuditLog::record` on the transaction, branch = receiving branch):
  - `transfer.receipt_received`: old `{receipt_status}`, new `{receipt_status:'received', received_on, receipt_at, note, all_branches}`;
  - `transfer.receipt_disputed`: old `{receipt_status}`, new `{receipt_status:'disputed', receipt_at, note, all_branches}`;
  - `transfer.receipt_cancelled` (R7): old `{receipt_status}`, new `{receipt_status:'cancelled', reversal_id, reversal_ref}`;
  - every receipt row keeps both `received_on` (business date, may be back-dated) and `receipt_at` (system time), so a back-dated confirmation is visible;
  - the existing `transaction.<type>` creation row gains `receipt_status`;
  - the setting change is the existing `settings.updated` row.
  - The Audit log screen shows the three new events in the "Transactions" group, labelled "Receipt confirmed", "Discrepancy reported", "Receipt cancelled (reversed)". Refusals write nothing.
- **R13 Edge cases.** Deactivated receiving branch or account: still confirmable (Super Admin, All mode). A user who is a member of both branches but did not create the transfer may confirm in the receiving branch. A void never applies (transfers are reversed, not voided).
- **R14 In transit as at a date D** (accounting review MUST 1), defined once in `TransferReceipts` and used by the Period locks review (D = period end), the Year-end preview (D = fiscal year end) and the Transfers report option "In transit at end date" (D = To): a between-branch transfer with `receipt_status` not null, `txn_date <= D`, not reversed on or before D (no posted reversal dated <= D), and (`receipt_status in (awaiting, disputed, cancelled)` or `received_on > D`). A cancelled receipt counts only while its reversal is dated after D. "Open today" (the widget, the Transactions filter) is the current state only and is not used for month-end.

## 7. Acceptance criteria
Data: branches MAIN, KDY, GAL; setting on unless stated; today 2026-11-20. KDY Accountant (creator); GAL Cashier and GAL Accountant with `transfer.acknowledge`; GAL Viewer (no key); MAIN Accountant with `transfer.acknowledge`. T1 = KDY Cash -> GAL Cash 10,000.00 dated 2026-11-18 by the KDY Accountant.

1. Given the setting on, When T1 is posted, Then `receipt_status = 'awaiting'`, `status = 'posted'`, and the ledger lines and balances are exactly those of the same transfer with the setting off.
2. Given the setting off, When a branch transfer is posted, Then `receipt_status` is null and it never appears in `GET /transfer-receipts`.
3. Given the setting on, When an internal transfer (KDY Cash -> KDY Bank) or capital is posted, Then `receipt_status` is null. A Super Admin top-up MAIN -> GAL and a GAL -> MAIN fund return both get `awaiting`.
4. Given T1, When the GAL Cashier (X-Branch-Id GAL) GETs `/transfer-receipts`, Then `incoming` holds T1 with `age_days = 2`, bucket `2_3` count 1 amount 10000.00, `can_acknowledge = true`; for the KDY Accountant (X-Branch-Id KDY) `incoming` is empty.
5. Given T1, When the GAL Cashier POSTs `action=received, received_on=2026-11-19`, Then 200, `receipt_status = 'received'`, `received_on`, `receipt_by`, `receipt_at` set; one `transfer.receipt_received` audit row with branch GAL; ledger entry count, every `accounts.current_balance`, T1 `status` and `amount` unchanged; `php artisan ledger:verify` passes; T1 is no longer in `incoming`.
6. Given T1 confirmed (AC 5), When the GAL Accountant POSTs `received` again, Then 422 "Receipt was already confirmed by <name> on 2026-11-19." and no new audit row.
7. Given T1, When the GAL Viewer POSTs `received`, Then 403 (missing permission) and nothing changes.
8. Given T1, When the KDY Accountant (X-Branch-Id KDY, has `transfer.acknowledge`) POSTs `received`, Then 404 and nothing changes.
9. Given the KDY Accountant is also a GAL member with `transfer.acknowledge`, When they POST `received` on T1 with X-Branch-Id GAL, Then 403 "You sent this transfer. Someone in GAL must confirm receipt.".
10. Given a Super Admin top-up MAIN -> GAL created by the Super Admin, When the Super Admin (All mode) POSTs `received`, Then 403 (R5); the GAL Cashier can confirm it (200).
10a. Given T1, When the Super Admin (All mode, not the creator) POSTs `received` without a note, Then 422 and nothing changes; with the note "Checked GAL cash count sheet 19 Nov", Then 200, the audit row has `all_branches = true` and branch GAL, and the detail's `receipt_by` is a Super Admin.
11. Given T1, When `received_on` is 2026-11-17 (before the transfer) or 2026-11-21 (future), Then 422 with a field error and nothing changes.
12. Given T1, When the GAL Cashier POSTs `action=discrepancy` with note "Short by 500" (13 chars), Then 200, `receipt_status = 'disputed'`, the note is stored, `transfer.receipt_disputed` is audited, balances unchanged; T1 stays in GAL's `incoming` and appears in KDY's `outgoing_disputed`.
13. Given T1, When `discrepancy` is sent with no note or a 9-character note, Then 422 and nothing changes.
14. Given T1 disputed, When the GAL Cashier confirms `received` with the note "Missing 500 found in the bag", Then 200 and `received`; without a note (or a 9-character one) 422 and nothing changes; when instead a second `discrepancy` is sent, Then 422 (R6 message 6).
15. Given T1 awaiting (or disputed), When the KDY Accountant reverses T1, Then T1 `status = 'reversed'`, `receipt_status = 'cancelled'`, a `transfer.receipt_cancelled` row exists, the reversal row has `receipt_status` null, and T1 leaves both widgets; a later `received` returns 422 "This transfer was reversed. No receipt is needed.".
16. Given T1 received, When it is reversed, Then `receipt_status` stays `received` and no `transfer.receipt_cancelled` row is written.
17. Given November locked for GAL and KDY, When the GAL Cashier confirms T1, Then 200 (no lock error) and no ledger line is written. Same when T1's fiscal year is closed.
18. Given T1 awaiting and a lock review for 2026-11, Then `transfers_awaiting_receipt = 1` and `transfers_awaiting_receipt_amount = 10000.00` (`transfers_disputed = 1` once disputed); after AC 5 it is 0. The Year-end preview of the year that holds T1 shows the same fields.
18a. (MUST 1) Given T1 dated 2026-11-18, confirmed with `received_on = 2026-12-02` (today then 2026-12-03), Then the Transfers report with `receipt[]=in_transit`, To 2026-11-30, and the lock review for 2026-11 both still count T1 (1 row, 10,000.00); the report "In transit at 2026-12-02" does not. A transfer reversed on or before D is not in transit at D; one reversed after D is.
19. Given the setting turned off after T1 was posted, Then T1 is still `awaiting`, still listed, and can be confirmed (200).
20. Given transfers aged 0, 3, 5 and 10 days to GAL, Then the buckets are `0_1`:1, `2_3`:1, `4_7`:1, `8_plus`:1 with the right amounts, and `incoming` is sorted oldest first.
21. Given the Super Admin in All mode, Then `incoming` lists open receipts of every branch and `outgoing_disputed` is empty.
22. Given rows in every receipt state, When `GET /transactions?receipt=awaiting,disputed`, Then only those rows return (income, expense and null-receipt transfers never); `receipt=pending` returns 422 "Unknown receipt status: pending."; the Excel export has the four receipt columns and the `Receipt:` filter line.
23. Given the Transfers report with `receipt[]=awaiting`, Then Movements lists only awaiting transfers with the "Receipt" column; without the filter, net moved and the matrix equal the figures before any receipt was confirmed.
24. Given T1 posted with the setting on, Then `TransferReceiptRequested` is dispatched once; after AC 5, `TransferReceiptChanged` (awaiting -> received) is dispatched once (`Event::fake`). A refused request dispatches nothing.
25. Given `PUT /settings {transfer_receipt_required: true}` by a non-super user, Then 403; by the Super Admin, Then 200 and one `settings.updated` row with old `0` and new `1`.
26. Given a fresh seed, Then `transfer.acknowledge` exists, and the Branch Admin, Accountant and Cashier templates have it and Viewer does not; re-seeding an existing database adds it to `*` templates only.
27. (Web) Given the GAL Cashier on the dashboard with T1 open, Then the widget shows T1 with age 2 and both buttons; Confirm with today's date removes it and shows "Receipt of <ref> confirmed."; for the creator the row shows "Sent by you" and no buttons; the Settings screen shows the Transfers checkbox to the Super Admin.
28. Each of AC 1-26 (with 10a and 18a) has an API feature test; `php artisan test`, `pint --test` and `ng build` pass.

## 8. Open questions (assumption in bold)
- **Q1 (accountant) Should an unconfirmed transfer post to "cash in transit"?** Today the receiver's money account is debited on send, so until receipt its book balance includes cash not yet in the till or bank. A transit account would make the books show that, but it doubles the postings, changes every transfer report and the double-entry rules. **Assumption: no posting. The open list (Receipt = Awaiting/Discrepancy) at month-end is the list of reconciling items.** Please confirm, or say if fund returns at least should post to transit. **Decided:** no posting for all four types; the reconciling list is "in transit as at" the month-end (R14).
- **Q2 Default of the setting?** **Off** (backlog: "optional per setting"). Accountant may prefer on for go-live of R2. **Decided:** off in code; the release note recommends turning it on at go-live after granting the key (F1).
- **Q3 Does a reversal need its own receipt?** **No in this item**; the reverse dialog warns when the original was received (S6). **Decided:** agreed; a backlog item is raised for confirming the physical return of a received transfer.
- **Q4 Amount counted on a discrepancy?** **No field; the note carries it.** A discrepancy never changes amounts; the fix is a reversal and a new transfer. **Decided:** agreed (resolutions in R2).
- **Q5 Undo a wrong confirmation?** **No; "Received" is final.** The audit row shows who confirmed and when; a real shortfall found later is settled by a reversal or a new transfer. Raise a backlog item if an undo is needed. **Decided:** agreed.
- **Q6 Should the sender be able to see receipt on its own widget beyond disputes?** **No; it uses the Transactions filter or the Transfers report.** **Decided:** agreed.
- **Q7 Cashier gets the key?** **Yes** (they count the cash). Branch Admins can remove it per branch. **Decided:** agreed; the Viewer never has it.

## Accounting review (accountant, 2026-11-20, before development)
Checked against `LedgerService::transfer/reverse`, `PostingRules::transfer` (between-branch transfers post through the HO current account / BCA pair, as in fund-return.md), `PermissionSeeder::TEMPLATES` and the fund-return sign-off (SHOULD 4).

**Decisions**
- **Q1: no posting, confirmed, for all four types (fund returns included).** The send already posts correct double entry: each branch balances, the inter-branch pair eliminates on consolidation and nothing touches income or expense. A cash-in-transit account would only move the timing of the receiver's debit, double the postings and change every transfer report, for transfers that are mostly hand-carried the same day. Money in transit at month-end is a normal reconciling item: for a bank it is a "deposit in transit" on the bank reconciliation, for cash a line on the cash count sheet. That only works if the list can be produced **as at the month-end date**, not just "open today" (finding 1).
- **Q2: default off in code** (backlog: optional), **but the release note recommends the Super Admin turns it on at go-live**, after granting the key (finding 6). Until it is on, the fund-return weekly bank-to-Transfers-report reconciliation (fund-return SHOULD 4) stays in force.
- **Q3: agreed**, no receipt on the reversal in this item. The S6 warning is enough for now; raise a backlog item for confirming the physical return of a received transfer.
- **Q4: agreed**, no amount field. A discrepancy never changes figures; the note carries the counted amount (finding 3 for the resolution).
- **Q5: agreed**, "Received" is final. The audit row is the evidence; a later shortfall is corrected by reversal + new posting in the current period.
- **Q6: agreed.** The sender sees disputes on its widget and everything else through the filter/report.
- **Q7: agreed**, the Cashier gets the key: the person who counts the cash should confirm it. The Viewer must not have it.

**Findings**
1. **MUST: transfers in transit as at a date.** Define once (in `TransferReceipts`) and use for the Period locks review (R8/F7), the Year-end preview and a new Transfers report filter option "In transit at end date": between-branch transfer, `receipt_status` not null, `txn_date <= D`, not reversed on or before D, and (`receipt_status in (awaiting, disputed)` or `received_on > D`). Today's definition (current state only) loses every item confirmed after month-end but before the accountant prepares the reconciliation. New AC: T1 dated 2026-11-18, confirmed with `received_on = 2026-12-02`; the report "In transit at 2026-11-30" and the lock review for 2026-11 both still count T1 (1 row, 10,000.00); "In transit at 2026-12-02" does not.
2. **SHOULD: show amount as well as count** in the Period locks review and Year-end preview (`transfers_awaiting_receipt` + `transfers_awaiting_receipt_amount`), and show disputed separately. Information only; agreed that neither a lock nor the close is blocked (R8): confirming posts nothing, and a lock that one branch's open receipt could hold back would let one branch stop the whole close.
3. **SHOULD: discrepancy handling. A note is enough; a dispute blocks nothing.** Blocking postings or locks would not fix the books; only a posting does. Add to R2/F4 the three resolutions and their entries: (a) the missing money arrives -> confirm; (b) wrong amount sent -> sender reverses and posts the correct amount; (c) money lost or stolen in transit -> sender reverses, posts the amount actually delivered, and records the shortfall as an expense of the **sending** branch (category "Cash shortage / loss") with the dispute ref in the note. Also: confirming a `disputed` transfer (`disputed -> received`) must require a note (10-500) saying how it was settled; add to R6 item 7 and AC 14.
4. **SHOULD: remote confirmation by the Super Admin.** F6 lets the Super Admin confirm a KDY -> GAL transfer without anyone counting the cash at GAL, which defeats the purpose of the control. Allow it, but require a note (10-500) when the confirmer is acting in All mode, and show "Confirmed by Super Admin" in the Receipt panel. For fund returns into MAIN's bank this is the normal case: the note should cite the bank statement date.
5. **Fine: segregation (R5).** The creator can never confirm, Super Admin included; sending-branch users get 404 (R4); confirming posts nothing, so the confirmer cannot move money with it. The confirmer cannot reverse (reversal is owned by the sending branch; fund-return reversal stays Super Admin only).
6. **SHOULD: role defaults on existing databases.** As written, an existing database gets the key only on `*` templates (Branch Admin); Accountant and Cashier branch roles do not. If the Super Admin turns the setting on before granting it, only Branch Admins and the Super Admin can confirm, which concentrates the step in the people least likely to count the cash. Settings info text (S1) and the release note: "Grant 'Confirm transfer receipt' to the Accountant and Cashier roles in each branch before turning this on." Template defaults as in §2 are right.
7. **SHOULD: audit detail.** `transfer.receipt_cancelled` must also carry old `{receipt_status}` (as the other two rows do) and the reversal ref. Every receipt row keeps both `received_on` (business date, may be back-dated) and `receipt_at` (system time) so a back-dated confirmation is visible. Otherwise R12 is complete: who, when, old/new, branch = receiving branch, refusals write nothing, nothing deleted.
8. **Fine: reversal (R7).** Open receipts become "Cancelled (reversed)" in the same DB transaction; a received transfer stays received and the reversal posts in the current open period under the existing lock rules.
9. **Fine: no effect on figures (R1, AC 1, 5, 23).** Keep AC 5's `ledger:verify` and unchanged balances; consolidated figures and the Transfers matrix are unaffected.

**Verdict:** approved for development, subject to MUST 1.
