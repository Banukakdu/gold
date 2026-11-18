# Spec: Password reset by email (AG-002) and login rule check (AG-001)

## 1. Goal and scope
- **AG-002** "As a user, I want to reset my password by email so that I can regain access without admin help."
  - 'Forgot password' sends a time-limited (60 min) reset link.
  - The reset link is single-use.
  - The new password must meet the password policy (min 8 characters, a letter and a number).
- **AG-001 check** (login rules). Section 7 lists what the code does today. The small gaps G1, G3 and G4 are in scope; G2 is an open question.

In scope, besides the two pages and two endpoints:
- one shared password policy, used by set-password, reset and `POST /users`;
- revoking all of the user's sessions (API tokens) after a reset;
- a "your password was changed" email to the user, and an alert to Super Admins when an admin account is reset;
- audit events, with the requester's IP and user agent even when the work runs in the queue worker (R12);
- `/auth/set-password` accepts invited users only (section 6, R9), and gets the same link safety as `/reset-password` (4.3, Q6);
- a switch `AUTH_SUPER_ADMIN_EMAIL_RESET` (default `false`) that turns Super Admin reset by email off (R11);
- a console recovery command `php artisan auth:reset-password {email}` (section 5, N6);
- an email to the **old** address when an admin changes a user's sign-in email (E4).

Out of scope:
- a "change password" screen for a signed-in user (users-and-roles spec, out of scope); the shared policy is ready for it;
- 2FA (AG-005);
- an admin "send reset link" button (admins keep "Resend invitation");
- password history or reuse rules;
- SMS reset;
- a reset by an admin without email in the app (the console command N6 needs server access and is not an app feature).

Reuse what exists: `AuthController::setPassword` (Laravel password broker `users`, `password_reset_tokens` table, `expire` 60, `throttle` 60), `InvitationNotification` (queued, `afterCommit`, link from `config('app.frontend_url')`), `AccessAlerts` + `AccessChangedNotification`, `AuditLog::record`, the `.login` card layout used by `login.component.ts` and `set-password.component.ts`.

## 2. Personas and permissions
No permission key is needed. Both endpoints are public. Reading the audit rows needs `audit.view`. The rows have no branch (`branch_id` null), so only a Super Admin sees them, as with `auth.login` today (controls spec Q5).

| Persona | Can request a reset | Notes |
|---|---|---|
| Any active user who has logged in before (Cashier, Accountant, Viewer, Branch Admin templates, custom roles) | yes | |
| Active invited user who has never logged in (`last_login_at` null) | yes | A completed reset also completes the invitation (R7). |
| Inactive user (`status = inactive`) | no email is sent | The response is the same as for anyone else (R2). |
| Super Admin (`is_super_admin`) | only when `AUTH_SUPER_ADMIN_EMAIL_RESET=true` and another active Super Admin exists | Off by default: no email is sent, the request is refused in the audit log and the other Super Admins are alerted; the response is the same (R2, R11). Recovery is the console command N6. When on, every other active Super Admin is alerted on a reset (R13). |
| Unknown email | no email is sent | The response is the same (R2). |

## 3. User flows
**F1 Forgot password.**
1. On `/login` the user taps "Forgot password?". The app opens `/forgot-password?email=<what they typed>`.
2. They enter or confirm the email and tap "Send reset link".
3. They always see the same confirmation (section 4.2).
4. If the account is active, they get an email with "Reset password" -> `<FRONTEND_URL>/reset-password?token=..&email=..`.

**F2 Reset.**
1. The user opens the link and enters a new password and the confirmation, then taps "Change password".
2. The API sets the password, uses up the link and signs the user out of every device. It then emails them "Your password was changed".
3. The app goes to `/login?password_reset=1`, which shows "Your password has been changed. Sign in with your new password."
4. The user signs in as usual: AG-001 rules, branch check B12, default branch. They are not signed in automatically.

**F3 Expired or used link.** The page shows "This reset link is invalid or has expired." with a "Request a new link" link to `/forgot-password?email=<email>`.

**F4 Super Admin.**
- Default (`AUTH_SUPER_ADMIN_EMAIL_RESET=false`): F1 shows the same confirmation, but no email is sent. The request is audited as refused and every other active Super Admin gets an alert. The Super Admin recovers with the console command N6 (someone with server access runs it).
- With the switch on: same as F1 and F2, provided at least one other active Super Admin exists. In addition, every *other* active Super Admin gets an alert email.
- Super Admins have no "All branches" difference here: the flow happens before sign-in and is not tied to a branch.

**F6 Console recovery.** An operator with server access runs `php artisan auth:reset-password user@x.com`, types the new password twice (hidden), and the user can sign in with it. The bootstrap Super Admin replaces `ADMIN_PASSWORD` this way.

**F5 Invited user who lost or let the invitation expire.** They can use F1 instead of asking the admin. The reset link replaces the invitation link, because there is one token per email (R6).

