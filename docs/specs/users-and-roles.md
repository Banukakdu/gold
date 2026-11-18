# Spec: Users and roles management screens

## 1. Goal and scope
Give Super Admins and Branch Admins screens to manage users, their branch memberships and per-branch roles, and to build custom roles from the fixed permission catalogue.
Covers **AG-020** (create users, several branches, role per branch, invitation), **AG-021** (Branch Admin manages own branch, no escalation), **AG-022** (custom roles, checklist, unique name, deactivate not delete), **AG-023** (role templates, copy into a branch), **AG-024** (deactivate user at once).

Fixed decisions: single company; one Main branch; a user can belong to several branches with **one role per branch**; roles are per branch; global templates have `branch_id NULL`; nobody grants a permission they do not hold; nothing is hard-deleted; Super Admin is a global flag (`users.is_super_admin`).

Out of scope: granting/removing the Super Admin flag in the UI; changing the permission catalogue; self-service profile/password change; SSO/2FA; audit log screen.

## 2. Personas and permissions
Permission keys used (from `PermissionSeeder::PERMISSIONS`): `user.view`, `user.create`, `user.edit`, `role.view`, `role.manage`.

| Persona | How | Has |
|---|---|---|
| Super Admin | `is_super_admin = true` | Everything, every branch, plus "All branches" mode and templates |
| Branch Admin | template "Branch Admin" (`*` = all catalogue keys) copied into a branch | All five keys above, in that branch only |
| Accountant / Cashier / Viewer | templates | None of the five keys; menu items hidden |
| Custom role | built per branch | Whatever subset was ticked |

**Branch administrator** (used by the last-admin rule) = an *active* user holding an *active* role in that branch that contains both `user.edit` and `role.manage`.

## 3. User flows
**F1 Super Admin creates a user (branch mode).** Selects branch KDY in the switcher -> Users -> "New user" -> name, email, phone, role (KDY roles only) -> Save. User is created, joined to KDY with that role, invitation email sent. Lands on user detail.
**F2 Super Admin adds an existing user to another branch.** All branches or branch mode -> Users -> open user -> Branch access -> "Add to branch" -> pick branch, then a role of that branch -> Save.
**F3 Super Admin changes or removes a branch membership.** User detail -> Branch access row -> "Change role" or "Remove from branch" -> confirm.
**F4 Branch Admin manages own branch.** Switcher shows only their branches. Users list shows only members of the active branch. Can create users there (F1 without branch choice), change their role, remove them from the branch, and deactivate users who belong only to branches where the admin has `user.edit`.
**F5 Build a custom role.** Roles -> "New role" -> name -> tick permissions grouped by module -> Save. Boxes for permissions the actor lacks are disabled with tooltip "You don't have this permission".
**F6 Copy templates into a branch.** Super Admin -> Branches -> row action "Copy role templates" (or Roles screen in branch mode, button shown when the branch has none of the template names). Creates branch copies of Branch Admin, Accountant, Cashier, Viewer; existing names are skipped.
**F7 Deactivate a role.** Roles -> open role -> "Deactivate" -> confirm dialog shows "N users hold this role; they lose its permissions until given another role".
**F8 Deactivate a user (leaver).** User detail -> "Deactivate" -> confirm. All tokens revoked; next request from that user gets 403 and the app returns to login.
**F9 Invitation.** Invitee opens emailed link `/set-password?token=..&email=..` -> sets password -> redirected to login.
**All branches mode (Super Admin only):** Users list shows every user with all memberships; "New user" is disabled with hint "Select a branch to add a user". Roles shows **templates only**, editable.

## 4. Screens
Menu (shell): "Users" if `auth.can('user.view')`; "Roles" if `auth.can('role.view')`. Both list pages refetch on branch switch (existing shell behaviour).

### 4.1 Users list `/users` (guard `user.view`)
- Filters: search (name or email, contains), status (All/Active/Inactive). Paged 50.
- Columns: Name, Email, Phone, Role (branch mode: role in active branch, "Role inactive" tag if its role is inactive) / Branches (All mode: `KDY · Cashier, MAIN · Viewer`), Status tag, Last login (date-time or "Invitation pending" if never logged in).
- Super Admin users show a "Super Admin" tag and no role.
- Actions: row click -> detail; "New user" button (needs `user.create`, branch mode).
- Empty: "No users in this branch yet." Error: API message in `.alert`.

