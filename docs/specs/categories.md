# Spec: Categories and sub-categories

## 1. Goal and scope
- **AG-070** (E8, Must, R1): "As a Super Admin, I want to manage income and expense categories with sub-categories so that reports are meaningful." Backlog: two levels (Category > Sub-category); each has a type, Income or Expense; name unique within parent; seed common defaults.
- **AG-071** (E8, Should, R2): "As a Branch Admin, I want to add branch-only sub-categories so that local needs are covered without cluttering other branches." Backlog: a sub-category may be global or scoped to one branch; branch-scoped items are visible only in that branch; Super Admin can promote a branch sub-category to global.
- **AG-072** (E8, Must, R1): "As an admin, I want to deactivate (not delete) used categories so that history stays intact." Backlog: categories with transactions cannot be deleted; inactive categories are hidden in entry forms and still shown in reports.

**Today (code on `feature/categories`).**
- `GET /v1/categories` (any signed-in branch user), `POST /v1/categories` and `PUT /v1/categories/{id}` (`perm:category.manage`) in `CategoryController`. There is no DELETE route.
- `index` returns active top-level categories with their `children`: global ones plus the active branch's own. `type` and `include_inactive` filters exist. In All mode only global rows are returned, so Super Admin cannot see any branch-only sub-category.
- `store`: top level is Super Admin only. A sub-category takes its parent's type. A Super Admin's sub-category is global; anyone else's is branch-only (`branch_id` = active branch). The parent must be top level. Duplicate check is case-sensitive on SQLite, ignores type for top-level rows, and does not compare a new global sub with other branches' branch-only subs. The parent's `is_active` is not checked.
- `update`: `name` and `is_active` only (`type` and `parent_id` are silently ignored). Non-Super Admins may change only their branch's own rows. **No duplicate-name check on rename.**
- **No audit row** is written for a category create or update (only the system `account.created` / `account.updated` rows of its ledger accounts).
- Double entry (`docs/specs/double-entry.md` R1, `ChartOfAccounts`): every node has a 5-digit `ledger_code` (4PPSS income, 5PPSS expense; parent = PP00). The code is assigned on create, is the same in every branch, is never reused or edited, and has a 99-per-level limit (one SS sequence per parent, shared by global and all branch-only subs). A global node has an account in every branch; a branch-only sub has one in its own branch. Rename renames the accounts (a parent rename also renames its subs' accounts); (de)activate sets the accounts `inactive`/`active`.
- Posting (`LedgerService::incomeOrExpense`, `reclassify`) needs an active category of the right type, global or of the entry's branch. It does **not** check the parent's status.
- `CategorySeeder` seeds 3 income and 10 expense parents with sub-categories (Sales, Service income, Other income; Salaries & wages, Rent, Utilities, Transport, ...). AG-070's "seed defaults" is done.
- Web: the entry form (`features/transactions/entry-form.component.ts`) loads `GET /categories?type=` and requires a sub-category. The I&E report already shows inactive categories with an "Inactive" tag. **There is no category management screen.**

**Out of scope:** hard delete of any category (none, even unused, per CLAUDE.md); moving a sub-category to another parent; changing type; merging two categories; bulk reclassification; demoting a global sub-category to branch-only; a third level; editable ledger codes; hiding a global category for one branch only.

## 2. Personas and permissions
No new permission key. `category.manage` is held by the **Branch Admin** (`*`) and **Accountant** templates (users-and-roles finding 6). Cashier and Viewer do not have it.

| Action | Who |
|---|---|
| Read the tree for entry forms (`GET /categories`, no params) | every signed-in user in branch mode |
| Open the Categories screen (`GET /categories?manage=1`) | `category.manage` (Super Admin always) |
| Add / rename / deactivate / reactivate a **top-level category** | Super Admin only |
| Add / rename / deactivate / reactivate a **global sub-category** | Super Admin only |
| Add / rename / deactivate / reactivate a **branch-only sub-category** | `category.manage` in that branch (Branch Admin, Accountant, custom roles with the key); Super Admin in any mode |
| Promote a branch-only sub-category to global | Super Admin only |

A user with `category.manage` in KDY only can never see or change GAL's branch-only sub-categories.

## 3. User flows
**F1 Super Admin, All branches mode.** Categories → tabs Income / Expense → tree of all parents, global subs and every branch's branch-only subs (with a branch badge) → "Add category" (top level) or "Add sub-category" on a parent → name → Save. The new node appears in every branch's entry form and on every branch's chart of accounts.

**F2 Super Admin, branch mode (KDY).** Same screen, but only global rows and KDY's branch-only subs are listed. New sub-categories are global (Q3).

**F3 Branch Admin / Accountant in KDY.** Categories → Expense → on "Transport", "Add sub-category" → "Tuk-tuk hire" → Save. It is created branch-only for KDY with badge "KDY only". Global rows show no Edit/Deactivate actions for this user (read-only, with a lock hint "Managed by Super Admin").

**F4 Deactivate.** On a row, "Deactivate" → confirm dialog shows the usage count and, for a parent, the number of active sub-categories that will be hidden with it → Confirm. The row shows "Inactive" (greyed) and is hidden from entry forms. "Show inactive" toggle (default on in this screen) lets the user hide them. "Reactivate" reverses it.

**F5 Rename.** "Edit" → change name → Save. Every list, report and the chart show the new name from now on, also for past entries. The old name is kept in the audit log.

**F6 Promote (Super Admin).** On a branch-only row, "Make global" → dialog: "'Tuk-tuk hire' (KDY only, code 50403) will be available in every branch with the same code. Its past entries stay in KDY." → Confirm. The badge disappears; other branches get the account with a zero balance.

**F7 Wrong parent or type.** There is no Move and no type field on Edit. The user creates the correct node, deactivates the wrong one, and changes the category of each affected entry (reclassify, `PUT /transactions/{id}`), which posts reclass lines on the original dates.

## 4. Screens
### S1 Categories — route `/categories`
- Guard: `permissionGuard('category.manage')`. Nav link "Categories" under "Chart of accounts" (`class="subnav"`), shown when `auth.can('category.manage')`. Works in All mode and branch mode.
- Header: "Categories", tabs **Income** / **Expense** (remembered in the URL `?type=`), checkbox "Show inactive" (default checked), button "Add category" (Super Admin only).
- **Tree** (one card per parent, sorted by name; subs sorted by name inside):

| Column | Content |
|---|---|
| Name | parent bold; sub indented. Badges: `KDY only` (branch-only; in branch mode just "This branch only"), `Inactive`, `Hidden: parent inactive` (sub active, parent inactive) |
| Code | `ledger_code` (mono), e.g. 50400, 50403 |
| Used in | usage count: number of income and expense entries (any status) whose current category is this node, in the active branch (branch mode) or all branches (All mode). For a parent: its own entries + its subs'. "0" shown as "Not used" |
| Actions | Add sub-category (parent rows, if the parent is active), Edit, Deactivate / Reactivate, Make global (branch-only rows, Super Admin only). Actions the user may not perform are not shown |

- Empty state per tab: "No income categories yet." plus "Add category" for Super Admin, or "Ask a Super Admin to add categories." otherwise.
- Error state: the API message in the page alert (`errorText`), list unchanged.
- **Dialogs** (one form component, modal; full-width sheet at ≤ 760px):
  - *Add category*: Name (text, required, trimmed, 1–100 chars). Type is the current tab, shown read-only. Save / Cancel.
  - *Add sub-category*: Parent shown read-only, Name (as above). Scope shown read-only: "All branches" (Super Admin) or "This branch only (KDY)" (others).
  - *Edit*: Name only. Info text: "Type, parent and code cannot be changed. To move entries, create the right category and change the category on each entry."
  - *Deactivate*: "Deactivate 'Utilities'? It will be hidden from entry forms. Past entries and reports keep it. 4 active sub-categories will also be hidden. Used in 132 entries." Confirm / Cancel.
  - *Make global*: text from F6. Confirm / Cancel.
  - Buttons are disabled while saving (no double submit). On success: toast/`ok` line "Saved.", tree reloads.
- **390px layout:** no horizontal scroll. Each row becomes two lines: name + badges on line 1; code · "Used in N" on line 2; actions in a single "⋯" menu button (min 44×44 px tap target). Tabs and the "Show inactive" toggle wrap onto one line under the title.

### S2 Entry form (existing) — `/transactions/new/:kind`
- Category select: only active parents of the kind that have at least one active sub-category visible in this branch. Sub-category select: only active subs, global or of the active branch. (API already filters; the parent-with-no-subs rule is new and client-side.)

### S3 Reports, transaction list and detail (existing)
- Unchanged: they show every category used, active or not, with the current name; I&E keeps the "Inactive" tag. Transaction list and detail show the current name.

### S4 Chart of accounts (existing)
- A sub-category's accounts show `Inactive` when the sub or its parent is inactive (R6).

## 5. API
| Method, path | Guard | Change |
|---|---|---|
| `GET /v1/categories` | auth, branch | Unchanged for entry forms (active only, global + active branch). **Plus** `manage=1` (requires `category.manage`, else 403): includes inactive rows, adds per node `ledger_code`, `branch:{id,code}\|null`, `is_active`, `usage_count`, `can_edit` (bool), `can_promote` (bool). In All mode with `manage=1`, branch-only subs of every branch are included. Children of an inactive parent are returned under it. |
| `POST /v1/categories` | `perm:category.manage` | Adds rules R2, R3, R4, R7 and audit R8. Response 201 = the node in the `manage=1` shape. |
| `PUT /v1/categories/{id}` | `perm:category.manage` | `name`, `is_active` only. `type` or `parent_id` or `branch_id` present → 422 (R4/R5). Rename runs the duplicate check (R3). Access: R1. Audit R8. |
| `POST /v1/categories/{id}/promote` | `super` | **New.** R9. 200 = the node. Errors: 422 "Only a branch-only sub-category can be made global.", 422 "Reactivate it before making it global." (sub inactive), 422 "Reactivate it and its category before making it global." (parent inactive), 422 duplicate message (R9). |
| `DELETE /v1/categories/{id}` | — | Not added. Route stays absent (405). |

Error texts:
- 403 "Only Super Admin can change global categories."
- 422 "A category with this name already exists here." / "A sub-category with this name already exists in <branch codes>. Rename it first."
- 422 "The type of a category cannot be changed." / "A sub-category cannot be moved to another category. Create it under the new category and change the category on each entry."
- 422 "This category is inactive. Reactivate it before adding sub-categories."
- 422 "No more category codes are available under <parent>. Ask the accountant." (existing).

## 6. Business rules
- **R1 Access.** Top-level rows and global subs: Super Admin only (403 otherwise). A branch-only sub: Super Admin, or a user with `category.manage` whose active branch is that sub's branch (else 404, so other branches' rows are not revealed). Super Admin in All mode may edit any row.
- **R2 Two levels.** `parent_id` must be an active top-level node of the same type (a sub's type is always its parent's). A sub of a sub → 422 (existing).
- **R3 Unique name within parent.** Compare trimmed and case-insensitive, inactive rows included (so a reactivation can never create a duplicate). Top level: unique per type (an income "Rent" and an expense "Rent" may both exist). Sub-category: a name clashes with any global sibling; a branch-only sub also clashes with its own branch's siblings; a new **global** sub also clashes with every branch's branch-only siblings (else that branch would see two). The same branch-only name may exist in two different branches. Applies to create, rename and promote. Every name check runs under the chart lock (`ChartOfAccounts::lockChart`, the lock the code sequence uses), inside the same DB transaction as the write, so two concurrent requests (e.g. a GAL create and a promote of the same name) cannot both pass (review finding 6).
- **R4 Type is fixed from creation.** The type picks the 4/5 code prefix and the account class in every branch when the node is created, so it is never editable (stricter than "after first use", Q2).
- **R5 No move.** `parent_id` and `branch_id` are not editable. Moving would change the code (PP) and split history. Use reclassify per entry (F7).
- **R6 Deactivate / reactivate.** Sets `is_active` only on that node; children keep their own flag. A sub is **effectively inactive** when it or its parent is inactive. Effectively inactive nodes: hidden in entry forms and in `GET /categories` without `manage=1`; choosing them for new money is refused (**new**: `LedgerService::incomeOrExpense`, the reclassify **target** and the controls R5 rule must also check the parent's `is_active`); their ledger accounts are `inactive`. Reactivating a parent brings back exactly those subs whose own flag is active. Deactivating a node that is used or unused is allowed; nothing is deleted.
  - **Account status = effective status everywhere** (review finding 2). A category account is `active` only when the sub and its parent are active, else `inactive`; `closed` stays `closed`. This applies wherever a category account's status is set: `syncCategoryAccounts` (a parent's status change also re-syncs its subs' accounts) and `ChartOfAccounts::categoryAccount()`, which also creates accounts for promote (R9), a new branch (`setupBranch`) and the posting safety net.
  - **The parent check is only for choosing a category for new money** (review finding 4). It must **not** be added to void, reverse, the reclassify **source**, the year-end close / reopen or the back-fill: these post to the existing (possibly inactive) category account by design and do not check category or account status. Inactive accounts still take void, reverse and year-end closing lines, so their balances close normally at year end.
- **R7 Branch scope on create.** Super Admin creates global rows. Any other user creates a branch-only sub for the active branch. Branch-only subs share their parent's SS sequence (99 per parent across all branches).
- **R8 Audit** (review finding 1). One row per create, rename, (de)activate and promote, in the same DB transaction as the change, with old and new values; nothing is ever deleted (AC 24).
  - `category.created`: new `{name, type, parent_id, branch_id, ledger_code}`.
  - `category.updated`: old/new of the changed fields among `name`, `is_active`. For a (de)activation of a parent, new also has `affected_sub_ids`: the ids of the subs whose effective status changed with it.
  - `category.promoted`: old `{branch_id: <origin>}`, new `{old_branch_id, new_branch_id: null, ledger_code, name, created_account_ids}`. Written with `branch_id` = the **origin** branch (not null), so the origin branch's Branch Admin / Accountant see in their audit view why their sub is now managed by the Super Admin.
  - Audit `branch_id` for created/updated = the category's branch (global rows → null, Super Admin only, per controls R9). The existing system `account.created` / `account.updated` / `ledger.chart_created` rows continue.
- **R9 Promote to global** (Q1, agreed by the accountant; review findings 3 and 5). Only an **effectively active** branch-only sub: the sub inactive → 422 "Reactivate it before making it global."; its parent inactive → 422 "Reactivate it and its category before making it global.". In one DB transaction, under the chart lock (R3): re-check R3 against global siblings **and every other branch's** branch-only siblings, **inactive ones included** (refuse and list the branch codes; no merge); set `branch_id = null`; keep `ledger_code` unchanged (codes are already unique across branches, so the consolidated trial balance keeps one line with the same figures); create the account in every other branch (active and inactive branches, zero balance, effective status per R6) via `createCategoryAccounts`. Post nothing; leave the origin account, its lines, its year-end closing lines and every `category_id` unchanged. Period locks and year-end closes are therefore not affected. Not reversible in this release (a mistaken promote is handled by deactivating the node in all branches).
- **R10 History shows the current name.** Entries reference `category_id`, so lists, reports, exports and the chart show the new name for past periods too. The old name is only in the audit log.
- **R11 Existing entries in an inactive category** stay there. Editing such an entry's other fields is allowed; changing its category needs an active target (existing reclassify rule).
- **R12 Branch isolation.** A branch-only sub never appears in another branch's form, tree, usage count or chart. Usage count in branch mode counts only that branch's entries, even for global nodes.

## 7. Acceptance criteria
Fixtures: branches MAIN, KDY, GAL; seeded categories; users: SA (Super Admin), KBA (Branch Admin, KDY), KACC (Accountant, KDY), KCASH (Cashier, KDY), GBA (Branch Admin, GAL).

**Create and uniqueness**
1. Given SA in All mode, When POST `{name:"Insurance", type:"expense"}`, Then 201, a top-level expense node with the next 5PP00 code, an account in every branch, and one `category.created` audit row with `branch_id` null.
2. Given KBA in KDY, When POST a top-level category, Then 403 and nothing is created.
3. Given KBA in KDY, When POST `{name:"Tuk-tuk hire", parent_id: Transport}`, Then 201 with `branch_id` = KDY, type expense, code 504SS, an account in KDY only, and `category.created` with `branch_id` = KDY.
4. Given KCASH in KDY, When POST any category, Then 403.
5. Given Utilities has "Water", When POST "water " under Utilities (any user allowed to), Then 422 "A category with this name already exists here."
6. Given expense top-level "Rent", When SA POSTs income top-level "Rent", Then 201.
7. Given KDY has branch-only "Tuk-tuk hire" under Transport, When GBA POSTs "Tuk-tuk hire" under Transport in GAL, Then 201 with a different code.
8. Given AC 7, When SA POSTs global "Tuk-tuk hire" under Transport, Then 422 "A sub-category with this name already exists in GAL, KDY. Rename it first."
9. Given Utilities is inactive, When POST a sub-category under it, Then 422 "This category is inactive. Reactivate it before adding sub-categories."
10. Given a sub-category as `parent_id`, When POST, Then 422 "Only two levels are allowed (Category > Sub-category)."

**Edit, type, move**
11. Given SA, When PUT Electricity `{name:"Power"}`, Then 200, every branch's account is named "Utilities > Power", one `category.updated` row has old name "Electricity" and new "Power", and an I&E report for last month lists "Power".
12. Given Utilities has "Water", When PUT Electricity `{name:"WATER"}`, Then 422 duplicate and the name is unchanged.
13. Given any category, When PUT with `type` set (even the same value), Then 422 "The type of a category cannot be changed." and nothing changes.
14. Given any sub-category, When PUT with `parent_id` or `branch_id`, Then 422 with the move message and nothing changes.
15. Given KBA in KDY, When PUT a global category or sub-category, Then 403 "Only Super Admin can change global categories."
16. Given KBA in KDY, When PUT GAL's branch-only sub, Then 404.
17. Given KACC in KDY, When PUT KDY's branch-only sub `{name:"Three-wheeler hire"}`, Then 200 and `category.updated` with `branch_id` KDY.

**Deactivate / reactivate**
18. Given Electricity used by 5 entries, When PUT `{is_active:false}`, Then 200, `category.updated` old true / new false, its accounts in every branch are `inactive`, and no row or transaction is deleted.
19. Given AC 18, When KCASH opens Record expense and picks Utilities, Then Electricity is not in the sub-category list; When POST /v1/expenses with Electricity, Then 422 "Category must be an active expense category."
20. Given AC 18, When the I&E report covers those 5 entries, Then Electricity is listed with its amount and an "Inactive" tag; the transaction list still shows "Utilities › Electricity".
21. Given Utilities (active subs Electricity, Water; Internet already inactive), When SA deactivates Utilities, Then Utilities is not in the entry form, posting or reclassifying to Water returns 422, and the accounts of Utilities, Electricity and Water are `inactive` in every branch; the `is_active` flags of Electricity, Water and Internet are unchanged.
22. Given AC 21, When SA reactivates Utilities, Then Utilities, Electricity and Water are back in entry forms with active accounts, and Internet stays inactive.
23. Given an inactive category, When an entry in it is edited (description only), Then 200; When its category is changed to another inactive category, Then 422 "Choose an active expense category of this branch."
24. Given any category, When DELETE `/v1/categories/{id}`, Then 405 and the row remains.

**Visibility and branch isolation**
25. Given KDY's "Tuk-tuk hire", When a GAL user calls GET /categories (or opens the entry form), Then it is not returned; a KDY user sees it.
26. Given SA in All mode, When GET /categories?manage=1&type=expense, Then the result includes KDY's and GAL's branch-only subs, each with `branch:{id,code}`, plus inactive nodes, with `ledger_code` and `usage_count`.
27. Given KBA in KDY, When GET /categories?manage=1, Then only global nodes and KDY's branch-only subs are returned, and `usage_count` counts KDY entries only.
28. Given KCASH, When GET /categories?manage=1, Then 403.
29. Given a global sub with 3 KDY and 2 GAL entries (one voided), Then `usage_count` is 3 in KDY mode and 5 in All mode; its parent's count includes them.

**Promote (AG-071)**
30. Given KDY's active "Tuk-tuk hire" (code 50403) with 4 KDY entries, When SA POSTs promote, Then 200, `branch_id` null, code still 50403, MAIN and GAL (and any inactive branch) get "Transport > Tuk-tuk hire" with code 50403 and zero balance, the KDY account and its lines are unchanged, one `category.promoted` row is written, and the sub now appears in GAL's entry form.
31. Given AC 30, Then the consolidated trial balance shows one 50403 line, and KDY's I&E figures for past periods are unchanged.
32. Given GAL also has a branch-only "Tuk-tuk hire", When SA promotes KDY's, Then 422 listing GAL and nothing changes.
33. Given an inactive branch-only sub, When promote, Then 422 "Reactivate it before making it global."; given a global sub, Then 422 "Only a branch-only sub-category can be made global."
34. Given KBA, When POST promote, Then 403.
35. Given a promote in a month that is locked, Then it succeeds and no ledger line is posted.

**Screen (S1)**
36. Given KCASH, Then the "Categories" nav link is hidden and `/categories` redirects (guard).
37. Given KBA in KDY on Expense, Then global rows show no Edit/Deactivate actions, KDY's subs show Edit, Deactivate, and "This branch only"; "Add category" and "Make global" are not shown.
38. Given SA in All mode, Then branch-only rows show the badge "<code> only", and "Make global" appears only on them.
39. Given an inactive parent, Then its active subs show "Hidden: parent inactive"; with "Show inactive" unchecked, the parent and its subs are not listed.
40. Given the Deactivate dialog for Utilities (4 active subs, 132 entries), Then the text states both numbers; Cancel changes nothing.
41. Given a 390px-wide viewport, Then the page has no horizontal scroll, each row shows name/badges and code/usage on two lines, actions are in a "⋯" menu, and dialogs fill the width.
42. Given a double click on Save in any dialog, Then exactly one request is sent.
43. Given a 100th sub-category under one parent, When Save, Then the dialog shows "No more category codes are available under <parent>. Ask the accountant." and nothing is created.

**Entry form**
44. Given a parent whose subs are all inactive (or all other branches' only), Then that parent is not in the entry form's Category list.

**Accounting review (findings 1-6)**
45. (Finding 1) Given each of create, rename, deactivate, reactivate and promote, Then exactly one `category.*` audit row is written in the same DB transaction with old and new values. Given SA deactivates Utilities (active subs Electricity, Water; Internet inactive), Then `category.updated` new has `affected_sub_ids` = [Electricity, Water]. Given AC 30, Then `category.promoted` has `branch_id` = KDY and new `{old_branch_id: KDY, new_branch_id: null, ledger_code: "50403", name, created_account_ids}`, and KBA sees it in KDY's audit log.
46. (Finding 2) Given Utilities inactive, When SA creates branch MTR, Then MTR's Utilities, Electricity and Water accounts are `inactive`. Given a branch-only sub under an inactive parent, Then its account is `inactive`; a `closed` account stays `closed` on any sync.
47. (Finding 3) Given KDY's active branch-only sub under an inactive parent, When SA promotes it, Then 422 "Reactivate it and its category before making it global." and nothing changes.
48. (Finding 4) Given an entry whose category (or its parent) is inactive: (a) void → 200 and (b) reverse (where reversible) → 200, the contra/mirror lines are on the inactive account and the I&E nets to 0.00 for it; (c) reclassify **from** the inactive category to an active one → 200; (d) year-end close of a year with balances on an inactive category account → closing lines are posted on it and it nets to 0.00 at the year end. Posting a new entry or reclassifying **to** a sub whose parent is inactive → 422.
49. (Finding 5) Given AC 30, Then `ledger:verify` V1–V8 pass after the promote; the consolidated trial balance for any period (including a closed year, pre- and post-closing) has the same totals and the same 50403 figure before and after; the I&E for All branches for past periods is unchanged. Given GAL has an **inactive** branch-only "Tuk-tuk hire", When SA promotes KDY's, Then 422 listing GAL.
50. (Finding 6) Every name check (create, rename, promote) takes the chart lock inside the DB transaction of the write.

## 8. Open questions (with assumptions)
**Decisions (accounting review 2026-11-18), folded into the body above:**
- Q1 agreed: promote keeps the code, adds zero-balance accounts in every other branch (effective status), keeps history in the origin branch, refuses (never merges) on a name clash incl. inactive siblings and lists the branches, and is not reversible (a mistaken promote is deactivated). A branch with a clashing sub renames it, deactivates it and reclassifies its open-period entries after the promote.
- Q2 agreed: type is fixed at creation (backlog wording "fixed after first use" → "fixed at creation"). Fix = create, deactivate, reclassify (F7).
- Q3 agreed: a Super Admin's sub is global. Q4 agreed: no delete, even unused. Q5 agreed: out of scope.
- Q6 confirmed: the Accountant keeps `category.manage`; renames are controlled by the audit log (R8) and the rename warning.
- Q7 agreed: usage count = current `category_id`, any status, reversal rows excluded; it is an operational hint, the ledger balance is the figure.
- Q8 agreed: ships with AG-070/072 in R1.
- Deactivating a parent (R6) agreed; inactive accounts still take void, reverse and year-end closing lines. Renames shown retroactively (R10) acceptable, also for locked months and closed years.

- **Q1 Promote and double entry (accountant).** Assumed: the code is kept, accounts are added in other branches with zero balance, history stays in the origin branch, no merge with same-name branch subs (refuse instead), not reversible. Is a consolidated line that has history in one branch only acceptable? Should same-name subs in other branches be merged (reclassify their entries) instead of refused?
- **Q2 Type change before first use.** Backlog/brief say "fixed after first use". Assumed: fixed from creation, because the code and the accounts in every branch are created with the type. Fix = deactivate and re-create.
- **Q3 Super Admin creating a branch-only sub.** Assumed: not offered; Super Admin rows are global (as today).
- **Q4 Delete of unused categories.** AG-072 implies unused ones could be deleted. Assumed: no delete at all (CLAUDE.md "nothing is hard-deleted"); deactivate instead.
- **Q5 Hiding a global category in one branch.** Assumed out of scope; only Super Admin deactivates global rows, for every branch.
- **Q6 Accountant.** The Accountant template holds `category.manage`, so Accountants get the same rights as a Branch Admin here. Assumed intended (users-and-roles finding 6).
- **Q7 Usage count basis.** Assumed: entries whose current `category_id` is the node (income and expense, any status; reversal rows not counted). Entries reclassified away are not counted, though the ledger account keeps their lines.
- **Q8 AG-071 is R2.** It is already built in the API; this spec adds the R2 promote. Assumed it ships with AG-070/072 in R1.

## Accounting review (accountant, 2026-11-18, before development)

Reviewed against `CategoryController`, `ChartOfAccounts` (codes, `categoryAccount`, `syncCategoryAccounts`), `LedgerService` (income/expense, reclassify, void, reverse, year-end close), `ReportService` (I&E, trial balance by branch and consolidated), `LedgerChecks` V5 and `YearEnd`. The design is sound: categories change the chart, never the ledger. No category action posts a line, so cash balances, period locks and closed years are untouched, and every figure stays keyed to `category_id` (I&E, V5) or `ledger_code` (consolidated trial balance), neither of which ever changes.

### Findings
1. **MUST — Audit (R8) as written, in the same DB transaction as the change.** Today no `category.*` row is written. Also: `category.updated` for a deactivation/reactivation of a parent lists the ids of the subs whose effective status changed; `category.promoted` holds `{old_branch_id, new_branch_id:null, ledger_code, name, created_account_ids}` and is written with `branch_id` = the **origin** branch (not null), so the origin branch's Branch Admin/Accountant can see in their audit view why their sub is now Super Admin-managed. AC: one audit row per create/rename/(de)activate/promote, with old and new values; nothing is ever deleted (AC 24 stays).
2. **MUST — Effective status everywhere an account status is set, not only in `syncCategoryAccounts`.** `ChartOfAccounts::categoryAccount()` creates accounts with `$c->is_active` only; it is also used by promote, by `setupBranch` (new branch) and by the posting safety net. It must use "sub active AND parent active" (R6), `closed` stays `closed`. AC: deactivate Utilities, create branch MTR (or promote a sub under Utilities, see 3) → the new Utilities/Electricity/Water accounts in MTR are `inactive`.
3. **MUST — Promote refuses an effectively inactive sub.** R9/AC 33 check only the sub's own flag. Refuse also when the parent is inactive: 422 "Reactivate it and its category before making it global."
4. **MUST — The new parent check (R6) applies only to choosing a category for new money.** It goes into `incomeOrExpense` and the reclassify **target** (and controls R5). It must **not** be added to void, reverse, the reclassify **source**, the year-end close / reopen or the back-fill: these post to the existing (possibly inactive) category account by design. Today none of them checks category or account status; keep it so. ACs: (a) void and (b) reverse an entry whose category (or its parent) is inactive → 200, contra/mirror lines on the inactive account, I&E nets to 0.00 for it; (c) reclassify an entry **from** an inactive category to an active one → 200; (d) year-end close of a year with balances on an inactive category account → closing lines posted on it and the account nets to 0.00 at E.
5. **MUST — Promote (R9) as written, with these rules made explicit.** Keep `ledger_code`; create the other branches' accounts with zero balance and effective status; post nothing; leave the origin account, its lines, its year-end closing lines and every `category_id` unchanged; refuse on any name clash (global sibling or any other branch's branch-only sibling, **inactive ones included**) and name the branches. Add to AC 30/31: `ledger:verify` V1–V6 pass after the promote; the consolidated trial balance for any period (including a closed year, pre- and post-closing) has the same totals and the same 50403 figure before and after; the I&E for All branches for past periods is unchanged.
6. **MUST — Run every name check (create, rename, promote) under the chart lock** (`ChartOfAccounts::lockChart`, the lock the code sequence already uses), inside the same DB transaction as the write. Otherwise a GAL create and a promote of the same name can both pass and GAL shows two "Tuk-tuk hire" lines.
7. **SHOULD — Rename guidance for history.** Rename is a re-label, not a change of meaning. The Edit dialog of a used node shows: "Used in N entries. Reports for all periods, including locked months and closed years, will show the new name. Rename only to correct or clarify the name. If the category now means something different, create a new category and deactivate this one." Add the line "up to <latest locked month>" / "including closed FY <label>" when that applies.
8. **SHOULD — Show the code and the branch of branch-only subs in reports.** The I&E report (screen and exports) shows the `ledger_code` beside each category, and a "<branch code> only" tag on branch-only subs when more than one branch is selected. Two branches' "Tuk-tuk hire" are different codes and are listed separately (correct, double-entry Q4), and without the tag they look like a duplicate. The code also lets an old printout be reconciled after a rename.
9. **SHOULD — Deactivate dialog: state the current-year balance as well as the usage count** for a node with a non-zero balance in the open fiscal year ("Rs X this year. Its balance is still closed at year end."), so nobody deactivates to "hide" a figure.
10. **SHOULD — Periodic review of branch-only subs.** On the S1 screen in All mode, a filter "Branch-only" so the Super Admin can review new local subs each month and promote or ask the branch to reclassify those that duplicate a global one. Branch-only subs that duplicate global ones fragment the consolidated I&E.
11. **Fine as written:** two levels; R3 uniqueness (case-insensitive, inactive rows included, type-separate top level); no move (R5) and correction by per-entry reclassify on the original date (F7, double-entry Q7); usage count (R12, Q7); access table and 404 for other branches' rows (R1); S2 entry-form filter; trial balance and I&E with inactive categories (both read all accounts / all `category_id`s regardless of status, and tag inactive ones; AC 20 is enough); V5 is unaffected by rename, deactivate and promote because it is keyed by (branch, `category_id`).

### Answers to the open questions
- **Q1 Promote — agreed, refuse rather than merge.**
  - *Code kept:* yes. The code is the consolidation key and is already unique across branches (one SS sequence per parent), so the consolidated trial balance keeps one 50403 line with the same figures before and after.
  - *Zero-balance accounts in other branches:* yes, created at promote (all branches, active and inactive, status per finding 2). This is the same as a new global category or a new branch (double-entry R10). Zero rows are not shown in the trial balance.
  - *History stays in the origin branch:* yes, and it must. The expense was incurred by KDY; moving or spreading it would rewrite locked and closed periods. A consolidated line with history in one branch only is normal (a global category first used by one branch looks the same).
  - *Name clash:* refuse and list the branches (finding 5). A merge is a reclassification of another branch's entries, possibly into locked months or closed years, so it is not a chart action. The branch renames its own sub (e.g. "Tuk-tuk hire (old)"), deactivates it, and reclassifies its open-period entries to the global one after the promote; entries in locked months stay where they are.
  - *Not reversible:* agreed for this release. Demotion could strand other branches' postings. If a promote was a mistake, the Super Admin deactivates the node (all branches).
- **Q2 Type fixed from creation — agreed.** Type sets the class (income/expense), the 4/5 code and the account in every branch at creation; changing it later would move an existing balance across the income statement without a posting. Fix = create the right one, deactivate, reclassify (F7). Update the backlog wording "fixed after first use" to "fixed at creation".
- **Q3 — Agreed.** A Super Admin's sub is global. Branch-only subs come from the branch.
- **Q4 — Agreed: no delete, even unused.** An unused node's accounts have zero balance and are hidden from the trial balance once inactive; keeping the row keeps the code from being reused.
- **Q5 — Agreed, out of scope.** A branch that does not need a global sub simply does not use it.
- **Q6 Accountant — yes, confirmed** (users-and-roles finding 6): classification is accounting work. The risk that an Accountant re-labels a branch-only sub to disguise past entries is controlled by findings 1 and 7: every rename is in the audit log with old and new names, the Branch Admin and Super Admin can read it, and global categories (the bulk of the chart) are Super Admin only. No change to the templates.
- **Q7 Usage count — agreed.** Current `category_id`, any status, reversal rows excluded. It is an operational hint, not a figure; the ledger balance is the figure (finding 9).
- **Q8 — Agreed**, ship with AG-070/072 in R1.
- **Deactivating a parent (R6):** agreed. Children keep their own flag; their accounts become `inactive` while the parent is inactive and come back only if their own flag is active. Inactive accounts still take void, reverse and year-end closing lines (finding 4), so their balances close normally at year end.
- **Renames shown retroactively (R10):** acceptable, also for locked months and closed years. A rename changes no amount, no `category_id` and no code, so every locked or closed figure, the year-end close and its reconciliation are unchanged; only the label changes. Conditions: findings 1 (old name in the audit log), 7 (warning) and 8 (code shown, so a signed printout can be matched to today's report by code).