## 4. Screens
All three are public routes outside the shell, using the existing `.login` > `.card` layout and the `Account gold` brand header.

### 4.1 Login `/login` (change)
- Add a link "Forgot password?" under the Password field (`routerLink="/forgot-password"`, `queryParams: {email}` when the field is not empty).
- Add a banner: when `?password_reset=1` is set, show `.ok` "Your password has been changed. Sign in with your new password." The existing `?password_set=1` banner stays.

### 4.2 Forgot password `/forgot-password` (new)
| Field | Type | Required | Validation |
|---|---|---|---|
| Email | `type=email`, `autocomplete=username`, pre-filled from `?email=` | yes | valid email, max 255 |

- Button: "Send reset link". While the request runs it reads "Sending…" and is disabled.
- Success (202): hide the form and show `.ok` "If an account exists for {email}, we have sent a link to reset your password. The link works for 60 minutes. Check your spam folder if it does not arrive."
  - Under it, the button "Send again" is disabled for 60 seconds with a countdown. After that it sends the same request again.
  - Show "Back to sign in".
- Errors:
  - 422 shows the field error under Email.
  - 429 shows the API message in `.alert` (for example "Too many requests. Try again in 15 minutes.").
  - A network or 5xx error shows `.alert` "Something went wrong. Try again."
- Always show the link "Back to sign in" (`/login`).

### 4.3 Reset password `/reset-password?token=..&email=..` (new)
- Header: "Choose a new password for {email}".

| Field | Type | Required | Validation (client, server is the authority) |
|---|---|---|---|
| New password | `type=password`, `autocomplete=new-password` | yes | min 8 characters, at least one letter and one digit |
| Confirm password | `type=password`, `autocomplete=new-password` | yes | equals New password, else "The passwords do not match." with no request sent |

- Hint: "At least 8 characters, with a letter and a number." This is the same text as on set-password.
- Button: "Change password". While the request runs it reads "Saving…" and is disabled.
- Missing `token` or `email`, or a 422 with a `token` error:
  - hide the form;
  - show `.alert` "This reset link is invalid or has expired.";
  - show the link "Request a new link" to `/forgot-password?email={email}`.
- A 422 with a `password` error shows the server message under the field, and the form stays.
- Success: go to `/login?password_reset=1`.
- Link safety (also on `/set-password`, Q6):
  - After reading the query, the page removes `token` from the address bar with `history.replaceState`. A reload then shows the form only while the values are still held in memory.
  - The page sets `<meta name="referrer" content="no-referrer">` while it is open, so the token is never sent in a Referer header.

### 4.4 Layout at 390px
- All three pages use `.login`: 16px padding and a card with `max-width: 360px; width: 100%`.
- At a 390px viewport:
  - there is no horizontal scroll;
  - inputs and the primary button are full card width;
  - the "Forgot password?" link and "Back to sign in" are at least 40px tall tap targets;
  - long emails in the header and confirmation wrap (`overflow-wrap: anywhere`).

## 5. API
### N1 `POST /api/v1/auth/forgot-password` (new, public)
- Request: `{ email }`.
- Response: **202** `{ "message": "If an account exists for this email, a reset link has been sent." }`. It is always the same for a valid email format.
- Errors:
  - 422 `email` for an empty or malformed email (this does not depend on whether the account exists);
  - 429 `{ message }` when throttled (R4).
