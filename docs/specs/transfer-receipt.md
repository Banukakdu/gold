# Spec: Transfer receipt acknowledgement (receiving branch confirms the cash handover)

## 1. Goal and scope
Backlog **AG-052** (E6, Could, R2, 3 points): "As a receiving branch, I want to acknowledge receipt of a transfer so that physical cash handover is confirmed." AC: status flow Sent -> Received (optional per setting); unacknowledged transfers are listed on the receiving branch's dashboard.

Raised in priority by the fund return sign-off (fund-return.md R10, accountant SHOULD 4): today a branch can record a return that never reached Main's bank, and Main only finds out at its weekly bank-to-Transfers-report reconciliation.

**Applies to every transfer between two branches:** `opening_allocation`, `fund_allocation` (Top-up), `fund_return`, `branch_transfer`. Not to `internal_transfer` (same branch), `capital`, `income`, `expense`, `reversal` or `year_end_close`.

**It is a control and confirmation step only. It posts nothing.** The money is posted on send (`LedgerService::transfer`, unchanged). Acknowledging or disputing never writes a ledger line, never changes `accounts.current_balance`, `transactions.status` or `transactions.amount`, and never changes a report figure. See Q1 for the accountant.

What exists today (`feature/transfer-receipt`, 2026-11-20): `transactions` has `branch_id` (sending branch), `related_branch_id` (receiving branch) and `status` (`posted|reversed|void`; `pending|rejected` reserved for AG-051/AG-064). `approved_by`/`approved_at` are reserved for AG-051 and are **not** reused here. Settings are `App\Support\Settings` + `SettingsController::EDITABLE`. The dashboard is `DashboardController` (`perm:report.dashboard`).

**Out of scope:** notifications (AG-094; only a hook, R11), transfer approval (AG-051), a "cash in transit" account or any posting (Q1), an amount-counted field (Q4), acknowledging reversals (Q3), undoing a confirmation (Q5), backfilling transfers posted before this release (R3).

## 2. Personas and permissions
**New key `transfer.acknowledge`** in `PermissionSeeder::PERMISSIONS` (after `transfer.reverse`). Meaning: may confirm receipt of, or report a discrepancy on, a transfer **received by** the active branch.

| Template | Has it | How |
|---|---|---|
| Branch Admin (`*`) | yes | added automatically to `*` templates by `PermissionSeeder::run` |
| Accountant | yes | added to `TEMPLATES['Accountant']` (new installs). Existing databases: Super Admin adds it through the Roles screen (release note; users-and-roles finding 7: new keys never reach other templates or branch copies automatically) |
| Cashier | yes | as Accountant. The cashier is the person who physically counts the cash |
| Viewer | no | |
| Super Admin | yes (all keys) | any branch, All mode included |

Other keys used: `report.dashboard` (widget), `transaction.view` (list, detail, receipts endpoint), `report.view` (Transfers report), `transfer.reverse` (unchanged), Super Admin `super` (setting).

**Segregation (R5):** the user who created the transfer can never acknowledge it, Super Admin included.

