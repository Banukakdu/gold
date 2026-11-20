# Spec: Fund return (branch returns surplus funds to Main)

## 1. Goal and scope
Backlog **AG-042** (E5, Should, R2): "As a Super Admin, I want branches to return surplus funds to Main so that cash is centralised." AC: Branch-to-Main transfer type 'Fund Return'; requires branch permission `transfer.create`.

**Most of AG-042 already exists** (built with AG-050/053 and double entry). This spec records what exists and covers only the gap.

### What already exists (verified on `feature/fund-return`, 2026-11-20)
| Area | Where | State |
|---|---|---|
| Type | `Transaction::FUND_RETURN = 'fund_return'`, in `TRANSFER_TYPES`; ref prefix `RET` (`<BR>-RET-YYYY-NNNNNN`) | done |
| Entry point (API) | `POST /api/v1/transfers` (`perm:transfer.create`). `LedgerService::transferType()` sets `fund_return` when the from-account is in a non-main branch and the to-account is in MAIN | done |
| Branch isolation | `TransferController::transfer` loads the from-account through the branch scope, so a branch user can only send from the active branch. Super Admin in "All branches" mode can send from any branch (AG-053) | done |
| Postings | `PostingRules::transfer`: B Dr HO current account / Cr money account; MAIN Dr money account / Cr BCA(B). 4 lines, balanced per branch (double-entry R3, AC 7) | done |
| Insufficient balance | `assertFunds` always blocks (as AG-050; the AG-040 block/warn setting does not apply) | done |
| Inactive account/branch, same account, future date, amount > 0, 2 decimals | `LedgerService::transfer` + controller validation | done |
| Period lock / year-end | `assertOpenFor` checks both branches; `assertYearOpen` refuses closed years | done (message gap, G2) |
| Reversal | `POST /transactions/{id}/reverse` (`perm:transfer.reverse`); not in `SUPER_ADMIN_TYPES`, so the sending branch may reverse. 4 mirrored lines; MAIN's money account is funds-checked | done |
| Audit | `transaction.fund_return` ("Fund return recorded"), reversal writes `transaction.reversal` and `transaction.reversed` | done |
| Reports | Transfers & Allocations (movement type "Fund return", matrix B→MAIN); Branch Comparison (transfers in/out); excluded from Income & Expense; Transaction search type filter and "In" rows for MAIN; trial balance via inter-branch accounts | done |
| Web | Transfer money form (`/transfers/new`) can pick a MAIN account; list, report and money pipe show the label "Fund return" | done (UX gap, G1) |
| Tests | `PostingTest::test_ac7_fund_return` (Super Admin only), `TransfersReportTest`, `VerifyAndControlsTest` | partial (G3) |

### Gap (this spec)
- **G1** The Transfer money form does not tell the user the transfer is a fund return, and the To account list does not mark Main accounts.
- **G2** When only MAIN's month is locked, a fund return (or its reversal) fails with "The period YYYY-MM is locked.", which a branch user cannot act on (their own month is open). It must name MAIN, as branch transfers already do.
- **G3** No tests for a branch user posting a fund return, permission refusal, insufficient balance, MAIN lock, closed year, reversal rights, and audit.
- **G4** (control gap found while checking, see Q1) A MAIN-branch user who is not Super Admin but has `transfer.create` can post MAIN → branch through `/transfers`. That creates a `fund_allocation`, a type only a Super Admin may post or reverse.

Out of scope: approvals (AG-051), receipt acknowledgement (AG-052), a separate "Return funds" screen, a new permission key, any change to postings or reports.

## 2. Personas and permissions
No new key. Real keys: `transfer.create`, `transfer.reverse`, `transaction.view`, `report.view`.