### 4.2 New user `/users/new` (guard `user.create`, branch mode only, else redirect to `/users`)
| Field | Type | Req | Validation |
|---|---|---|---|
| Name | text | yes | 1-255 chars, trimmed |
| Email | email | yes | valid, unique across all users (case-insensitive) |
| Phone | text | no | max 30 |
| Role | select | yes | active roles **of the active branch** only (no templates); options with permissions the actor lacks are omitted |
Note under form: "An invitation email is sent so the user can set their password." Errors: 422 field errors under fields; 403 message in alert. If the email belongs to an existing user: Super Admin sees "This email already has an account - open it to add branch access" with link; Branch Admin sees "This email already has an account. Ask a Super Admin to add them to your branch."

### 4.3 User detail `/users/:id` (guard `user.view`)
- Profile card: name, email, phone (editable with `user.edit` when allowed by rule B5), status tag, last login, buttons "Deactivate"/"Activate" and "Resend invitation" (only if never logged in).
- Branch access table: Branch, Role, Role status, actions "Change role" (select of that branch's assignable roles), "Remove from branch". Rows are limited to branches the actor can see (Super Admin: all). "Add to branch" button: Super Admin only.
- 404 if the user is not visible in the actor's branch: "User not found."

### 4.4 Roles list `/roles` (guard `role.view`)
- Branch mode: section "Roles in <branch>" (columns: Name, Permissions count, Users, Status, actions) and section "Templates" (read-only for non-Super Admin; shows "Copy templates into this branch" for Super Admin).
- All mode (Super Admin): templates only, editable.
- Empty branch roles: "This branch has no roles yet." + copy button (Super Admin) or "Ask a Super Admin to copy the role templates." (others).

### 4.5 Role editor `/roles/new`, `/roles/:id` (guard `role.manage`; `role.view` users get read-only view)
- Name: text, required, 1-60 chars, trimmed, unique in its branch (case-insensitive); templates unique among templates.
- Permission checklist: one group per module (prefix before the dot: branch, user, role, account, transfer, transaction, income, expense, category, report, audit), action label = part after the dot (view, create, edit, manage, void, reverse, dashboard, export). "Select all in module" checkbox. Disabled boxes for permissions the actor lacks.
- Footer: Save; "Deactivate"/"Activate" for existing roles; usage line "Held by N users".
- Errors: 422 duplicate name under field; 403 escalation message in alert.

### 4.6 Set password `/set-password` (public)
Fields: password + confirm (min 8, at least one letter and one digit, same as current rule). Invalid/expired token (60 min): "This link has expired. Ask your administrator to resend the invitation."

## 5. API
Existing, used as is: `GET /permissions`, `GET /roles`, `POST /roles`, `PUT /roles/{role}`, `GET /users`, `POST /users`, `PUT /users/{user}`, `POST /users/{user}/role`, `GET /branches`, `GET /me`.

Changes to existing endpoints:
- **E1 `GET /users`**: add query `q`, `status`; each row returns `memberships: [{branch_id, branch_code, branch_name, role_id, role_name, role_status}]` (only branches visible to the actor) and `last_login_at`. Keep `roles_in_branch` in branch mode.
- **E2 `POST /users`**: `password` becomes optional. If absent, store a random unusable password and send the invitation (Laravel password broker, mail via configured mailer). Reject an existing email with 422 `email` error (current).
- **E3 `assignableRole` (store + assignRole)**: reject templates (`branch_id NULL`) with 422 "Pick a role of this branch."; reject inactive roles with 422 "This role is inactive."; keep the escalation check. **Also** for non-Super Admin: reject when the target user's *current* role in the branch has permissions the actor lacks (403 "You cannot change the role of a user with more permissions than you").
- **E4 `PUT /users/{user}`**: rules B5, B6, B8 (below). Revoke tokens on deactivate (current).
- **E5 `GET /roles`**: return `status`, `users_count` (active users holding it in that branch). Non-Super Admin in branch mode still sees templates (read-only).
- **E6 `PUT /roles/{role}`**: accept `status: active|inactive`; enforce name uniqueness on rename (422, today it would hit the DB unique index); non-Super Admin cannot edit a role that already holds permissions they lack (403); last-admin rule B7.
- **E7 `POST /roles`**: uniqueness check case-insensitive and trimmed.

New endpoints (all inside `auth:sanctum` + `branch` group unless stated):
| ID | Method + path | Guard | Request | Response | Errors |
|---|---|---|---|---|---|
| N1 | `GET /users/{user}` | `user.view` | - | user + `memberships[]` (as E1) | 404 if not a member of the active branch (non-Super Admin) |
| N2 | `POST /users/{user}/remove-from-branch` | `user.edit` | - (uses active branch) | `{ok:true}` | 400 no branch; 404 not a member; 403 escalation (E3 rule); 422 last admin; 422 self |
| N3 | `POST /roles/copy-templates` | `super` | - (uses active branch) | `{created:[names], skipped:[names]}` 201 | 400 in All-branches mode |
| N4 | `POST /users/{user}/resend-invite` | `user.edit` | - | `{ok:true}` | 422 if user has logged in before or is inactive |
| N5 | `POST /auth/set-password` (public, throttled) | none | `token, email, password, password_confirmation` | 204 | 422 invalid/expired token or weak password |

Schema: migration adds `roles.status` string(20) default `active`. Effective permissions must ignore inactive roles (e.g. custom `Role` model with an "active" global scope on the `roles` relation; admin endpoints read all roles explicitly). N2 detaches the `branch_user` row and removes the user's role rows for that branch (team); the user record and all transactions stay. N3 copies name + permissions into new branch roles, independent of the template afterwards.

Audit (`AuditLog::record`): `user.invited`, `user.invite_resent`, `user.removed_from_branch` (old role in `old`), `role.copied_from_templates`, `role.deactivated`, `role.activated`, plus existing `user.*`/`role.*` events.

## 6. Business rules and edge cases
- **B1 Branch isolation.** Non-Super Admin sees, opens and changes only users who are members of the active branch, and only that branch's membership. Memberships in other branches are not returned.
- **B2 No escalation.** Nobody grants (role edit or role assignment) a permission they do not hold in the active branch. Non-Super Admin cannot edit a role, or change/remove the role of a user, whose current permissions exceed theirs.
- **B3 One role per branch.** Assigning replaces the previous role in that branch (current `syncRoles`).
- **B4 Templates.** Only Super Admin creates/edits templates (All-branches mode). Templates are never assigned directly. Editing a template never changes branch copies, and vice versa.
- **B5 Shared users.** Name, email, phone and status are global. A non-Super Admin may change them only if every branch the user belongs to is one where the actor holds `user.edit`; otherwise 403 "This user also works in other branches; ask a Super Admin." The actor can still change the role or remove them from the active branch.
- **B6 Super Admin users** cannot be edited, given roles, or removed from branches by non-Super Admins (403). Super Admin is never created via these screens.
- **B7 Last branch administrator.** Any change that would leave a branch with zero branch administrators (section 2) is rejected with 422 "This is the last Branch Admin of <branch>. Give another user that role first." Applies to: role change, remove from branch, user deactivation, role deactivation, removing `user.edit` or `role.manage` from a role. It applies to Super Admin too. It does not block when the branch already had zero.
- **B8 Self.** A user cannot deactivate themselves, change their own role, or remove themselves from a branch (422 "You cannot change your own access.").
- **B9 Deactivated user.** Tokens revoked at once; login returns 403; their transactions, audit entries and memberships are kept; reactivation restores access with the same memberships.
- **B10 Deactivated role.** Not offered for assignment; holders keep the membership but lose its permissions until reactivated or given another role; list shows "Role inactive".
- **B11 Names.** Role names trimmed, compared case-insensitively within a branch ("cashier" = "Cashier"). Emails stored lower-case.
- **B12 Removing the last membership** of a non-Super Admin user is allowed; the user then has no branch and cannot use the app (message "You do not have access to any branch" on login) until added again.

## 7. Acceptance criteria
1. Given a Super Admin in branch KDY, when they create a user with name, email, phone and a KDY role, then the user is active, a member of KDY with that role, an invitation email is sent and `user.invited` is audited.
2. Given an email already used (any case), when a user is created with it, then 422 with an error on `email`.
3. Given the New user form, when the role list loads, then it shows only active roles of the active branch and no templates.
4. Given a request to `POST /users` or `POST /users/{id}/role` with a template role id, then 422 "Pick a role of this branch."
5. Given an inactive role, when it is assigned, then 422 "This role is inactive."
6. Given a Super Admin, when they add an existing KDY user to MAIN with the Viewer copy, then `/me` for that user lists both branches with their own role each.
7. Given a user with role Cashier in KDY, when Super Admin assigns Accountant in KDY, then the user holds only Accountant in KDY and other branches are unchanged.
8. Given a KDY Branch Admin, when they open Users, then only KDY members are listed and memberships in other branches are not shown.
9. Given a KDY Branch Admin, when they call `GET /users/{id}` for a user not in KDY, then 404.
10. Given an admin whose KDY role has `user.edit` but not `audit.view`, when they assign a KDY role that includes `audit.view`, then 403 and nothing changes.
11. Given a user whose role has a permission the acting admin lacks, when the admin changes that user's role or removes them from the branch, then 403.
12. Given a non-Super Admin, when they edit any user with `is_super_admin = true`, then 403.
13. Given a user in KDY and MAIN and an admin of KDY only, when the admin changes the user's email or status, then 403 with the "other branches" message; changing the KDY role still succeeds.
14. Given a user in KDY and MAIN, when Super Admin removes them from KDY, then they lose access to KDY (403 on `X-Branch-Id: KDY`), keep MAIN, their KDY transactions remain, and `user.removed_from_branch` is audited.
15. Given a branch with exactly one branch administrator, when anyone changes their role, removes them, deactivates them, deactivates their role, or removes `user.edit`/`role.manage` from their role, then 422 with the last-admin message and nothing changes.
16. Given a branch with two branch administrators, when one is deactivated, then it succeeds.
17. Given any logged-in user, when they try to deactivate themselves, change their own role or remove themselves from a branch, then 422.
18. Given an active user with two open sessions, when Super Admin deactivates them, then both tokens stop working at once (next call 401/403) and login returns 403 "Your account is inactive...".
19. Given a deactivated user, when they are reactivated, then they can log in and have the same branches and roles as before.
20. Given role.manage, when a role is created with a name that differs only by case or spaces from an existing role in the same branch, then 422; the same name in another branch succeeds.
21. Given a role is renamed to a name already used in its branch, then 422 (not 500).
22. Given a non-Super Admin, when they create or edit a role with a permission they lack, then 403 listing the missing keys; in the UI that checkbox is disabled.
23. Given a non-Super Admin, when they edit a role that already has a permission they lack, then 403.
24. Given the role editor, then permissions are grouped by module prefix and every key in the catalogue appears exactly once.
25. Given a role held by 2 active users, when it is deactivated, then status is inactive, the users stay members, `/me` shows no permissions from that role, and the list shows "Role inactive".
26. Given an inactive role, when it is reactivated, then its holders regain its permissions.
27. There is no endpoint or button that deletes a user, a role or a template.
28. Given a fresh install, then templates Branch Admin, Accountant, Cashier, Viewer exist with `branch_id NULL`.
29. Given Super Admin in branch KDY with no roles, when they click "Copy role templates", then four KDY roles are created with the template permissions, and running it again returns all four as `skipped`.
30. Given a copied Cashier in KDY, when the Cashier template is edited, then the KDY Cashier permissions are unchanged (and vice versa).
31. Given a non-Super Admin, when they call `POST /roles/copy-templates` or edit a template, then 403.
32. Given Super Admin in All branches mode, then Users lists every user with all memberships, "New user" is disabled, and Roles shows only templates.
33. Given an invited user who never logged in, when they use the emailed link within 60 minutes and set a valid password, then they can log in; an expired or reused link gives the expired message.
34. Given a user who has logged in before, when "Resend invitation" is called, then 422.
35. Given a user without `user.view` / `role.view`, then the Users / Roles menu items are hidden and the routes redirect to the dashboard.

## 8. Open questions (with assumptions used)
1. Invitation vs admin-set password: AG-020 says invitation; API today requires a password. **Assume** invitation only in the UI (password optional in API for tests/seeding), 60-minute link, resend allowed.
2. Deactivating a role in use: block, or allow with holders losing permissions? **Assume** allow; holders lose its permissions (B10).
3. Who counts as "Branch Admin" for the last-admin rule: role name or permissions? **Assume** permissions (`user.edit` + `role.manage`), so renamed copies still count; Super Admin is not counted.
4. Can a Branch Admin add an existing user from another branch to theirs? **Assume** no (they cannot see other branches' users); only Super Admin does it.
5. Removing a membership deletes a `branch_user` pivot row and role rows: does that conflict with "nothing is hard-deleted"? **Assume** acceptable because it is access data, fully recorded in the audit log; business records are untouched.
6. Backlog wording "view/create/edit/delete/approve" vs the real catalogue (manage, void, reverse, export...). **Assume** the catalogue wins; labels are derived from keys, no new permissions.
7. Copy templates automatically on branch creation? **Assume** no, one click on the Branches/Roles screen (AG-023 says "can be copied").
8. Should the last-admin rule bind Super Admin? **Assume** yes, to avoid orphaned branches; Super Admin assigns a new admin first.

## Accounting review
Reviewer: accountant, 2026-11-18. Focus: segregation of duties, audit trail, attribution of past entries. Code checked: `PermissionSeeder`, `UserController`, `RoleController`, `AuditLog`, `LedgerService` (read only).

**Fine as written (no change):** B2/E3 no-escalation incl. "target has more permissions than you"; B7 last-admin rule on permissions, binding Super Admin (open Q3, Q8); B8 no self-change; B9 transactions kept on deactivation; no delete endpoints (AC 27); open Q2 (deactivating a role in use) and Q4, Q6, Q7. Open Q5 (deleting `branch_user`/role rows) is acceptable **only if** finding 1 is done, because the audit log then becomes the sole history of who had what access when. Period control is unaffected (no postings in this feature). Renaming a user is acceptable because the audit log records `user_id` and old/new name.

### Findings
1. **MUST - Access changes need full old/new values in the audit log.** Today `user.role_assigned` writes `old = null`, so a role change Cashier -> Accountant cannot be reconstructed, and after N2 deletes the pivot/role rows nothing else holds that history. Every user/role/membership event must record actor, branch, old and new state including the permission keys, and audit rows can never be edited or deleted. ACs 36-40.
2. **MUST - Nobody may set another person's password in production.** E2 keeps `password` in `POST /users` "for tests/seeding". An admin who knows a user's password can post and void entries that are recorded under that user's name, so the audit trail no longer shows who really did them. Tests and seeders can use factories. Outside `local`/`testing`, the API must refuse `password`, and the only way to set one is the invitation. AC 41.
3. **MUST - Past entries keep their author after deactivation or removal.** B1 (other branches' users are hidden) must not hide the *author* of a transaction or audit row. `transactions.created_by`, `approved_by`, `period_locks.locked_by` and `audit_logs.user_id` are never changed or nulled. Entries show the author's name with a tag such as "(inactive)" or "(no longer in this branch)", and the "Created by" filter still lists former members. AC 42.
4. **SHOULD - Pending work of a leaver.** Entries a deactivated or removed user left as `pending` stay pending and another authorised user can approve or reject them. Their own approvals stop at once (tokens revoked). AC 43.
5. **SHOULD - Warn about risky permission combinations, don't block.** With only 2-3 staff per branch, hard segregation of duties is not realistic. The role editor shows a non-blocking warning when a role can (a) record and cancel the same entries (`income.create`+`income.void`, `expense.create`+`expense.void`, `transfer.create`+`transfer.reverse`), or (b) both manage users/roles and move money. Save is still allowed. The compensating control is the audit log, which a person other than the doer must be able to read (findings 8, 9). AC 44.
6. **SHOULD - Role template changes** (see "Recommended templates" below). The Accountant can record, void and reverse but cannot read the audit log, so the main reviewer of the books cannot check cashier entries or voids. Add `audit.view` (read-only, so it creates no segregation-of-duties risk). Add `category.manage`, because classification is accounting work and today only Branch Admin can do it. Keep `user.*`/`role.*` in the Branch Admin template only. AC 45.
7. **SHOULD - Re-running the seeder must not silently reset templates.** `PermissionSeeder::run` calls `syncPermissions` on every run, so a deploy that seeds would undo Super Admin edits to templates without an audit entry. Sync permissions only when the template is newly created; the catalogue itself may still be extended. AC 46.
8. **SHOULD - Flag self-cancellation.** When the user who voids or reverses an entry is the user who created it, the audit row carries `self: true` so a reviewer can filter for it. Warning only, not a block. AC 47.
9. **SHOULD - Oversight of Branch Admins.** A Branch Admin (`*`) can create a second login for themselves and use it to record and void entries, with only the audit log as evidence. There is no audit log screen or endpoint yet (`audit.view` exists but nothing uses it). Before go-live, either deliver the audit log screen (filter by event `user.*`/`role.*`) or email Super Admins when a non-Super Admin creates a user, assigns a role, or changes a role's permissions. AC 48.

### Recommended templates (`PermissionSeeder::TEMPLATES`)
| Template | Change |
|---|---|
| Branch Admin | No change (`*`). Accepted for a small branch; mitigated by findings 1, 8, 9. |
| Accountant | **Add `audit.view`, `category.manage`.** Keep the rest. |
| Cashier | No change (records only; cannot edit, void or reverse - good). |
| Viewer | No change (read-only incl. audit - suitable for owner/external accountant). |

### Acceptance criteria (continued)
36. Given a KDY user with role Cashier, when an admin assigns Accountant, then `user.role_assigned` has `branch_id` = KDY, actor `user_id`, `old = {role_id, role_name: "Cashier", permissions: [sorted keys]}` and `new = {role_id, role_name: "Accountant", permissions: [sorted keys]}`. When the user had no role in that branch, `old = null`.
37. Given a user is removed from KDY, then `user.removed_from_branch` has `branch_id` = KDY, `old = {role_id, role_name, permissions}`, `new = null`, and the actor's `user_id`.
38. Given a role is created, renamed, has permissions changed, is deactivated or is reactivated, then the audit row (`role.created`/`role.updated`/`role.deactivated`/`role.activated`) has old and new `name`, `status` and sorted `permissions`. Deactivation and activation also record `holders: [user ids]` for the active users who held it at that moment.
39. Given templates are copied into KDY, then one `role.copied_from_templates` row per created role records `{template_id, name, permissions}` with `branch_id` = KDY.
40. Given `user.invited`, `user.invite_resent` or a status change through `user.updated`, then the row records the target user, the branch and role (for invite) or old/new `status`, plus actor, ip and time. There is no endpoint that updates or deletes `audit_logs`.
41. Given `APP_ENV=production`, when `POST /users` includes `password`, then 422 on `password` "Passwords are set by the user through the invitation." and no user is created. In `testing`, the same request succeeds.
42. Given user X recorded transactions in KDY and is then deactivated or removed from KDY, then in KDY the transaction list, transaction detail and audit rows still show X's name with an "(inactive)"/"(no longer in this branch)" tag. The "Created by" filter still offers X. `created_by`, `approved_by` and `audit_logs.user_id` are unchanged, and branch account balances and report totals are identical before and after.
43. Given user X created a `pending` transaction and is then deactivated, then it stays `pending` and another user with the approval permission can approve or reject it. Any request with X's old token returns 401/403.
44. Given the role editor, when the ticked permissions include `income.create`+`income.void`, `expense.create`+`expense.void` or `transfer.create`+`transfer.reverse`, then a warning "This role can record and cancel the same entries. Make sure someone else reviews the audit log." is shown. When any of `user.create`/`user.edit`/`role.manage` is combined with any of `income.create`, `expense.create`, `transfer.create`, `*.void`, `transfer.reverse` or `account.manage`, then "This role can manage users and move money." is shown. In both cases Save succeeds.
45. Given a fresh install, then the Accountant template = current list + `audit.view` + `category.manage`. Cashier and Viewer are unchanged, Branch Admin has every key, and no template except Branch Admin contains a `user.*` or `role.*` key.
46. Given a Super Admin removed `report.export` from the Accountant template, when `PermissionSeeder` runs again, then the template still lacks `report.export`. Branch copies are never touched by the seeder.
47. Given user X voids an income or expense, or reverses a transfer, that X created, then the `transaction.voided`/`transaction.reversed` audit row includes `self: true`. When the voider differs from the creator, `self: false`.
48. Given a non-Super Admin creates a user, assigns a role, or changes a role's permissions, then that event can be listed by a Super Admin (audit log screen filtered by `user.*`/`role.*`, or an email to every active Super Admin naming actor, branch, target and old -> new). This must be in place before go-live.

## Accountant sign-off
Reviewer: accountant, 2026-11-18. Branch `feature/agents-users-roles` (up to `93360c0`). Evidence: `cd api && php artisan test` gives **58 passed (551 assertions)**. I read `UserController`, `RoleController`, `AuthController`, `LedgerService` (void/reverse), `PermissionSeeder`, `BranchAdmins`, `AccessAlerts` + `AccessChangedNotification`, `TransactionController` (author tags), `routes/api.php`, the web role editor, and the tests in `api/tests/Feature`. I made no code changes. QA results are taken as reported: ACs 36-48 pass in the browser, AC 42 passes in part and AC 43 cannot be tested yet.

### Findings
| # | Finding | Result | Evidence |
|---|---|---|---|
| 1 | MUST: full old/new values in the audit log | **DONE** | `user.role_assigned` stores `Role::snapshot()` (role id, name, sorted permissions) as old and new, and old is null for a first role (`UserController.php:125-135`). `user.removed_from_branch` stores the old role and new = null (`:164`). `user.created`/`user.invited` store the branch and the role snapshot (`:97`, `:103`). `user.updated` stores the old and new fields, including status (`:216-227`). Role events store name, status and permissions, and deactivate/activate also store `holders` (`RoleController.php:86-106`). `role.copied_from_templates` stores the template id, name and permissions (`:134`). `AuditLog::record` adds the actor, branch, ip and time. No route writes `audit_logs`. Tests: `AccessAuditTest::test_ac36_*`, `test_ac37_*`, `test_ac38_*`, `test_ac40_*` (which checks that audit routes are GET only), `RoleManagementTest::test_ac29_ac39_*`. |
| 2 | MUST: nobody sets another person's password in production | **DONE** | `UserController.php:69-74` refuses `password` outside `local`/`testing` with the required message. `PUT /users/{id}` does not accept a password. The only other way to set one is the invitation token (`AuthController::setPassword`, which writes the `user.password_set` audit row). Test: `UserManagementTest::test_ac41_password_is_refused_outside_local_and_testing`. |
| 3 | MUST: past entries keep their author | **DONE for all screens that exist** | `created_by`, `approved_by` and `audit_logs.user_id` are never written by user or role changes. Users cannot be deleted, so the foreign keys have no cascade path. `TransactionController::tagAuthors` and `creators()` return the author name with "inactive" or "no longer in this branch". The list UI and the "Created by" filter show this (`transaction-list.component.ts:24,45`). `GET /transactions/{id}` returns the same tag. Test `test_ac42_*` proves the authors and audit user ids are unchanged, the filter still offers the leaver, and the balance and dashboard are identical before and after. **Deferred:** no transaction detail screen exists yet, and audit rows are not shown anywhere yet (see below). |
| 4 | SHOULD: pending work of a leaver | **PARTLY (by design)** | A pending entry stays pending with the same `created_by` after deactivation, and the leaver's old token returns 401 (`test_ac43_*`). Approve/reject cannot be checked because there is no approval workflow yet. **Deferred** to AG-051 and AG-064. |
| 5 | SHOULD: warn about risky combinations | **DONE** | `role-editor.component.ts:109-120` shows both texts exactly as written in AC 44. The key lists match AC 44 (`ADMIN_KEYS`/`MONEY_KEYS` at lines 11-12, record and cancel pairs). The warnings never stop Save. QA confirmed this in the browser. |
| 6 | SHOULD: role template changes | **DONE** | `PermissionSeeder.php:305-311`: Accountant = the previous list + `audit.view` + `category.manage`. Cashier and Viewer are unchanged, and Branch Admin = `*`. Only Branch Admin holds `user.*`/`role.*`. Test: `RoleManagementTest::test_ac28_ac45_fresh_install_templates`. I am satisfied with the templates as delivered. |
| 7 | SHOULD: re-seeding must not reset templates | **DONE** | `syncPermissions` runs only when a template is first created (`PermissionSeeder.php:328-333`). New catalogue keys are added only to the `*` template (Branch Admin), and keys a Super Admin removed are not added back. Branch copies are never touched. Tests: `test_ac46_reseeding_does_not_reset_templates_or_touch_copies`, `ReviewFixesTest::test_seeder_adds_new_catalogue_keys_to_all_keys_templates_only`. **Accepted:** a new key does not reach Accountant, Cashier or Viewer automatically. Whoever adds a key must decide for each template and set it through the Roles screen, which writes an audit row. That is the right control. |
| 8 | SHOULD: flag self-cancellation | **DONE** | `LedgerService.php:156` (reverse) and `:183` (void) write `self: true/false` in `new_values`. Test: `test_ac47_self_cancellation_is_flagged`, which covers income void and transfer reverse in both the self and other cases. |
| 9 | SHOULD: oversight of Branch Admins | **DONE (email option)** | `AccessAlerts::notify` emails every active Super Admin when a non-Super Admin creates a user, assigns a role, removes a user from a branch, creates a role or changes a role's permissions. The email names the actor, branch, target and before -> after values. Super Admins' own changes send no email. Test: `test_ac48_*`. The alert runs after the audit row is written, so a mail failure cannot lose the record (`ReviewFixesTest::test_mail_transport_failure_*`). |

### Notes and conditions
- **Go-live condition (operations, not code):** invitations and AC 48 alerts are queued mails. `.env.example` has `QUEUE_CONNECTION=database` and `MAIL_MAILER=log`. Production must run a queue worker and use a real mailer. Otherwise nobody receives the oversight emails, and the email option of finding 9 has no effect. Put this on the deployment checklist.
- **Small follow-up (SHOULD, not blocking):** the alert does not cover `user.updated` (an email or status change made by a Branch Admin). For a user who has never logged in, a Branch Admin could change the email to an address they control and then use "Resend invitation" to get a second login without an email to Super Admin. The audit row still records it. Add `user.updated` alerts for email and status changes to `AccessAlerts`, either with AG-090 or sooner.
- **Bootstrap Super Admin** gets `ADMIN_PASSWORD` from the seeder, and only once. This is acceptable for installation. Change it at first login (AG-002 password reset).
- **Deferred to later backlog items, which must carry the matching ACs:**
  - AG-090 (audit trail screen, R1 Must): show audit rows with the actor's name and the "(inactive)"/"(no longer in this branch)" tag (rest of AC 42). Add filters for `user.*`/`role.*` and `self = true` (findings 8 and 9). Also refuse update/delete on `AuditLog` at the model level as defence in depth.
  - AG-063 / AG-065 (edit/void and the transaction list): when a transaction detail screen is built, it must show the author with the same tag (rest of AC 42).
  - AG-051 / AG-064 (approvals, R2): AC 43 in full. A leaver's pending entries can be approved or rejected by another authorised user, and approvals use the same `self` flag when approver = creator.
- Figures: this feature makes no postings. Test `test_ac42_*` shows that branch balances and dashboard totals are unchanged by deactivation and removal. Period control is not affected.

### Verdict: **SIGNED OFF**
All three MUST findings are done. SHOULD findings 5-9 are done, and finding 4 is done as far as the current product allows. The items left open are tied to screens or workflows that do not exist yet and are assigned to AG-090, AG-063/AG-065 and AG-051/AG-064 above. Before go-live, a queue worker and a real mailer must be running.