## 3. User flows
- **F1 Setting (Super Admin).** Settings > Transfers > tick "Receiving branch must confirm receipt of transfers between branches" > Save > "Settings saved.". From now on each new between-branch transfer starts as "Awaiting receipt".
- **F2 Branch transfer (KDY -> GAL).** KDY Accountant posts KDY Cash -> GAL Cash 10,000.00 (unchanged flow). Success text adds "GAL must confirm receipt.". The cash goes by hand.
- **F3 Confirm (GAL Cashier).** Dashboard > widget "Transfers to confirm" lists KDY-TRF-… 10,000.00, age 0 days > "Confirm receipt" > dialog: Received on (default today), Note (optional) > Confirm > row leaves the widget; the entry shows "Received on 20 Nov 2026 by <name>".
- **F4 Discrepancy (GAL Cashier).** Same row > "Report a discrepancy" > Note (required, e.g. "Envelope held 9,500.00, counted with K. Silva") > Report > row stays in GAL's widget as "Discrepancy reported" and appears in KDY's widget under "Disputed by the receiving branch". Resolution: either the missing cash arrives and GAL confirms receipt (F3), or KDY reverses the transfer (receipt becomes "Cancelled (reversed)") and posts the correct amount.
- **F5 Fund return (GAL -> MAIN).** A MAIN user with `transfer.acknowledge`, or the Super Admin (not the creator), confirms in MAIN's widget after checking Main's bank.
- **F6 Super Admin, All branches.** The widget lists every open receipt in every branch, with From and To columns. The Super Admin may confirm any transfer they did not create; the audit row carries the receiving branch.
- **F7 Month-end.** Before locking a month, the Period locks review shows "N transfers dated up to <month> are not yet confirmed received" (information only). The accountant uses the Transactions list filter Receipt = Awaiting/Discrepancy as the list of transfers in transit.

## 4. Screens
**S1 Settings** `/settings` (existing). New section "Transfers" after "Fiscal year":

| Field | Type | Rules |
|---|---|---|
| Receiving branch must confirm receipt of transfers between branches | checkbox | default off. Info: "Applies to transfers posted after you turn it on. It does not change balances or reports." "Last changed by …" as other fields |

**S2 Dashboard widget "Transfers to confirm"** (`features/dashboard`). Shown when the user has `report.dashboard` and `transaction.view`, and (the setting is on, or the endpoint returns at least one row).
- **Ageing strip** (from `summary.buckets`): four tiles "0-1 days", "2-3 days", "4-7 days", "Over 7 days", each with count and amount; "Over 7 days" uses the danger colour when count > 0.
- **Table "To confirm"** (incoming, oldest first): Date · Age (days) · Ref (link to `/transactions/:id`) · Type · From (branch · account) · To account · Amount · Receipt (tag "Awaiting" / "Discrepancy" with the note on hover/expand) · Actions. All mode adds To branch. Rows aged 4-7 days amber, over 7 red (with the text tag too, not colour only).
- **Actions** (only when `can_acknowledge`): "Confirm receipt" and "Report a discrepancy" (the latter hidden when already disputed). When the user is the creator, no buttons and the hint "Sent by you".
- **Table "Disputed by the receiving branch"** (outgoing disputed rows, branch mode only, hidden when empty): Date · Ref · To (branch · account) · Amount · Note · Reported by/at. No actions (resolution is a receipt by the other branch or a reversal here).
- Footer link "View all in Transactions" -> `/transactions?receipt=awaiting,disputed`. The widget shows at most 50 rows per table, then "and N more".
- Empty: "No transfers waiting for confirmation."
- **Confirm dialog:** Received on (date, required, default today, min = transfer date, max = today), Note (optional, max 500). Buttons Confirm / Cancel. Success toast "Receipt of <ref> confirmed.".
- **Discrepancy dialog:** text "Report that the cash or deposit does not match <ref> (<amount>). Balances do not change. The sending branch sees your note." Note (required, 10-500). Buttons Report / Cancel. Success "Discrepancy on <ref> reported.".
- Errors: API `message` in the dialog's `.alert`; on 422 the widget reloads.

**S3 Transaction detail** `/transactions/:id` (existing), for the four transfer types. New "Receipt" panel: Status label (R2) · Received on · By / at · Note. Same two buttons and dialogs as S2 when `can_acknowledge`. Panel hidden when `receipt_status` is null ("Not required").

**S4 Transactions list** `/transactions` (existing). Filter "Receipt": checkboxes Awaiting / Received / Discrepancy / Cancelled (URL `receipt`, comma list; chip "Receipt: Awaiting ×"). Status column: second tag with the receipt label for rows with a receipt status. Mobile card: the tag on line 3.

**S5 Transfers & Allocations report** `/reports/transfers` (existing). Filter "Receipt" (multi, same four values; default all). Movements column "Receipt" after Status (label, or "—" when null).