| Persona | Initiate fund return | Reverse it |
|---|---|---|
| Super Admin, "All branches" | yes, from any branch account | yes |
| Super Admin, branch KDY selected | yes, from KDY accounts | yes (KDY's) |
| Branch Admin template (`*`) | yes, from own branch | yes, own branch's |
| Accountant template (`transfer.create`, `transfer.reverse`) | yes, from own branch | yes, own branch's |
| Cashier, Viewer templates | no (403, no menu) | no |
| MAIN-branch user (non-super) with `transfer.reverse` | n/a | no: the row belongs to the sending branch (404), as for all incoming rows (transaction-search R10) |

## 3. User flows
**F1 Branch Accountant (GAL).** Switcher = GAL → menu "Transfer money" → From = GAL · Cash → To = an account under the **Main** group (e.g. "MAIN · Bank") → the form shows "Type: Fund return to Main" → Amount, Date, Reason → Transfer → "Fund return GAL-RET-2026-000123 posted."
**F2 Super Admin, All branches.** Same screen; From lists every branch's accounts. Picking a non-main From and a MAIN To shows the same hint. Posting with MAIN From and a branch To shows "Type: Top-up" (Super Admin may do this, G4).
**F3 Reverse.** GAL Accountant → Transactions → the RET row → Reverse → reason, date → a reversal `GAL-REV-…` is posted; the original shows "Reversed by …".

## 4. Screens
**Transfer money** (`/transfers/new`, guard `transfer.create`), existing. Changes only:
- To account `<select>`: options grouped with `<optgroup>`: "Main" first (accounts whose branch `is_main`), then one group per branch code. Option text unchanged (`CODE · name`).
- Read-only line under the To field, shown once both accounts are chosen: "Type: Internal transfer" (same branch), "Type: Fund return to Main" (non-main → MAIN), "Type: Branch transfer" (non-main → other non-main), "Type: Top-up" (MAIN → branch; Super Admin only, see G4). Same rule as `LedgerService::transferType`.
- Success text uses the type label: "Fund return GAL-RET-2026-000123 posted." (other types: "Transfer … posted." as today).
- Errors: the API message in the existing alert (insufficient balance, locked period, closed year, inactive account).
- For a non-super user in MAIN, the To list excludes accounts of other branches (G4).

No other screen changes.

## 5. API
- `GET /api/v1/accounts/destinations`: add `is_main` to the embedded branch (`branch:id,code,name,is_main`). No other change.
- `POST /api/v1/transfers`: unchanged request/response. New errors:
  - G2: 422 `The period YYYY-MM is locked for MAIN (head office side of this fund return).` when the sending branch's month is open and MAIN's is locked. Same text for the reversal of a fund return (with "of this fund return").
  - G4: 403 `Only a Super Admin can send funds from Main to a branch. Use "Fund a branch".` when the from-account is in MAIN, the to-account is in another branch and the user is not Super Admin.

## 6. Business rules
- **R1 Type.** A transfer is a fund return exactly when the from-account's branch is not main and the to-account's branch is MAIN. The type is derived on the server; the client hint is display only.
- **R2 Postings** (unchanged, double-entry R3), GAL Cash → MAIN Bank, 20,000.00: GAL Dr 15001 HO current account / Cr Cash; MAIN Dr Bank / Cr 15003 BCA–GAL. `transactions.branch_id` = GAL, `related_branch_id` = MAIN, `amount` = 20,000.00.
- **R3 Funds.** The from-account balance must be ≥ amount, always (no warn mode). Checked under the row lock.
- **R4 Period lock.** Both GAL and MAIN months must be open. If GAL's month is locked: the existing "The period YYYY-MM is locked." If only MAIN's is locked: the G2 message. Same for the reversal on its date.
- **R5 Year-end.** No fund return or reversal dated in a closed year ("<FY label> is closed."). The inter-branch accounts are balance-sheet accounts and are not touched by the year-end close.
- **R6 Reversal.** Only the sending branch (or Super Admin) with `transfer.reverse`; date ≥ original and ≤ today; MAIN's money account must still hold the amount (net-credit check), otherwise 422 "Insufficient balance in <MAIN account> (available …)".
- **R7 Audit.** One `transaction.fund_return` row per posting (ref, amount, date, from/to accounts), branch = sending branch; reversal writes `transaction.reversal` (new entry) and `transaction.reversed` (original, with reason). Refused attempts write nothing.
- **R8 Isolation.** A branch user cannot send from another branch's account (404, account not found through the scope). Destinations list all active money accounts of all branches (existing behaviour).
- **R9 G4.** Non-super users may not post MAIN → other branch through `/transfers`. MAIN → MAIN (internal) stays allowed for MAIN users with `transfer.create`.

## 7. Acceptance criteria
Data: reports E-dataset branches MAIN, KDY, GAL; GAL Cash balance 40,000.00; MAIN Bank active; GAL Accountant (template) and GAL Cashier; KDY Accountant; a MAIN Accountant (non-super).

1. Given the GAL Accountant with X-Branch-Id = GAL, When POST `/transfers` GAL Cash → MAIN Bank 20,000.00 today with note "Surplus", Then 201, `type = fund_return`, `ref_no` matches `GAL-RET-YYYY-\d{6}`, the 4 lines of R2, GAL Cash balance 20,000.00 and MAIN Bank up by 20,000.00.
2. Given the GAL Cashier, When the same POST is sent, Then 403 and nothing is posted; the "Transfer money" menu item is not shown.
3. Given the KDY Accountant with X-Branch-Id = KDY, When POST with from = GAL Cash, Then 404 and nothing is posted.
4. Given the Super Admin in All branches mode, When POST GAL Cash → MAIN Bank 5,000.00, Then 201 and `type = fund_return`, `branch_id` = GAL.
5. Given GAL Cash 40,000.00, When the GAL Accountant returns 40,000.01, Then 422 "Insufficient balance in Cash (available 40000.00)." and no transaction, line or audit row is written.
6. Given October locked for MAIN only, When the GAL Accountant posts a fund return dated in October, Then 422 "The period 2026-10 is locked for MAIN (head office side of this fund return)." and nothing is posted.
7. Given October locked for GAL, When the same fund return is posted, Then 422 "The period 2026-10 is locked." and nothing is posted.
8. Given a closed financial year, When a fund return is dated inside it, Then 422 "<FY label> is closed."
9. Given the fund return of AC 1, When the GAL Accountant reverses it today with a reason, Then 201, a `reversal` with 4 mirrored lines balanced in GAL and in MAIN, the original's status is `reversed`, GAL Cash back to 40,000.00, and audit rows `transaction.fund_return`, `transaction.reversal` and `transaction.reversed` (with the reason) exist.
10. Given the fund return of AC 1, When the MAIN Accountant (X-Branch-Id = MAIN, has `transfer.reverse`) tries to reverse it, Then 404. The Super Admin can reverse it.
11. Given the fund return of AC 1 and MAIN Bank later reduced below 20,000.00, When it is reversed, Then 422 "Insufficient balance in Bank (available …)." and nothing is posted.
12. Given the fund return of AC 1 and only MAIN's month locked, When it is reversed in that month, Then 422 with the G2 message ("…of this fund return").
13. Given the fund return of AC 1, Then the Transfers report shows it as "Fund return" GAL → MAIN 20,000.00; Branch Comparison shows 20,000.00 in GAL Transfers out and MAIN Transfers in; Income & Expense is unchanged; `ledger:verify` passes.
14. Given the GAL Accountant on `/transfers/new`, When the To list opens, Then MAIN accounts are under a "Main" group first; choosing GAL Cash → MAIN Bank shows "Type: Fund return to Main"; GAL Cash → GAL Bank shows "Type: Internal transfer"; GAL Cash → KDY Cash shows "Type: Branch transfer".
15. Given AC 1 done from the screen, Then the success text is "Fund return GAL-RET-YYYY-NNNNNN posted." and the From balance refreshes.
16. Given `GET /accounts/destinations`, Then each row's `branch` has `is_main`.
17. (G4) Given the MAIN Accountant (non-super, `transfer.create`), When POST `/transfers` MAIN Bank → KDY Cash, Then 403 with the G4 message and nothing is posted; MAIN Bank → MAIN Cash still returns 201 `internal_transfer`. The Super Admin may still post MAIN → KDY through `/transfers` (`fund_allocation`).
18. Each of AC 1-12 and 17 has an API feature test; `php artisan test` and `pint --test` pass; `ng build` passes.

## 8. Open questions (with assumptions)
- **Q1 G4 in this item?** It is the reverse direction of the same endpoint and a control gap (controls review finding 8 treats allocations as Super Admin only). *Assumption:* fix it here (one guard, AC 17). The accountant may move it to its own item.
- **Q2 Who initiates?** Backlog says the branch (`transfer.create`). *Assumption:* the branch with `transfer.create`, and the Super Admin (any branch, AG-053). MAIN users cannot "pull" funds from a branch.
- **Q3 Separate "Return funds to Main" screen or menu?** *Assumption:* no; the existing Transfer money form with the type hint is the entry point (AG-042 is 2 points).
- **Q4 Block or warn on insufficient balance?** *Assumption:* always block, as AG-050; the AG-040 setting is for allocations from Main only.
- **Q5 Should a lock on MAIN block a fund return?** *Assumption:* yes (MAIN's Bank is debited in that month; double-entry sign-off already expects this). Only the message changes.

## Accounting review (accountant, 2026-11-20, before development)
Checked against `LedgerService::transfer/reverse/assertOpenFor/transferType`, `PostingRules::transfer`, `TransferController`, `ReportService` (Branch Comparison, consolidated trial balance).

**Postings: fine.** R2 is correct double entry: GAL Dr HO current account / Cr Cash; MAIN Dr Bank / Cr BCA(GAL). Each branch balances on its own, the inter-branch pair offsets exactly and is eliminated on consolidation. Nothing goes to income or expense, so consolidated profit is unchanged. The reversal posts the 4 mirrored lines, dated on the reversal date in the current open period, and the original row is kept. Q5 is agreed: a lock on MAIN must block, because MAIN's Bank moves in that month. Year-end (R5) is fine: the inter-branch accounts are balance-sheet accounts.

**Decisions**
- **Q1 / G4: yes, block with 403, in this item.** Moving Main's money to a branch is an allocation. It is Super Admin only (controls finding 8; `SUPER_ADMIN_TYPES` already stops others reversing a `fund_allocation`). Today a MAIN user with `transfer.create` can create a `fund_allocation` through `/transfers` that they then cannot reverse. That is a gap in segregation of duties, and R9 / AC 17 close it.
- **Over-return: limit it to the available balance of the from account, not to the funds allocated.** A branch legitimately sends its own takings to Main, so a return may exceed what Main gave it. In that case the branch's HO current account simply moves to a debit, which is offset by the branch result at the year-end close and eliminated on consolidation. Do not add an "allocated" cap. Always block at the from account's balance (R3, no warn mode, no overdraft). The check is per money account, not on the branch total.

**Findings**
1. **MUST: G4 guard on the server** (R9, AC 17). In `TransferController::transfer`, before posting: if the from branch `is_main` and the to branch is not MAIN and the user is not Super Admin, return 403 with the G4 message. Nothing is posted and no audit row is written. MAIN → MAIN internal transfers stay allowed. Hiding accounts in the web To list is only a convenience, not the control.
2. **MUST: reversing a fund return is Super Admin only.** Add `Transaction::FUND_RETURN` to `TransferController::SUPER_ADMIN_TYPES`. Reasons: (a) the reversal credits MAIN's Bank, which takes Main's money back to the branch. That is the same movement as finding 1, and the branch does not control or reconcile MAIN's bank. (b) As written, AC 11 would show a branch user MAIN's bank balance ("available …"). Replace the following in the spec:
   - §2 table: Branch Admin / Accountant "Reverse it" becomes **no (403 "Only a Super Admin can reverse this entry.")**. Super Admin (any mode): yes.
   - R6: "Only the Super Admin with `transfer.reverse` (or `*`)…"
   - AC 9, 11 and 12: the actor is the Super Admin.
   - New AC 9a: GAL Accountant (has `transfer.reverse`) reverses the AC 1 fund return → 403, nothing posted, original still `posted`.
   - AC 10 stands (MAIN Accountant → 404).
   - F3: the branch asks the Super Admin to reverse.
3. **MUST: the G2 message also covers the reversal.** `reverse()` passes `headOfficeSide` only for `branch_transfer`. It must also do so for `fund_return`, with the text "…of this fund return" (AC 6, 12). The GAL-lock path keeps the plain "The period YYYY-MM is locked." (AC 7). The closed-year check runs before both (AC 8).
4. **SHOULD: Main must confirm it received the money.** Until AG-052 (receipt acknowledgement) is built, a branch can record a return that never reached Main's bank. That would cut its book cash to match a short till. Add to the release note: Main reconciles its bank to the Transfers report (type "Fund return") at least weekly. Raise AG-052 in priority after this item.
5. **SHOULD: add consolidation to AC 13.** After AC 1, the consolidated trial balance shows GAL's HO current account and MAIN's BCA(GAL) eliminating to 0, `balanced = true`, and Branch Comparison totals still reconcile (opening + in − out = closing per branch; sum of branches = consolidated).
6. **SHOULD: do not hard-code account codes in tests.** The inter-branch codes are `15` + the 3-digit branch id, so 15001/15003 in R2 hold only for MAIN id 1 and GAL id 3. Assert by account role or branch, not by literal code.
7. **Fine as specified:** audit (R7; refusals write nothing), isolation (R8, AC 3), funds check under the row lock (R3), Super Admin "All branches" initiation (Q2), and no separate screen (Q3).

**Verdict:** approved for development, subject to MUST 1–3.