- Work done in the request: validate, check the throttles, lower-case the email, capture the requester's IP and user agent, then dispatch `SendPasswordResetLink(email, ip, userAgent)` (a queued job, `afterResponse` when `QUEUE_CONNECTION=sync`). That is all. The request does no user lookup, hashing or mail. This keeps the response time the same whether or not the email exists (R2).
- Job (every audit row it writes carries the IP and user agent passed from the request, not the worker's, R12):
  1. Find the user by `lower(email)`.
  2. If there is no user, stop. There is no audit row (this keeps unknown addresses out of the log).
  3. If the user is inactive, write the audit row `auth.password_reset_refused` with `{ email, reason: "inactive" }`, send no email, and stop.
  4. If the user is a Super Admin and `auth.super_admin_email_reset` is off, write `auth.password_reset_refused` with `{ email, reason: "super_admin_email_reset_disabled" }`, send no link, alert every other active Super Admin (R13c), and stop.
  5. If the user is a Super Admin, the switch is on, and no other active Super Admin exists, write `auth.password_reset_refused` with `{ email, reason: "no_other_super_admin" }`, send no link, and stop.
  6. If the broker throttle applies (a token for this email was made less than 60 s ago, `Password::RESET_THROTTLED`), stop quietly.
  7. Otherwise `Password::broker()->createToken($user)`, which deletes any older token row for the email. Then write the audit row `auth.password_reset_requested`, send `PasswordResetLinkNotification`, and send nothing to admins.
- The N1 response is the same in every case above (R2).

### N2 `POST /api/v1/auth/reset-password` (new, public)
- Request: `{ token, email, password, password_confirmation }`.
- Response: **204**.
- Errors:
  - 422 `token`: "This reset link is invalid or has expired." This is the same text for an unknown email, wrong token, used token, expired token, inactive user, or a Super Admin while `auth.super_admin_email_reset` is off (so a link made before the switch was turned off cannot be used).
  - 422 `password`: policy or confirmation.
  - 429 (R4).
- On success, in one DB transaction:
  1. Set `password` (the `hashed` cast).
  2. `Password::reset` deletes the token row.
  3. `$user->tokens()->delete()`.
  4. `auth()->setUser($user)`, then `AuditLog::record('auth.password_reset', $user, null, {...})` (R12), so the row's actor (`user_id`) is the user, as in `setPassword`.
- After commit:
  1. Clear the login lockout for `email|ip` of this request (AG-001).
  2. Queue `PasswordChangedNotification` to the user (R13).
  3. Send the admin alert if R13 applies.

### Existing endpoints that change
- **N5 `POST /auth/set-password`**:
  - the password rule becomes the shared policy (R10);
  - it refuses users with `last_login_at` not null, with its existing expired message (R9);
  - it also deletes any API tokens of the user (none are expected);
  - the page gets the 4.3 link safety (Q6).
- **E2 `POST /users`**: the `password` (local/testing only) uses the shared policy.
- **E4 `PUT /users/{user}`**:
  - when `email` changes, delete the `password_reset_tokens` row for the old email;
  - when `email` changes, queue `EmailChangedNotification` to the **old** address: "Your {app name} sign-in email was changed to {new} by {admin name}. If you did not expect this, contact your administrator at once." This applies whoever made the change, a Super Admin included. A mail failure is reported and never fails the request;
  - when the user is deactivated, delete the row for their email.
  - This way an old link can never be used after these changes, and the account owner learns about a redirected mailbox.
- **`POST /auth/login`**: G1, G3 and G4 in section 7.
- **`POST /auth/logout`**: write `auth.logout` (G4).
- **AuditLogController**:
  - the "Logins" group becomes the prefix `auth.`;
  - add labels: `auth.logout` "Logout", `auth.locked_out` "Login locked", `auth.password_reset_requested` "Password reset requested", `auth.password_reset_refused` "Password reset refused", `auth.password_reset` "Password reset".

### New code
- `App\Notifications\PasswordResetLinkNotification`:
  - `ShouldQueue` and `afterCommit`, like `InvitationNotification`;
  - subject "Reset your {app name} password";
  - action "Reset password" to `rtrim(config('app.frontend_url'),'/').'/reset-password?'.http_build_query(['token'=>..,'email'=>..])`;
  - text: "This link expires in 60 minutes and can be used once. If you did not ask for this, ignore this email; your password stays the same."
- `App\Notifications\PasswordChangedNotification`:
  - queued;
  - text: "Your {app name} password was changed on {date time, business timezone} from IP {ip}. You have been signed out on all devices. If this was not you, contact your administrator at once."
  - It contains no link with a token.
- Shared policy: `Illuminate\Validation\Rules\Password::defaults(fn () => Password::min(8)->letters()->numbers())` in `AppServiceProvider`. Every endpoint that accepts a new password uses `['required', 'string', Password::defaults(), 'confirmed']`. No endpoint keeps its own regex.
- Named rate limiters in `AppServiceProvider`: `password-forgot` and `password-reset` (R4).
- `App\Notifications\EmailChangedNotification`: queued, `afterCommit`, sent on demand to the old address (E4). No link.
- `App\Notifications\PasswordResetAlertNotification`: queued, `afterCommit`, the R13b/R13c alert to Super Admins. No link.
- Config `auth.super_admin_email_reset` (env `AUTH_SUPER_ADMIN_EMAIL_RESET`, default `false`), R11.
- `AuditLog::record()` gets optional `ip` and `userAgent` arguments; when given they are written instead of `request()`'s (R12).

### N6 `php artisan auth:reset-password {email}` (new, console)
- Finds the user by lower-cased email; an unknown email exits 1 with "No user with this email.".
- Asks "New password" and "Confirm new password" with hidden input, and validates them with `Password::defaults()` and `confirmed`. A failure prints the messages, changes nothing and exits 1.
- In one DB transaction: sets the password, deletes the user's `password_reset_tokens` row, deletes every API token, and writes `auth.password_reset` `{ user_id, email, via: "console", tokens_revoked }` with the actor (`user_id` column) null.
- After commit: clears the login lock keys for the email (every IP), queues `PasswordChangedNotification` (no IP: "from the server console"), and sends the R13b alert when it applies.
- It works for any user and does not change `status`. It is the only recovery route for a Super Admin while the switch is off, and the way the bootstrap Super Admin replaces `ADMIN_PASSWORD`.

## 6. Business rules and edge cases
- **R1 One broker.** The invitation and the reset use the same broker (`users`) and table (`password_reset_tokens`, one row per email).
  - The row stores only a hash of the token (Laravel `Hash::make`). The plain token appears only in the email.
  - The token is 64 random characters.
- **R2 No user enumeration.**
  - N1 returns the same status, body and headers for an unknown, inactive, invited, throttled-by-broker or active email.
  - All user-dependent work runs in the queued job, so response time does not depend on the account.
  - N2 returns the same 422 `token` message for every failure reason, and N2 and N5 run exactly one bcrypt check on every path (the row's hash, or a shared dummy hash when there is no user, no live row or a refused user), so timing does not tell the reasons apart either (fix round R1).
  - The UI confirmation never says whether the account exists.
- **R3 Expiry and single use.**
  - A token is valid for 60 minutes from creation (`auth.passwords.users.expire = 60`).
  - A successful reset deletes it. The row is read `FOR UPDATE` and only the request whose delete removes it may continue, so two parallel submits use it once (fix round Q1). A database error is reported without the SQL bindings, which may hold the new hash.
  - A new request (or a new invitation) replaces the row, so older links stop working.
  - A wrong password policy or a confirmation mismatch does **not** use up the token.
- **R4 Throttling.**
  - N1:
    - per IP, 5 per minute and 20 per hour;
    - per email (lower-cased, whether or not it exists), 3 per 15 minutes;
    - plus the broker's quiet 60 s per account.
  - N2:
    - per IP, 10 per minute (as for set-password);
    - per email, 10 per 15 minutes.
  - When throttled the response is 429 `{ message: "Too many requests. Try again in N minutes." }`. N is rounded up, minimum 1 ("1 minute").
  - Emails are matched exactly as stored (lower-case, byte for byte) after the database lookup (`User::findByEmail`). An accent variant that MySQL's collation would match (`ú@x.com` for `u@x.com`) is an unknown email on login, N1, N2, N5 and N6, so the per-email limits keyed on the typed text cannot be bypassed (fix round R3).
  - The per-email limit applies equally to unknown emails, so a 429 says nothing about whether an account exists.
- **R5 Inactive users.**
  - N1 sends no email (R2).
  - If a user is deactivated after the link was sent, N2 refuses with the token message. Deactivation also deletes their token row (section 5).
- **R6 Invitation and reset collide.**
  - A reset request for an invited user replaces the invitation token; the invitation link then shows its expired message.
  - Likewise, "Resend invitation" replaces a pending reset link.
  - The latest email always wins.
- **R7 A reset completes an invitation.** An invited user (`status` active, `last_login_at` null) who resets their password can sign in. This is safe because both links prove control of the same mailbox. The audit row carries `first_password: true`. The users list keeps "Invitation pending" until their first login, as it does today.
- **R8 Sessions.**
  - A successful reset deletes **all** the user's Sanctum tokens, so every device gets 401 on its next call and the app goes to `/login`.
  - The user is not signed in by N2.
- **R9 set-password is for invitations only.** `/auth/set-password` refuses users who have logged in before, so a reset link used on the set-password page can't skip the reset audit and alerts. Invited users can use both pages.
- **R10 One password policy.** It is enforced in one place (`Password::defaults()`): at least 8 characters, at least one letter, at least one digit. The UI hint text is the same on every page.
- **R11 Super Admin.**
  - Risk: whoever controls a Super Admin mailbox gets every branch, the Main account, period locks and the year-end close, and there is no second factor until AG-005.
  - So Super Admin reset by email is **off by default**: `auth.super_admin_email_reset` (env `AUTH_SUPER_ADMIN_EMAIL_RESET`, default `false`) until AG-005 2FA ships (Q1).
    - Off: N1 sends no link to a Super Admin and writes `auth.password_reset_refused` `{ reason: "super_admin_email_reset_disabled" }`; the response is unchanged (R2). N2 refuses a Super Admin's token with the standard `token` message.
    - On (a business with at least two Super Admins may choose it): N1 refuses with `reason: "no_other_super_admin"` when the Super Admin is the only active one, because the R13b alert would then have no independent reader.
  - Recovery is the console command N6. Server access is a separate control from the mailbox, and anyone with server access can already change the database, so the command adds no new risk.
  - Mitigations when the switch is on: the alert to the other Super Admins (R13), the "password changed" email to the account itself, all tokens revoked, and the audit row.
  - The seeded bootstrap Super Admin replaces `ADMIN_PASSWORD` with N6, not with "Forgot password".
- **R12 Audit, with no secrets.**
  - Events:
    - `auth.password_reset_requested`: new `{ email }`;
    - `auth.password_reset_refused`: new `{ email, reason }` (`inactive`, `super_admin_email_reset_disabled`, `no_other_super_admin`);
    - `auth.password_reset`: new `{ user_id, email, first_password, tokens_revoked: <count> }` from N2, or `{ user_id, email, via: "console", tokens_revoked }` from N6;
    - `auth.locked_out`: new `{ email }`, with auditable = the user when the email exists;
    - `auth.logout`.
  - `AuditLog::record` adds ip, user agent and time.
    - The `requested` and `refused` rows are written by the queue worker, so N1 captures the requester's IP and user agent and passes them to the job, which writes them on the row. They are never null and never the worker's.
  - `user_id` (the actor) is the user for an N2 reset (`auth()->setUser($user)` on the public route) and for logout. It is null for request/refused/locked_out, because they are not signed in, and for an N6 console reset. `auditable` is the user on every row about a known user.
  - Rows never contain the password, its hash, the reset token or its hash, or an API token.
  - `branch_id` is null.
- **R13 Notifications.** Both are queued and sent after commit. A mail failure is reported and never fails the request, as in `AccessAlerts`.
  - (a) The user always gets `PasswordChangedNotification` after N2 succeeds. They do not get it after an invitation set-password.
  - (b) Alert to every **other** active Super Admin, through a new `AccessAlerts::passwordReset(User $user)` (subject "Password reset: {name} <{email}>"):
    - when the reset user is a Super Admin;
    - or when the reset user is a branch administrator (`BranchAdmins::branchesOf($user)` is not empty).
    - The existing `notify()` skips this because it ignores Super Admin and guest actors.
    - The same alert is sent after an N6 console reset.
  - (c) Alert to every other active Super Admin when N1 refuses a Super Admin request because the switch is off: "Password reset requested for Super Admin {email}, refused". The per-email throttle limits it to 3 per 15 minutes. It is an early sign that someone is targeting the account.
  - Resets of other users are in the audit log only.
- **R14 Link URL.**
  - Links are built only from `config('app.frontend_url')` (env `FRONTEND_URL`, already in `.env.example`, default `http://localhost:4200`). They are never built from the request's Host header, so a forged Host cannot redirect the link.
  - The queue worker must run with the same `.env`.
- **R15 Queue.**
  - The request job and both mails are queued.
  - Production must run `php artisan queue:work` and a real mailer, or no reset emails arrive (users-and-roles go-live note).
  - Locally, set `QUEUE_CONNECTION=sync`. With `MAIL_MAILER=log`, the link appears in `storage/logs/laravel.log`.
  - Production must not run `MAIL_MAILER=log`, because the plain link would be written to `laravel.log` (go-live note). The queued notification payload in `jobs` and `failed_jobs` holds the plain token; that is accepted because it expires in 60 minutes, but `failed_jobs` must never be shown in an admin screen.
- **R16 A user with no branch** (non-Super Admin, B12) may reset. Login then still refuses with the existing "no access to any branch" message.
- **R17 Email case.** Emails are compared lower-cased (B11). The link carries the stored lower-case email.

## 7. AG-001 check (login) — existing code
| Rule | Today (`AuthController::login`, `logout`) | Status |
|---|---|---|
| Login returns a Sanctum token | `createToken('web')`, plus `auth.login` audited | Meets |
| 5 failed attempts within 15 min lock login for 15 min | `RateLimiter` key `email\|ip`, `hit($key, 900)`, `tooManyAttempts($key, 5)`. Test `test_login_and_lockout_after_five_failures`. | **Partly.** G1, G2 |
| Inactive users cannot log in, clear message | 403 "Your account is inactive. Contact your administrator." It is shown only after a correct password. Deactivation deletes tokens. | Meets |
| Logout revokes the token | `currentAccessToken()->delete()` | Meets (G4: not audited) |

- **G1 (in scope)** The decay starts at the **first** failure, so the 5th failure at minute 14 locks for only about 1 minute.
  - Fix: on the 5th failure in the window, set a separate lock key `login-lock:{email}|{ip}` for 15 minutes from that moment (`Cache::add`, so one request starts it). While it exists, return 429.
  - Attempts are counted before the password check; an attempt over the limit is refused without a check (fix round R2). The keys carry a per-email generation that the console command bumps to clear every IP (R4).
  - A successful reset clears both keys for the request IP.
- **G2 (Q3, decided)** The key includes the IP, so an attacker who changes IP is never locked out.
  - A per-email-only lock would let anyone lock out any named user (a denial of service), for example a cashier at the till.
  - Decided: keep `email|ip`. No per-email hard lock. A later item may add detection without a lock (`auth.suspicious` + alert at 20 failures in 15 minutes for one email from any IP).
- **G3 (in scope)** An unknown email returns without `Hash::check`, so it answers faster than a known one (enumeration by timing). Fix: check against a fixed dummy bcrypt hash when the user is not found.
- **G4 (in scope)** Lockouts and logouts are not audited. Fix:
  - write `auth.locked_out` once when a lock starts (not on every failure);
  - write `auth.logout` on logout.

## 8. Acceptance criteria
Mail is faked with `Notification::fake()`, and the queue runs sync in tests unless stated.

**Request (N1)**
1. Given an active user `u@x.com`, when `POST /auth/forgot-password {email:"U@x.com"}`, then 202 with the fixed message. `PasswordResetLinkNotification` is sent to that user. Its URL starts with `config('app.frontend_url').'/reset-password?token='` and contains `email=u%40x.com`. `auth.password_reset_requested` is audited.
2. Given an unknown email, an inactive user, and an active user, when each is requested, then all three responses have the same status, JSON body and header set. Only the active user receives an email.
3. Given an inactive user, when requested, then no notification is sent and `auth.password_reset_refused` with `reason: inactive` is audited.
4. Given an unknown email, when requested, then no audit row and no notification are created.
5. Given an empty or malformed email, then 422 on `email`.
6. Given a request for a user, when the same email is requested again 30 s later, then 202 and no second email (broker throttle). After 61 s a new email is sent, and the first token no longer works (AC 13).
7. Given 3 requests for the same email (existing or not) within 15 minutes, when a 4th is sent, then 429 "Too many requests. Try again in N minutes." The same happens for an unknown email.
8. Given 5 requests from one IP within a minute for different emails, when a 6th is sent, then 429.
9. Given the `password_reset_tokens` row after a request, then `token` is not equal to the plain token in the email, and `Hash::check(plain, row.token)` is true.
10. Given a forged `Host: evil.test` header on N1, then the emailed URL still starts with `FRONTEND_URL`.

**Reset (N2)**

11. Given a valid token, when N2 is called with a password that meets the policy and its confirmation, then 204. Login with the new password succeeds and the old password gives 422 "Invalid email or password."
12. Given a used token, when N2 is called again, then 422 `token` "This reset link is invalid or has expired."
13. Given a token older than 60 minutes (travel 61 min), or a token replaced by a newer request, then 422 with the same message.
14. Given an unknown email, a wrong token, or a user deactivated after the request, then 422 with the same `token` message and body.
15. Given `abcdefgh`, `12345678` or `abc1234`, then 422 on `password`, and the token still works afterwards.
16. Given a confirmation that differs, then 422 on `password`, and the token still works.
17. Given a user with 2 active API tokens, when the reset succeeds, then both tokens get 401 on `GET /me`, and `tokens_revoked: 2` is in the audit row.
18. Given a successful reset, then `auth.password_reset` is audited with `user_id`, `email`, `first_password`, `tokens_revoked`, ip and time. The row's `user_id` column (the actor) equals the reset user's id, and `auditable` is that user. No audit column contains the new password, the bcrypt hash, the reset token or its hash.
19. Given a successful reset, then `PasswordChangedNotification` is sent to the user with time and IP, and it has no link containing a token.
20. Given `AUTH_SUPER_ADMIN_EMAIL_RESET=true`, a Super Admin A resets their password and Super Admin B is active, then B gets the alert and A does not. Given a branch administrator resets, then every active Super Admin gets the alert. Given a Cashier resets, then no Super Admin gets an alert.
21. Given the mail transport throws during N2, then N2 still returns 204 and the audit row exists.
22. Given 10 N2 calls from one IP in a minute, when an 11th is sent, then 429.
23. Given a user locked out at login from IP 1 (AG-001), when they reset from IP 1, then login from IP 1 with the new password succeeds at once.

**Invitations and account state**

24. Given an invited user (never logged in) with a pending invitation, when they request a reset, then the invitation token stops working (set-password gives its expired message). Using the reset link succeeds, they can log in, and the audit row has `first_password: true`.
25. Given a user who has logged in before and holds a valid reset token, when they post it to `/auth/set-password`, then 422 with the set-password expired message and the password is unchanged.
26. Given a pending reset link, when an admin changes the user's email or deactivates the user, then the old link gives 422 `token`.
27. Given `POST /users` (testing env), `/auth/set-password` and `/auth/reset-password`, when each gets `abcdefgh`, then each returns 422 on `password` from the same rule (`Password::defaults()`).
28. Given a non-Super Admin with no branch, when they reset, then 204, and login returns the existing 403 "no access to any branch" message.

**Login (AG-001)**

29. Given 4 failures at minute 0 and the 5th at minute 14, when the correct password is tried at minute 20, then 429. At minute 29:01 it succeeds.
30. Given 5 failures, then exactly one `auth.locked_out` row is written. A 6th failure while locked writes none.
31. Given a logout, then that token gets 401, other tokens of the user still work, and `auth.logout` is audited.
32. Given an unknown email, then the login code runs one `Hash::check` (verified with a spy/mocked hasher). The response stays 422 "Invalid email or password."
33. Given the audit log screen as Super Admin with group "Logins", then `auth.login`, `auth.logout`, `auth.locked_out` and the three `auth.password_reset*` events are listed with their labels.

**UI**

34. Given `/login`, then a "Forgot password?" link is visible. With `user@x.com` typed in Email, tapping it opens `/forgot-password?email=user%40x.com` with the field pre-filled.
35. Given `/forgot-password`, when the form is submitted for any valid email, then the confirmation text from 4.2 shows, the form is hidden, and "Send again" is disabled for 60 s.
36. Given `/reset-password` without `token`, or after a 422 `token`, then the alert "This reset link is invalid or has expired." and a "Request a new link" link to `/forgot-password?email=...` are shown.
37. Given `/reset-password?token=t&email=e` or `/set-password?token=t&email=e`, after load the address bar no longer contains `token=`, and the document has `<meta name="referrer" content="no-referrer">`.
38. Given mismatched passwords on `/reset-password`, then "The passwords do not match." shows and no request is sent. On success the app is on `/login?password_reset=1` with the success banner.
39. Given a 390px wide viewport, on `/login`, `/forgot-password` and `/reset-password` there is no horizontal scroll, inputs and the primary button are full card width, and a 60-character email wraps inside the card.

**Accounting review (findings 1-7)**

40. Given `AUTH_SUPER_ADMIN_EMAIL_RESET=false` (the default) and Super Admins A and B, when A's email is requested, then the response is the same 202 as for any other email, no `PasswordResetLinkNotification` is sent, `auth.password_reset_refused` with `reason: super_admin_email_reset_disabled` is written, and B gets the R13c alert (A does not).
41. Given a reset token made for Super Admin A while the switch was on, when the switch is turned off and N2 is called with it, then 422 with the standard `token` message, and the password is unchanged.
42. Given the switch on and only one active Super Admin, when they request a reset, then no link is sent and `auth.password_reset_refused` with `reason: no_other_super_admin` is written. With the switch on and a second active Super Admin, AC 1 and AC 20 apply.
43. Given `php artisan auth:reset-password u@x.com` with a password that meets the policy typed twice, then the password changes, every API token and the reset token row are gone, login locks for that email are cleared, and `auth.password_reset` is written with `via: console`, `tokens_revoked` and a null actor. `PasswordChangedNotification` is sent, and the R13b alert when it applies. Given `abcdefgh`, or two different entries, then it exits 1 and nothing changes. Given an unknown email, it exits 1.
44. Given the queue on `database`, when N1 is called from IP `10.1.2.3` with user agent `UA-test` and the job is processed later by a worker, then the `auth.password_reset_requested` row (and a `refused` row likewise) has ip `10.1.2.3` and user agent `UA-test`, not null and not the worker's.
45. Given an admin (Super Admin or Branch Admin) changes a user's email from `old@x.com` to `new@x.com`, then `EmailChangedNotification` is sent to `old@x.com` naming the new address and the admin, and a reset link made for `old@x.com` no longer works (AC 26).

## 9. Open questions (with assumptions)
1. **Super Admin reset by email.** A compromised Super Admin mailbox is a full takeover. **Decided (accounting review):** off by default with `AUTH_SUPER_ADMIN_EMAIL_RESET=false` until AG-005 2FA ships; recovery is the console command N6 (R11). Revisit with AG-005.
2. **Admin alerts scope.** **Decided:** alerts only for Super Admin and branch administrator resets (R13b) and refused Super Admin requests (R13c); other resets are in the audit log only. Branch Admins are not told about staff resets; the user's own "password changed" email is the control.
3. **G2 per-account lock.** **Decided:** keep `email|ip`, no per-email hard lock. Optional later item: `auth.suspicious` detection and alert (section 7, G2).
4. **Change password while signed in** (bootstrap Super Admin first-login change). **Decided:** out of scope. The bootstrap Super Admin uses the console command N6. The shared policy is ready for a later item.
5. **Expired token rows.** Rows stay in `password_reset_tokens` until replaced or used. They are harmless because expiry is checked on use and they hold only a hash and an email. **Decided:** no scheduler job now; add `auth:clear-resets` when a scheduler is set up.
6. **Invitation page Referer and token in URL.** **Decided:** the 4.3 link safety applies to `/set-password` too, in this item.
7. **Maximum password length.** It is not in the backlog. bcrypt ignores bytes after 72. No controls impact; **assume** no max now and leave it to the reviewer.
8. **Audit rows for unknown emails.** **Decided:** none (R2, N1 job step 2), to keep typed addresses of strangers out of the log. Only `auth.locked_out` records a typed email.

## Accounting review
Reviewer: accountant (pre-development). No money, ledger or period effect. Reviewed for access to accounts that can move money, Super Admin takeover, segregation of duties, alerts, audit and lockout.

**Fine as written:** self-service reset keeps admins out of users' passwords, and there is no admin-set password or admin "send reset link" (good segregation of duties). Single use, 60-minute expiry, revoking all tokens, no enumeration, the R9 set-password restriction, R7 (reset completes an invitation) and keeping unknown emails out of the log (Q8) are all acceptable.

### Findings
1. **MUST: Super Admin email reset is off by default (decides Q1).** Add `auth.super_admin_email_reset` (env `AUTH_SUPER_ADMIN_EMAIL_RESET`, default `false`). When it is off and the email belongs to a Super Admin, the N1 job sends no link and writes `auth.password_reset_refused` `{ email, reason: "super_admin_email_reset_disabled" }`. The N1 response is unchanged (R2). N2 also refuses a Super Admin's token with the standard `token` message, so an old link cannot be used. R11 changes to match. AC: with the switch off, a Super Admin request returns the same 202, no notification is sent, and the refused row is written. With the switch on, AC 1 and AC 20 apply.
2. **MUST: a console recovery path replaces it.** Add `php artisan auth:reset-password {email}`. It asks for the password with hidden input and twice, uses `Password::defaults()`, deletes any reset token row and every API token, clears the login lock keys for the email, and writes `auth.password_reset` `{ user_id, email, via: "console", tokens_revoked }` with `user_id` (actor) null. Then it sends `PasswordChangedNotification` and the R13b alert. It works for any user, but it is the only route for a Super Admin while finding 1 is off. The bootstrap Super Admin replaces `ADMIN_PASSWORD` with this command, not with "Forgot password" (R11, last bullet). AC: the command changes the password, revokes tokens, writes the row with `via: console`, and refuses a password that fails the policy.
3. **MUST: audit rows carry the requester's IP and user agent.** `AuditLog::record` reads `request()`, and inside the queued N1 job that is the worker, not the requester. Capture `ip` and `user_agent` in N1, pass them to `SendPasswordResetLink`, and write them on the `requested` and `refused` rows (extend `record()` with optional `ip`/`ua`, or set them on the row). AC: with the queue on `database` and processed later, the `auth.password_reset_requested` row has the N1 request's IP and user agent, not null and not the worker's.
4. **MUST: the actor on `auth.password_reset` is the user.** `record()` takes `user_id` from `auth()->id()`, which is null on a public route. Call `auth()->setUser($user)` before recording, as `setPassword` does. Add to AC 18: the row's `user_id` column equals the reset user's id, and `auditable` is that user.
5. **MUST: tell the old address when a sign-in email changes.** An admin who can edit users can set an Accountant's or Cashier's email to a mailbox they control, request a reset, and then record or void transactions under that person's name. Today only Super Admins are alerted (and only when a non-Super Admin made the change). On E4, when `email` changes, queue an "Your sign-in email was changed to {new} by {admin}" mail to the **old** address. This also applies when a Super Admin makes the change. AC: after an email change, the old address gets the notification and the reset link for the old email no longer works (AC 26).
6. **SHOULD: when the switch in finding 1 is on, refuse if the Super Admin is the only active Super Admin.** The R13b alert then has no independent recipient, and only Super Admins can read `auth.*` rows, so the attacker would be the only reviewer. Refuse with `reason: "no_other_super_admin"`. AC: with one active Super Admin and the switch on, no link is sent.
7. **SHOULD: alert other Super Admins about refused Super Admin requests.** Send the R13b alert ("Password reset requested for Super Admin {email}, refused") on the refused row in finding 1. The per-email throttle limits it to 3 per 15 minutes. It is an early sign that someone is targeting the account.
8. **SHOULD: keep plain tokens out of logs at rest.** Production must not run `MAIL_MAILER=log`, because the link is written to `laravel.log` (R15). Add this to the go-live note. The queued notification payload in `jobs` and `failed_jobs` holds the plain token, which is acceptable because it expires in 60 minutes, but `failed_jobs` must not be shown in any admin screen.
9. **SHOULD: apply R4.3 link safety to `/auth/set-password` in this item (decides Q6).** An invitation link sets up an account that may have money permissions. The change is two lines.
10. **Lockout design is fine.** G1 (lock for 15 minutes from the 5th failure), G4 (one `auth.locked_out` per lock) and clearing only the request IP's keys after a reset are correct. See Q3 for the per-email question.

### Answers to open questions
- **Q1:** Not allowed by default. `AUTH_SUPER_ADMIN_EMAIL_RESET=false` until AG-005 2FA ships. Recovery is the console command (finding 2). Server access is a separate control from the mailbox, and anyone with server access can already change the database, so the command adds no new risk. A business with at least two Super Admins may turn the switch on (finding 6). Revisit when AG-005 is done: with 2FA, email reset can be on by default.
- **Q2:** Keep the assumption. Branch Admins are not told about staff resets. The user's own "password changed" email is the control. Revisit if branches ask for it.
- **Q3:** Keep `email|ip`. Do not add a per-email hard lock: it would let anyone lock a cashier out at the till. Optional later item (SHOULD): write `auth.suspicious` and alert Super Admins when one email has 20 or more failures in 15 minutes from any IP. This adds detection without a lock.
- **Q4:** Out of scope accepted. The bootstrap Super Admin uses the console command (finding 2).
- **Q5:** Accepted. Expired rows hold only a hash and an email.
- **Q6:** Apply it now (finding 9).
- **Q7:** No controls impact. Leave it to the reviewer.
- **Q8:** Accepted.