**S6 Reverse dialog** (existing). For a transfer with receipt "Received": warning line "<BR> confirmed receipt on <date>. The reversal sends the money back; arrange the physical return." Not blocking.

## 5. API
| Method, path | Guard | Request | Response / errors |
|---|---|---|---|
| `GET /v1/transfer-receipts` (new) | `branch`, `perm:transaction.view` | `state` optional comma list of `awaiting,disputed` (default both) | `{summary:{buckets:[{key:'0_1'\|'2_3'\|'4_7'\|'8_plus', count, amount}], count, amount}, incoming:{data:[row], total}, outgoing_disputed:{data:[row], total}}`. Row: `id, ref_no, type, txn_date, age_days, from:{branch_code, account}, to:{branch_code, account}, amount, receipt_status, receipt_note, receipt_by:{id,name}\|null, receipt_at, created_by:{id,name}, can_acknowledge`. 50 rows per list, oldest first. Summary covers `incoming` only |
| `POST /v1/transactions/{id}/receipt` (new) | `branch`, `perm:transfer.acknowledge` | `action` = `received`\|`discrepancy`; `received_on` (Y-m-d; required for `received`, >= transfer date, <= `BusinessDate::today()`); `note` (`received`: optional, max 500; `discrepancy`: required, 10-500, trimmed) | 200 with the transaction's receipt fields. Errors in R6 |
| `GET /v1/settings`, `PUT /v1/settings` | `super` (unchanged) | + `transfer_receipt_required` (boolean) | + `transfer_receipt_required: bool`. Written with the existing single `settings.updated` row |
| `GET /v1/transactions`, `POST /v1/transactions/export`, `GET /v1/transactions/{id}` | unchanged | list/export: + `receipt` (comma list of `awaiting,received,disputed,cancelled`; other values 422 "Unknown receipt status: <value>.") | rows + `receipt_status, received_on, receipt_note, receipt_by:{id,name}\|null, receipt_at`; detail + `can_acknowledge`. Excel adds columns Receipt · Received on (date cell) · Receipt by · Receipt note after Status, and `Receipt: …` in the Filters header line |
| `GET /v1/reports/transfers`, `POST /v1/reports/transfers/export` | unchanged | + `receipt[]` (same values, 422 as above) | movements + `receipt_status`, `received_on`; export adds column "Receipt" |
| `GET /v1/period-locks/review` | `super` (unchanged) | — | + `transfers_awaiting_receipt` (int): transfers with receipt `awaiting` or `disputed`, dated <= the period end, sent or received by a branch the lock newly closes. Information only |
| `POST /v1/transfers`, `POST /v1/allocations` | unchanged | — | response + `receipt_status` |

**Schema** (one migration after the latest one): `transactions` gains `receipt_status` string(20) null, `received_on` date null, `receipt_note` text null, `receipt_by` FK users null, `receipt_at` timestamp null; index `(related_branch_id, receipt_status)`. Settings row `transfer_receipt_required` = `0` inserted where missing; `Settings::transferReceiptRequired(): bool`. No backfill.

## 6. Business rules
- **R1 No postings.** Acknowledgement and discrepancy update only the five receipt columns, through a new `App\Services\TransferReceipts` service (not `LedgerService`, which owns money postings). They never touch `ledger_entries`, `accounts.current_balance`, `status` or `amount`. All reports, the dashboard money figures, the trial balance and `ledger:verify` are unchanged by them.
- **R2 States** (`receipt_status`; labels on screen, PDF and Excel):
  - `null` "Not required" (setting off at send, other types, all transfers before this release);
  - `awaiting` "Awaiting receipt" (Sent);
  - `received` "Received" (final);
  - `disputed` "Discrepancy reported";
  - `cancelled` "Cancelled (reversed)".
  - Allowed moves: `awaiting -> received`, `awaiting -> disputed`, `disputed -> received`, `awaiting|disputed -> cancelled` (reversal only, R7). Nothing else.
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
  7. 422 field errors (dates, note).
  - Concurrency: the update is conditional (`where receipt_status in (allowed from-states) and status = 'posted'` under `lockForUpdate`); the loser of two simultaneous requests, or of a request racing a reversal, gets the matching 422 above.
- **R7 Reversal.** `LedgerService::reverse` of a transfer with `awaiting` or `disputed`, in the same DB transaction: `receipt_status = 'cancelled'` (receipt_by/at/note kept as they were), audit `transfer.receipt_cancelled`. `received` stays `received`; the reverse dialog warns (S6). The reversal row itself has `receipt_status` null (Q3). Reversal rights are unchanged (fund return reversal stays Super Admin only).
- **R8 Period lock and year-end.** Confirming or disputing posts nothing, so it is **allowed when the transfer's month is locked or its fiscal year is closed**, and `received_on` may fall in a locked month. A lock never blocks it and it never needs an unlock. Information only: Period locks review shows `transfers_awaiting_receipt` (F7); the Year-end preview shows the same count for transfers dated in that year (field `transfers_awaiting_receipt`, not a blocking `check`). Reopening a year changes no receipt.
- **R9 Ageing.** `age_days` = `BusinessDate::today()` − `txn_date` in calendar days (Asia/Colombo). Buckets 0-1, 2-3, 4-7, 8+. Amounts via `Money` (integer cents), dates via `whereDate`.
- **R10 Scope of the widget.** Branch mode: `incoming` = `related_branch_id` = active branch and `receipt_status in (awaiting, disputed)` and `status = 'posted'`; `outgoing_disputed` = `branch_id` = active branch and `disputed`. All mode: `incoming` = every open receipt, `outgoing_disputed` empty.
- **R11 Hook for AG-094.** After commit, dispatch `App\Events\TransferReceiptRequested` (a transfer posted as `awaiting`) and `App\Events\TransferReceiptChanged` (`received`, `disputed`, `cancelled`; payload: transaction id, old and new state, user id). No listener in this item; AG-094 adds them (e.g. tell the sender about a discrepancy).
- **R12 Audit** (`AuditLog::record` on the transaction, branch = receiving branch):
  - `transfer.receipt_received`: old `{receipt_status}`, new `{receipt_status:'received', received_on, note}`;
  - `transfer.receipt_disputed`: old `{receipt_status}`, new `{receipt_status:'disputed', note}`;
  - `transfer.receipt_cancelled` (R7): new `{receipt_status:'cancelled', reversal_id}`;
  - the existing `transaction.<type>` creation row gains `receipt_status`;
  - the setting change is the existing `settings.updated` row.
  - The Audit log screen shows the three new events in the "Transactions" group, labelled "Receipt confirmed", "Discrepancy reported", "Receipt cancelled (reversed)". Refusals write nothing.
- **R13 Edge cases.** Deactivated receiving branch or account: still confirmable (Super Admin, All mode). A user who is a member of both branches but did not create the transfer may confirm in the receiving branch. A void never applies (transfers are reversed, not voided).

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
11. Given T1, When `received_on` is 2026-11-17 (before the transfer) or 2026-11-21 (future), Then 422 with a field error and nothing changes.
12. Given T1, When the GAL Cashier POSTs `action=discrepancy` with note "Short by 500" (13 chars), Then 200, `receipt_status = 'disputed'`, the note is stored, `transfer.receipt_disputed` is audited, balances unchanged; T1 stays in GAL's `incoming` and appears in KDY's `outgoing_disputed`.
13. Given T1, When `discrepancy` is sent with no note or a 9-character note, Then 422 and nothing changes.
14. Given T1 disputed, When the GAL Cashier confirms `received`, Then 200 and `received`; when instead a second `discrepancy` is sent, Then 422 (R6 message 6).
15. Given T1 awaiting (or disputed), When the KDY Accountant reverses T1, Then T1 `status = 'reversed'`, `receipt_status = 'cancelled'`, a `transfer.receipt_cancelled` row exists, the reversal row has `receipt_status` null, and T1 leaves both widgets; a later `received` returns 422 "This transfer was reversed. No receipt is needed.".
16. Given T1 received, When it is reversed, Then `receipt_status` stays `received` and no `transfer.receipt_cancelled` row is written.
17. Given November locked for GAL and KDY, When the GAL Cashier confirms T1, Then 200 (no lock error) and no ledger line is written. Same when T1's fiscal year is closed.
18. Given T1 awaiting and a lock review for 2026-11, Then `transfers_awaiting_receipt = 1`; after AC 5 it is 0.
19. Given the setting turned off after T1 was posted, Then T1 is still `awaiting`, still listed, and can be confirmed (200).
20. Given transfers aged 0, 3, 5 and 10 days to GAL, Then the buckets are `0_1`:1, `2_3`:1, `4_7`:1, `8_plus`:1 with the right amounts, and `incoming` is sorted oldest first.
21. Given the Super Admin in All mode, Then `incoming` lists open receipts of every branch and `outgoing_disputed` is empty.
22. Given rows in every receipt state, When `GET /transactions?receipt=awaiting,disputed`, Then only those rows return (income, expense and null-receipt transfers never); `receipt=pending` returns 422 "Unknown receipt status: pending."; the Excel export has the four receipt columns and the `Receipt:` filter line.
23. Given the Transfers report with `receipt[]=awaiting`, Then Movements lists only awaiting transfers with the "Receipt" column; without the filter, net moved and the matrix equal the figures before any receipt was confirmed.
24. Given T1 posted with the setting on, Then `TransferReceiptRequested` is dispatched once; after AC 5, `TransferReceiptChanged` (awaiting -> received) is dispatched once (`Event::fake`). A refused request dispatches nothing.
25. Given `PUT /settings {transfer_receipt_required: true}` by a non-super user, Then 403; by the Super Admin, Then 200 and one `settings.updated` row with old `0` and new `1`.
26. Given a fresh seed, Then `transfer.acknowledge` exists, and the Branch Admin, Accountant and Cashier templates have it and Viewer does not; re-seeding an existing database adds it to `*` templates only.
27. (Web) Given the GAL Cashier on the dashboard with T1 open, Then the widget shows T1 with age 2 and both buttons; Confirm with today's date removes it and shows "Receipt of <ref> confirmed."; for the creator the row shows "Sent by you" and no buttons; the Settings screen shows the Transfers checkbox to the Super Admin.
28. Each of AC 1-26 has an API feature test; `php artisan test`, `pint --test` and `ng build` pass.

## 8. Open questions (assumption in bold)
- **Q1 (accountant) Should an unconfirmed transfer post to "cash in transit"?** Today the receiver's money account is debited on send, so until receipt its book balance includes cash not yet in the till or bank. A transit account would make the books show that, but it doubles the postings, changes every transfer report and the double-entry rules. **Assumption: no posting. The open list (Receipt = Awaiting/Discrepancy) at month-end is the list of reconciling items.** Please confirm, or say if fund returns at least should post to transit.
- **Q2 Default of the setting?** **Off** (backlog: "optional per setting"). Accountant may prefer on for go-live of R2.
- **Q3 Does a reversal need its own receipt?** **No in this item**; the reverse dialog warns when the original was received (S6).
- **Q4 Amount counted on a discrepancy?** **No field; the note carries it.** A discrepancy never changes amounts; the fix is a reversal and a new transfer.
- **Q5 Undo a wrong confirmation?** **No; "Received" is final.** The audit row shows who confirmed and when; a real shortfall found later is settled by a reversal or a new transfer. Raise a backlog item if an undo is needed.
- **Q6 Should the sender be able to see receipt on its own widget beyond disputes?** **No; it uses the Transactions filter or the Transfers report.**
- **Q7 Cashier gets the key?** **Yes** (they count the cash). Branch Admins can remove it per branch.
