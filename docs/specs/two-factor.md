# Spec: Two-factor sign-in (AG-005)

## 1. Goal and scope
Backlog **AG-005** (E1, Could, R3, 3 points): "As a Super Admin, I want optional two-factor authentication so that privileged accounts are protected."
- TOTP 2FA can be enabled per user.
- Super Admin can enforce 2FA for all admins.

In scope:
- TOTP (RFC 6238) written in our own code, no PHP package (R1). The setup screen always shows the setup key. It shows a QR code only if a QR library is approved, and the QR is drawn in one web component (S7, Q1).
- Enrolment that is switched on only after a correct code is entered.
- The secret is stored encrypted. Recovery codes are hashed, can be used once, and can be regenerated.
- A second sign-in step. No Sanctum token is issued until the code has been checked (R4).
- Rate limits and lockout, and replay protection: a time step that has been used cannot be used again.
- Turning 2FA off (needs the password and a code), moving to a new phone, and a Super Admin resetting another user's 2FA.
- The setting "Require two-factor sign-in for admins", with forced enrolment.
- How 2FA works with password reset, deactivation and existing tokens.
- An audit row for every change, emails to the user, and alerts to Super Admins.
- A console recovery command.

Out of scope:
- SMS, email codes, WebAuthn/passkeys and push approval.
- "Remember this device".
- Requiring 2FA for all users. The setting covers admins only (section 2).
- Branch Admins resetting 2FA.
- In-app (bell) copies of 2FA emails. Security emails stay email only (notifications spec Q4).
- A general "My profile" or change-password screen. The new `/account/security` page holds 2FA only.
- Changing the AG-002 Super Admin email-reset switch default (Q3).

Reuse what exists:
- `AuthController::login`, `LoginThrottle` and `DummyHash`.
- `AccessAlerts`, `AuditLog::record` (with `$client`), and the `Settings` / `SettingsController::EDITABLE` pattern.
- The `super` middleware and `superGuard`.
- The `.login` card layout, `link-safety.ts`, and the queued `afterCommit` notification pattern.

## 2. Personas and permissions
**No new permission key.** The reset and the setting use the `super` middleware, like settings and period locks. A new catalogue key would be given to every Branch Admin automatically (`PermissionSeeder::run` adds new keys to the `*` template).

| Persona | Own 2FA (enable, codes, disable) | Required when the setting is on | Reset another user's 2FA | Change the setting |
|---|---|---|---|---|
| Super Admin (`users.is_super_admin`) | yes | **yes** | yes (not their own) | yes |
| Branch Admin template (`*`, which includes `user.create`, `user.edit`, `role.manage`) | yes | **yes** | no | no |
| Custom role with any of `user.create`, `user.edit`, `role.manage` | yes | **yes** | no | no |
| Accountant, Cashier, Viewer templates (none of those keys) | yes (optional) | no | no | no |

**Admin (for enforcement, R8).** The user is active and either is a Super Admin, or holds an **active** role in a branch they are a member of (`branch_user` row) whose permissions include **at least one** of `user.create`, `user.edit` or `role.manage`. These are the keys that let someone create logins or change access.
- This set is wider than `BranchAdmins` (which needs both `user.edit` and `role.manage`, for the last-admin rule). Someone who can only create users can still make a second login for themselves (users-and-roles finding 9). See Q2.
- The check runs per user across all their branches, not per active branch. 2FA is about the person, not the branch.
- Implemented once as `App\Support\TwoFactorPolicy::isAdmin(User)` and `requiredFor(User)` (= setting on and `isAdmin`).

Reading the `auth.two_factor_*` audit rows needs `audit.view`. The rows have no branch (`branch_id` null), so only a Super Admin in All branches mode sees them, as with `auth.login` (controls Q5).

Users list and user detail: the 2FA status column, row and reset button are returned and shown **to Super Admins only**. Branch Admins with `user.view` do not see who has 2FA.

## 3. User flows
**F1 Turn on 2FA (any user, signed in; for example the KDY Accountant).**
1. Header: tap the user name, then "Security" (`/account/security`).
2. The card reads "Two-factor sign-in: Off". Tap "Turn on". Enter the password, then tap "Continue".
3. The setup panel (S3) shows:
   - step 1: "Install an authenticator app (for example Google Authenticator, Microsoft Authenticator or 1Password)";
   - step 2: the QR code (if available) and the setup key `ABCD EFGH ...` with a Copy button;
   - step 3: "Enter the 6-digit code from the app".
4. Enter the code and tap "Turn on". 2FA is on. All the user's **other** sessions are signed out (R10). The 10 recovery codes are shown once (S4).
5. Tick "I have saved these codes", then tap "Done". The card reads "On since 20 Nov 2026 10:42".
6. The user gets the email "Two-factor sign-in turned on".

**F2 Sign in with 2FA.**
1. `/login`: enter the email and password and tap "Sign in".
2. The API checks the password, `status`, the branch rule (B12) and the 2FA lock. It returns a **challenge**, not a token.
3. The card changes to "Enter the 6-digit code from your authenticator app". The user types `123456` (it submits on the 6th digit) and is signed in. The default branch is used as today.
4. "Use a recovery code instead" swaps the field to a recovery code (`xxxxx-xxxxx`). After a successful recovery-code sign-in, a toast says "Recovery code used. 9 left." When 3 or fewer are left, a banner on `/account/security` and a toast say "Only N recovery codes left. Create new ones."
5. "Back" returns to step 1 and forgets the challenge.

**F3 Forced enrolment (admin, setting on, 2FA off).**
1. A KDY Branch Admin signs in with the right password. The API returns an **enrol** challenge (no token).
2. The card reads: "Your organisation requires two-factor sign-in for administrators. Set it up now to continue." The setup panel (S3) follows, without asking for the password again.
3. The admin enters a valid code. The API turns 2FA on, issues the token, and returns the recovery codes (S4). After "Done", the app opens the dashboard.
4. If the admin leaves or the enrol challenge expires (15 minutes), nothing is turned on and the next sign-in starts again.

**F4 Admin already signed in when enforcement starts (or who becomes an admin mid-session).**
- Their next API call (except those on the R9 allow list) gets 403 with `code: two_factor_enrolment_required`.
- The app opens `/account/security?required=1`. The shell menu is hidden and only "Log out" is shown.
- The admin turns 2FA on with F1 (password, then code). After "Done", the app reloads `/me` and returns to the dashboard.

**F5 Lost phone, has recovery codes.**
1. Sign in with a recovery code (F2 step 4).
2. Security, then "Move to a new phone". Enter the password and a code from the old app **or** a recovery code.
3. Set up the new app (S3), then confirm with a code from it.
4. The old secret stops working only when the new one is confirmed. The user can then create new recovery codes.

**F6 Lost phone and recovery codes (not a Super Admin).**
1. The user asks a Super Admin.
2. The Super Admin opens Users, then the user, then "Two-factor: On", then "Reset two-factor".
3. The Super Admin enters their own password (and their own code, if they have 2FA), and a reason of 10 to 500 characters, then confirms.
4. The user's 2FA is cleared, every session of the user is signed out, the user is emailed, and the other Super Admins are alerted.
5. At the next sign-in the user goes through F3 if they are an admin and the setting is on. Otherwise they sign in with the password only.

**F7 Turn off 2FA (not required).**
1. Security, then "Turn off". Enter the password and a code or a recovery code, then confirm.
2. 2FA is off and the recovery codes stop working. An email goes to the user.
3. If the user is an admin, the other Super Admins are alerted.
4. A user who is required (R8) sees no "Turn off" button. The API refuses with 422.

**F8 New recovery codes.** Security, then "Create new recovery codes". Enter the password and a code. 10 new codes are shown once (S4), and every older unused code stops working.

**F9 Super Admin turns on enforcement (any switcher mode, including All branches).**
1. Settings, section "Sign-in security".
2. Tick "Require two-factor sign-in for admins".
3. A note shows "6 admins have not set it up yet" with their names. They will be asked to set it up at their next request or sign-in.
4. Save. This is refused if the Super Admin has no 2FA themselves (R8).
5. The other Super Admins are alerted.

**F10 The only Super Admin loses phone and codes.** Someone with server access runs `php artisan auth:two-factor-reset admin@x.com --reason="Lost phone, verified in person"` (section 5, C1).

**Super Admin "All branches" mode.** No difference. `/account/security`, the settings section and the reset on user detail are not branch data and work in any switcher mode. The sign-in steps happen before a branch is chosen.

**Normal branch user (Cashier, KDY).**
- The setting does not affect them.
- They may turn 2FA on in F1 and off in F7.
- If they lose everything, a Super Admin resets it (F6). A Branch Admin cannot.

## 4. Screens
All dates and times are shown in Asia/Colombo with the date-format setting (`appDate` / `colomboDateTime`).

### S1 Login `/login` (change)
Step 1 is unchanged. The response decides what comes next:
- `{token, user}`: as today.
- `two_factor: "challenge"`: show S2.
- `two_factor: "enrol"`: show S3 in enrol mode.

The challenge token is kept **in memory only** (a component signal). It is never stored in localStorage or the URL. A page reload returns to step 1.

### S2 Code step (inside `/login`)
- Title "Two-factor sign-in". Text "Enter the 6-digit code from your authenticator app."

| Field | Type | Required | Validation |
|---|---|---|---|
| Code | `text`, `inputmode="numeric"`, `autocomplete="one-time-code"`, `maxlength=6`, autofocus | yes | exactly 6 digits. Spaces are removed. Auto-submits on the 6th digit. |
| Recovery code (after "Use a recovery code instead") | `text`, `autocomplete="off"`, `autocapitalize="off"` | yes | 10 letters or digits, with or without the dash. Case-insensitive. |

- Buttons: "Verify" (reads "Checking…" and is disabled while the request runs), the link "Use a recovery code instead" / "Use your authenticator app", and the link "Back".
- Errors:
  - 422 `code` or `recovery_code`: shown under the field and the field is cleared. Code: "That code didn't work. Check that the time on your phone is set automatically." Recovery code: "That recovery code is not valid or has already been used."
  - 422 `challenge_token`: return to step 1 with `.alert` "Your sign-in has expired. Enter your email and password again."
  - 429: the API message in `.alert` ("Too many wrong codes. Try again in N minutes."), and the form is disabled.
  - Network or 5xx: "Something went wrong. Try again."

### S3 Setup panel (shared component `TwoFactorSetupComponent`, used in S1 enrol mode, S5 and F4)
1. "1. Install an authenticator app" (text only, no links to stores).
2. "2. Scan this QR code or enter the setup key":
   - `<app-two-factor-qr [uri]="otpauthUri">`, the only place a QR is drawn (S7);
   - always the setup key, in monospace, in groups of 4 (`ABCD EFGH IJKL MNOP QRST UVWX YZ23 4567`), with a "Copy key" button;
   - the line "Account: {email} · Type: time-based".
3. "3. Enter the 6-digit code": the same code field as S2, and the button "Turn on".

- The panel shows "This setup expires at 10:57." (15 minutes). After it expires, the API returns 422 `setup`. Show "This setup has expired. Start again." with a "Start again" button. In enrol mode the button goes to step 1 of login.
- A wrong code shows the S2 code message, and the setup stays open.

### S4 Recovery codes (shown once)
- Title "Save your recovery codes". Text: "Each code works once if you lose your phone. Keep them somewhere safe and private. They will not be shown again."
- The 10 codes in monospace: 2 columns on desktop, 1 column at 390px.
- Buttons: "Copy all", "Download .txt" (file `account-gold-recovery-codes.txt`: the codes, the email and the date, with no secret), and "Print".
- The checkbox "I have saved these codes" (required) enables "Done".
- The browser's leave-page prompt is shown while the box is unticked.
- The codes live only in component memory and are cleared on "Done" or when the user leaves.

### S5 Account security `/account/security` (new, any signed-in user)
- Route guard: signed in only (no permission, no branch mode needed).
- Reached from the header: the user name becomes a menu with "Security" and "Log out".
- With `?required=1` (F4): the shell menu and branch switcher are hidden, and the amber banner "Two-factor sign-in is required for your account. Set it up to continue." is shown.

**Card "Two-factor sign-in"**

| State | Shows | Actions |
|---|---|---|
| Off | "Off. Add a code from your phone when you sign in." If required: amber "Required for your account." | "Turn on": a password dialog, then S3 |
| On | "On since {date time}". "Recovery codes left: N" (amber when 3 or fewer). "Required for your account" tag when required. | "Move to a new phone" (password + code or recovery code, then S3); "Create new recovery codes" (password + code, then S4); "Turn off" (password + code or recovery code, then confirm). "Turn off" is hidden when required. |

- Step-up dialog fields:
  - Password (`type=password`, `autocomplete=current-password`, required);
  - Code (6 digits) or, through the link "Use a recovery code", Recovery code (required where the action needs one).
- Errors:
  - 422 `password` "The password is incorrect.";
  - the code errors from S2;
  - 429 in `.alert`;
  - 422 `two_factor` (for example "Two-factor sign-in is required for your account and cannot be turned off.") in `.alert`.
- Success messages:
  - "Two-factor sign-in is on. Other devices have been signed out.";
  - "New recovery codes created. Your old codes no longer work.";
  - "Two-factor sign-in is off.";
  - "Your new phone is set up."
- No empty state. A failed load shows `.alert` with the API message and a "Retry" button.

### S6 Settings `/settings` (Super Admin, change)
New section "Sign-in security":

| Field | Type | Rules |
|---|---|---|
| Require two-factor sign-in for admins | checkbox | Default off. Help text: "Admins are Super Admins and anyone whose role can create users, edit users or manage roles in any branch. They must set up two-factor sign-in at their next sign-in or request." |

- Under it: "N admins have not set it up yet:" and up to 50 names (`name <email>`, each linking to the user detail), plus "and M more" when there are more. When everyone has it: "Every admin has two-factor sign-in."
- Save uses the existing Settings save, with only the changed keys.
- Error 422 `two_factor_required_for_admins`: "Turn on two-factor sign-in for your own account first." (link to `/account/security`).
- "Last changed by" uses the existing behaviour.

### S7 QR component `web/src/app/features/account/two-factor-qr.component.ts`
- Input: `uri` (the `otpauth://` URI).
- If a QR library is approved, it draws an SVG QR **in the browser** with error-correction level M, at least 180×180 px, white quiet zone, and `alt` "QR code for the setup key".
- If not approved, it renders nothing, and S3 still works with the key.
- No other file imports a QR library. The URI is never sent to any external service (no online QR APIs). The server never makes QR images.

### S8 User detail `/users/:id` and Users list `/users` (Super Admin only, change)
- Users list: a new column "2FA" with "On", "Off" or "Required, off" (amber).
- User detail, profile card:
  - the row "Two-factor sign-in: On since {date} · recovery codes left N", or "Off", or "Off (required: will be asked at next sign-in)";
  - the button "Reset two-factor" (only when On, and not for the Super Admin's own account).
- Dialog "Reset two-factor for {name}":
  - text: "This turns off their two-factor sign-in, removes their recovery codes and signs them out on every device. If they are an admin and two-factor is required, they must set it up again at their next sign-in.";
  - fields: Reason (textarea, required, 10–500), Your password (required), Your code (6 digits, required only if the Super Admin has 2FA on);
  - buttons "Reset" and "Cancel";
  - errors under the fields, or in `.alert` for 403/422.

### S9 Layout at 390px
On S2–S6 at a 390px viewport:
- there is no horizontal scroll;
- inputs and primary buttons are full width;
- the code field is 24px monospace with letter spacing;
- the setup key wraps by groups (`overflow-wrap: anywhere`) and "Copy key" sits under it;
- recovery codes are in one column;
- dialog buttons are stacked and the primary one is on top;
- tap targets are at least 40px tall.

On desktop the cards are at most 560px wide (`.login` card on S1–S4 inside login, 360px as today).

## 5. API
All under `/api/v1`. JSON. Users' 2FA fields are added to `User::$hidden`, so they never appear in `toArray()`.

### Changed: `POST /auth/login`
Steps up to and including the B12 branch check are unchanged (`LoginThrottle`, `DummyHash`, inactive 403). Then:
1. If the 2FA lock for this user is active (R6), return 429 "Too many wrong codes. Try again in N minutes." No challenge is created.
2. If the user has 2FA on: create a `verify` challenge (R4) and return **200** `{ "two_factor": "challenge", "challenge_token": "<40 random chars>", "expires_at": "<ISO>" }`.
3. Else, if `TwoFactorPolicy::requiredFor($user)`: create an `enrol` challenge (15 minutes) and return **200** `{ "two_factor": "enrol", "challenge_token": "...", "expires_at": "..." }`.
4. Else, as today: `{token, user}`, `last_login_at`, and `auth.login`.

In cases 1–3 the password lock counter is cleared, as for a correct password today. `last_login_at` is **not** set, `auth.login` is **not** written, and **no Sanctum token exists**.

### New, public, challenge-based (throttle `two-factor`: 10 per minute per IP)
| ID | Method, path | Request | Response | Errors |
|---|---|---|---|---|
| P1 | `POST /auth/two-factor/verify` | `challenge_token` + exactly one of `code` (6 digits) or `recovery_code` | 200 `{token, user, recovery_codes_left, recovery_code_used: bool}`. Sets `last_login_at`. Writes `auth.login` with new `{two_factor: "totp"\|"recovery"}`. | 422 `challenge_token` "Your sign-in has expired. Enter your email and password again." (unknown, expired, consumed, `enrol` purpose, 5 wrong codes on it, user now inactive or without a branch, or 2FA reset since). 422 `code` / `recovery_code` (S2 texts). 429 (R6). |
| P2 | `POST /auth/two-factor/enrol/start` | `challenge_token` (`enrol` purpose) | 200 `{secret, otpauth_uri, expires_at}`. A new call replaces the pending secret. | 422 `challenge_token`. 429 (more than 5 starts per challenge) |
| P3 | `POST /auth/two-factor/enrol/confirm` | `challenge_token`, `code` | 200 `{token, user, recovery_codes: [10]}`. 2FA on (`forced: true`), the challenge is consumed, `last_login_at` is set, and `auth.two_factor_enabled` and `auth.login` `{two_factor: "enrolled"}` are written. | 422 `challenge_token`, 422 `code`, 422 `setup` "This setup has expired. Start again." 429. |

### New, signed in (`auth:sanctum`, outside `branch`; allowed for non-compliant tokens, R9)
| ID | Method, path | Request | Response | Errors |
|---|---|---|---|---|
| M1 | `GET /me/two-factor` | — | `{enabled, confirmed_at, required, recovery_codes_left, setup_pending_until: ISO\|null}` | — |
| M2 | `POST /me/two-factor/setup` | `password`. When 2FA is already on (moving phone): also `code` or `recovery_code`. | 200 `{secret, otpauth_uri, expires_at}`. Stores the pending secret. When moving phone, a used recovery code is consumed now. | 422 `password`, `code`, `recovery_code`. 429 (R6; and 5 setups per 15 min per user) |
| M3 | `POST /me/two-factor/confirm` | `code` (from the **pending** secret) | 200 `{recovery_codes: [10], tokens_revoked}`. Turn on: revoke every other token of the user (not the current one). Move phone: the old secret is replaced, recovery codes are unchanged, and other tokens are revoked. | 422 `setup` (no pending secret, or older than 15 min), 422 `code`, 429. Moving phone returns `recovery_codes: null`. |
| M4 | `POST /me/two-factor/recovery-codes` | `password`, `code` | 200 `{recovery_codes: [10]}`. Earlier codes get `superseded_at`. | 422 `two_factor` "Two-factor sign-in is off." 422 `password`/`code`. 429 |
| M5 | `POST /me/two-factor/disable` | `password`, `code` or `recovery_code` | 204 | 422 `two_factor` "Two-factor sign-in is required for your account and cannot be turned off." (R8) or "Two-factor sign-in is off.". 422 `password`/`code`/`recovery_code`. 429 |

### New, Super Admin (`auth:sanctum` + `super`, outside `branch`)
| ID | Method, path | Request | Response | Errors |
|---|---|---|---|---|
| A1 | `POST /users/{user}/two-factor/reset` | `reason` (10–500, trimmed), `password` (actor's), `code` (actor's, required when the actor has 2FA on) | 200 `{tokens_revoked}` | 403 "Super Admin only." 404 unknown user. 422 `user` "Use Security to change your own two-factor sign-in." (self). 422 `user` "Two-factor sign-in is not on for this user." 422 `password`/`code`/`reason`. 429 (R6, counted on the actor). |

### Changed endpoints
- **`GET /me`** adds `two_factor: {enabled, required, recovery_codes_left}`.
- **`GET /users`, `GET /users/{user}`**: only when the caller is a Super Admin, each user adds `two_factor: {enabled, confirmed_at, required, recovery_codes_left}`. For everyone else the key is absent.
- **`GET /settings`** adds `two_factor_required_for_admins: bool` and `two_factor_pending_admins: {count, users: [{id, name, email}] (max 50, by name)}`.
- **`PUT /settings`** accepts `two_factor_required_for_admins` (boolean). It is added to `SettingsController::EDITABLE` and stored as `0`/`1`. Turning it **on** needs the actor's own 2FA on (422, S6). It writes the existing `settings.updated` row and then sends the R12 alert.
- **`POST /auth/logout`**: unchanged.
- **AuditLogController**: add the labels in R11. The "Logins" group (`auth.` prefix) covers them.

### Console
- **C1 `php artisan auth:two-factor-reset {email} --reason=`**:
  - a reason of 10–500 characters is required (it asks if the option is missing);
  - an unknown email exits 1 with "No user with this email.", and a user with 2FA off exits 1 with "Two-factor sign-in is not on for this user.";
  - otherwise it does the same as A1 with a null actor and `via: "console"`, then the R12 emails.

### New code
- **`App\Support\Totp`** (pure PHP, no package):
  - `generateSecret(): string`: 20 random bytes (`random_bytes`), Base32 (RFC 4648, no padding), 32 characters.
  - `codeAt(string $secret, int $step): string`: HMAC-SHA1, dynamic truncation, 6 digits, left-padded with zeros.
  - `match(string $secret, string $code, int $now): ?int`: checks steps `t-1, t, t+1` (`t = floor(now/30)`) with `hash_equals`, and returns the matching step or null.
  - `uri(string $issuer, string $email, string $secret): string`: `otpauth://totp/{issuer}:{email}?secret=…&issuer=…&algorithm=SHA1&digits=6&period=30`, URL-encoded. The issuer is the company-name setting, with any `:` removed, cut to 40 characters.
- **`App\Services\TwoFactor`**: enable, confirm, verify (TOTP and recovery code), regenerate, disable and reset. It holds the R5 replay update and the R6 counters. It is the only writer of the 2FA columns and tables.
- **`App\Support\TwoFactorPolicy`**: `isAdmin`, `requiredFor` and `pendingAdmins()` (section 2).
- **Middleware `two-factor`** (`EnsureTwoFactorCompliance`) on the `auth:sanctum` group (R9).
- **Notifications** (queued, `afterCommit`, no secret, code or link with a token):
  - `TwoFactorChangedNotification` (to the user);
  - `TwoFactorAlertNotification` (to Super Admins, through `AccessAlerts::twoFactor(...)`).
- **Rate limiter** `two-factor` in `AppServiceProvider`.

### Schema (migration after `2026_12_03_000000_create_report_schedules_tables.php`)
1. **`users`** adds:
   - `two_factor_secret` text null (`encrypted` cast);
   - `two_factor_confirmed_at` timestamp null;
   - `two_factor_last_step` unsigned bigint null;
   - `two_factor_pending_secret` text null (`encrypted`);
   - `two_factor_pending_at` timestamp null.

   2FA is "on" exactly when `two_factor_confirmed_at` is not null.
2. **`two_factor_recovery_codes`**:
   - `id`, `user_id` (FK users, no cascade);
   - `code_hash` char(64) (HMAC-SHA256 with the app key of the normalised code: upper-case, no dash);
   - `batch` (uuid);
   - `created_at`;
   - `used_at` null, `used_ip` null;
   - `superseded_at` null;
   - index `(user_id, code_hash)`.

   A code is valid when `used_at` and `superseded_at` are both null.
3. **`two_factor_challenges`**:
   - `id`, `user_id` (FK);
   - `token_hash` char(64), unique (sha256 of the plain token);
   - `purpose` (`verify`|`enrol`);
   - `attempts` tinyint default 0, `starts` tinyint default 0;
   - `expires_at`, `consumed_at` null;
   - `ip`, `user_agent`, `created_at`.
4. **`settings`** row `two_factor_required_for_admins` = `0`, inserted where missing.

No column or row is deleted:
- Turning 2FA off or resetting it sets the secret columns to null (the secret must not be kept). The recovery codes get `superseded_at`.
- A consumed challenge gets `consumed_at`.
- `auth:prune-two-factor-challenges` (daily, scheduler) removes challenge rows older than 30 days. These are short-lived sign-in records like `password_reset_tokens`, and the audit log keeps the history (Q5).

## 6. Business rules and edge cases
- **R1 TOTP parameters.**
  - RFC 6238 with SHA1, 6 digits, a 30-second period and T0 = 0.
  - A code is accepted for the step before, the current step and the step after (±30 s).
  - Server time must be NTP-synced (go-live note). The code check uses `now()`, so tests can travel in time.
- **R2 Secret.**
  - 160 bits from `random_bytes`, stored only with Laravel's `encrypted` cast (APP_KEY).
  - It is returned **only** by P2 and M2, while it is pending. After confirmation no endpoint, export, log or audit row ever contains it.
  - Rotating APP_KEY needs `APP_PREVIOUS_KEYS`. Restoring a backup under a different APP_KEY makes every secret unreadable. Users then use recovery codes (which also use the app key) or C1 (go-live note).
- **R3 Enrolment is confirmed.**
  - A pending secret does nothing until a correct code from it is entered within 15 minutes (P3 / M3).
  - Starting setup again replaces the pending secret.
  - Moving phone keeps the old secret working until the new one is confirmed.
  - Confirming sets `two_factor_last_step` to the matched step.
- **R4 No token before the second step.**
  - P1 and P3 are the only places that create a Sanctum token for a user who has 2FA on or is required.
  - The challenge token is 40 random characters, and only its sha256 is stored.
  - A `verify` challenge lives 5 minutes, an `enrol` challenge 15 minutes.
  - A challenge is used once (`consumed_at` is set in the same transaction that creates the token, `UPDATE … WHERE consumed_at IS NULL`, and only one request wins).
  - P1 and P3 check `status = active` and the B12 branch rule again.
  - A reset (A1, C1) or deactivation consumes all open challenges of the user.
- **R5 Replay protection.**
  - A TOTP code is accepted only if its step is greater than `two_factor_last_step`.
  - The update is atomic: `UPDATE users SET two_factor_last_step = :s WHERE id = :id AND (two_factor_last_step IS NULL OR two_factor_last_step < :s)`, and the code counts only when it affects 1 row.
  - So the same code cannot be used twice (two parallel sign-ins, or sign-in then disable), and an older code cannot be used after a newer one.
  - The rule applies to P1, P3, M2–M5 and the actor's code on A1.
  - A recovery code is used with `UPDATE … SET used_at WHERE id = ? AND used_at IS NULL AND superseded_at IS NULL`.
- **R6 Rate limits and lockout.**
  - Per IP: 10 per minute on P1–P3 (`two-factor`), and the existing login limits on `/auth/login`.
  - Per challenge: 5 wrong codes consume it, and the user must enter the password again.
  - Per user: 10 wrong codes or step-up passwords in 15 minutes, across P1–P3, M2–M5 and A1 (as actor), start a 2FA lock for 15 minutes from that moment (`Cache::add`, as `LoginThrottle`).
  - While locked:
    - login with the correct password and every code endpoint for that user return 429 "Too many wrong codes. Try again in N minutes.";
    - a wrong password still gives the normal 422, so the lock says nothing before the password is known.
  - When a lock starts:
    - write one `auth.two_factor_locked` row;
    - email the user "Someone entered your password but not the code. If this was not you, reset your password now.";
    - alert the other Super Admins if the user is an admin (R12).
  - A successful code clears the per-user counter.
  - A password reset (AG-002) does **not** clear the 2FA lock.
- **R7 Recovery codes.**
  - 10 per batch. Format `xxxxx-xxxxx` from `23456789abcdefghjkmnpqrstuvwxyz` (31 characters, about 49 bits).
  - Stored as HMAC-SHA256 only. The plain codes are returned once (P3, M3 first enable, M4) and never again.
  - Typed input is normalised (case and dash ignored).
  - Regenerating supersedes every unused code of the user.
  - Using one sends the user an email "A recovery code was used to sign in. N left."
- **R8 Enforcement.**
  - Setting `two_factor_required_for_admins` (default off, Super Admin only).
  - Admin = section 2. It is checked at login and on every request (R9), so it applies at once to new admins, role changes and role permission edits, without a per-change hook.
  - A required user cannot turn 2FA off (M5 returns 422). They can move phone and regenerate codes.
  - A Super Admin cannot turn the setting on while their own 2FA is off.
  - Turning it off is allowed. 2FA stays on for everyone who has it.
  - Inactive users are never "pending".
- **R9 Existing tokens of a non-compliant user.**
  - Middleware `two-factor`: if `requiredFor(user)` and 2FA is off, every route returns 403 `{message: "Set up two-factor sign-in to continue.", code: "two_factor_enrolment_required"}`, except this allow list:
    - `GET /me`;
    - `POST /auth/logout`;
    - `GET /settings/public` and `GET /settings/logo`;
    - `GET /me/two-factor` and `POST /me/two-factor/setup` / `confirm`.
  - Tokens are not revoked when the setting is turned on, and the user keeps their place after setup.
  - The setting read is memoised per request, so the cost is one settings read when it is off.
  - The web interceptor sends that 403 code to `/account/security?required=1`.
- **R10 Tokens when 2FA changes.**
  - Turn on (M3 first enable, P3): revoke every other token of the user. Sessions made with only the password end.
  - Move phone (M3): revoke every other token.
  - Recovery codes (M4): no change.
  - Turn off (M5): no change.
  - Reset (A1, C1): revoke **all** the user's tokens and consume open challenges.
  - The count goes in the audit row (`tokens_revoked`).
- **R11 Audit.** Every change writes `AuditLog::record`, with `branch_id` null, auditable = the user, and the actor = the signed-in user (or the user themselves on P1/P3 via `auth()->setUser`, or null for C1). No row contains a secret, a code, a recovery code, a challenge token or any hash of them.

| Event | Label | Old | New |
|---|---|---|---|
| `auth.two_factor_setup_started` | 2FA setup started | — | `{mode: "enable"\|"move"\|"forced", recovery_code_used: bool}` |
| `auth.two_factor_enabled` | 2FA turned on | — | `{forced: bool, recovery_codes: 10, tokens_revoked}` |
| `auth.two_factor_moved` | 2FA moved to a new phone | — | `{tokens_revoked}` |
| `auth.two_factor_recovery_regenerated` | Recovery codes replaced | `{unused: n}` | `{recovery_codes: 10}` |
| `auth.two_factor_recovery_used` | Recovery code used | — | `{remaining, context: "sign_in"\|"move"\|"disable"}` |
| `auth.two_factor_disabled` | 2FA turned off | `{confirmed_at}` | `{superseded_codes: n}` |
| `auth.two_factor_reset` | 2FA reset | `{confirmed_at}` | `{reason, via: "app"\|"console", tokens_revoked, superseded_codes}` |
| `auth.two_factor_locked` | 2FA locked | — | `{failures: 10}` |
| `auth.login` (changed) | Login | — | `{two_factor: "totp"\|"recovery"\|"enrolled"\|null}` |
| `settings.updated` (existing) | — | `{two_factor_required_for_admins: false}` | `{… true}` |

  Wrong codes are not written one by one. They are counted, and the lock row is the record.
- **R12 Emails.** Queued, `afterCommit`. A mail failure is reported and never fails the request (as `AccessAlerts`).
  - To the user (`TwoFactorChangedNotification`), for each of: turned on, moved to a new phone, recovery codes replaced, recovery code used (with N left), turned off, reset by {Super Admin name} or "by the server console", and lock started. Each mail has the time (Asia/Colombo) and IP, and the line "If this was not you, contact your administrator at once."
  - To every **other** active Super Admin (`AccessAlerts::twoFactor`):
    - 2FA turned off by an admin;
    - 2FA reset by anyone (target, actor, reason);
    - a lock started for an admin;
    - the setting turned on or off (actor).

    They are not sent for turning 2FA on or moving phone, and nothing goes to Branch Admins.
- **R13 Password reset (AG-002).**
  - A reset changes the password and revokes tokens (existing). It does **not** turn 2FA off or clear the lock. The next sign-in still asks for the code.
  - A user who lost the phone and codes needs F6 or C1.
  - The console `auth:reset-password` does not touch 2FA either.
  - The step-up dialogs check the **current** password.
- **R14 Deactivation and membership.**
  - Deactivating (existing) revokes tokens. It also consumes open 2FA challenges. 2FA settings are kept.
  - Reactivation restores the same 2FA (on or off, same codes).
  - Removing a user's last membership (B12) makes P1/P3 refuse with the challenge error, and login refuses earlier with the B12 message.
  - An inactive user cannot be reset (A1 returns 422 "This user is inactive."). Reactivate first.
- **R15 Invitations.**
  - An invited user sets the password (unchanged), then signs in.
  - If invited into an admin role while the setting is on, they go through F3 at the first sign-in.
  - `last_login_at` is set only after the second step.
- **R16 Super Admin reset rules.**
  - Super Admins only, never their own account (they use M2/M5, or another Super Admin, or C1).
  - A Super Admin may reset another Super Admin. Every other Super Admin is alerted, including the target.
  - The actor proves presence with their password, and their code when their 2FA is on.
  - A reason is required.
- **R17 Email change** (E4, existing). 2FA is unchanged. The label in the user's app keeps the old email (cosmetic). They can "Move to a new phone" to refresh it.
- **R18 Branch isolation.**
  - 2FA data is per user, not per branch. No model here uses `BranchScoped`.
  - Non-Super Admins never see other users' 2FA fields (section 2).
  - The admin check uses `model_has_roles` with each membership's `branch_id` directly (like `BranchAdmins`), not the active branch.
- **R19 Clock and UI.** The UI never computes codes. "Expires at" times come from the API.

## 7. Acceptance criteria
Tests use `Notification::fake()`, a sync queue and `Carbon::setTestNow`. "Code at T" means `Totp::codeAt(secret, floor(T/30))`.

**TOTP core**
1. Given the RFC 6238 SHA1 secret `12345678901234567890` (ASCII, Base32 `GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ`), when `codeAt` is called for times 59, 1111111109, 1111111111, 1234567890, 2000000000 and 20000000000, then it returns `287082`, `081804`, `050471`, `005924`, `279037` and `353130`.
2. Given a secret and time T, then `match` accepts the codes at T-30, T and T+30 and rejects the codes at T-60 and T+60.
3. Given `generateSecret()` is called 100 times, then every result is 32 characters of `[A-Z2-7]` and all are different.
4. Given `uri("Lanka: Traders", "u@x.com", S)`, then it equals `otpauth://totp/Lanka%20Traders:u%40x.com?secret=S&issuer=Lanka%20Traders&algorithm=SHA1&digits=6&period=30`.

**Enrolment**

5. Given a signed-in Cashier with 2FA off, when `POST /me/two-factor/setup` is called with a wrong password, then 422 `password` and no pending secret is stored. With the right password: 200 with `secret`, `otpauth_uri` and `expires_at` = now + 15 minutes. `auth.two_factor_setup_started` is written.
6. Given a pending setup, when `GET /me` is called, then `two_factor.enabled` is false. When the user signs in elsewhere, then login returns `{token}` directly (a pending secret does nothing).
7. Given a pending setup, when `POST /me/two-factor/confirm` gets a wrong code, then 422 `code` and 2FA stays off. With the current code: 200 with 10 recovery codes in the `xxxxx-xxxxx` format, `two_factor_confirmed_at` is set, `auth.two_factor_enabled` `{forced:false, recovery_codes:10, tokens_revoked}` is written, and `TwoFactorChangedNotification` is sent to the user.
8. Given a pending setup older than 15 minutes, when confirm is called with a valid code from it, then 422 `setup` "This setup has expired. Start again."
9. Given the user has 3 tokens and confirms from token A, then tokens B and C get 401, token A still works, and the audit row has `tokens_revoked: 2`.
10. Given 2FA is on, then `users.two_factor_secret` in the database is not the Base32 secret and does not contain it. `decrypt` returns it. No API response after confirmation (`/me`, `/me/two-factor`, `/users/{id}`, `/settings`) contains it.
11. Given 2FA is on, then `two_factor_recovery_codes` holds 10 rows whose `code_hash` differs from every plain code, and no column holds a plain code.

**Sign-in**

12. Given a user with 2FA on, when they log in with the right password, then 200 `{two_factor:"challenge", challenge_token, expires_at}`, the body has no `token`, `personal_access_tokens` has no new row, `last_login_at` is unchanged, and no `auth.login` row exists.
13. Given that challenge, when P1 gets the current code, then 200 with `token` and `user`. The token works on `GET /me`. `auth.login` has `{two_factor:"totp"}`, and `last_login_at` is set.
14. Given a challenge, when P1 is called again with the same `challenge_token` after success, then 422 `challenge_token`.
15. Given a challenge created at T, when P1 is called at T+5 min+1 s, then 422 `challenge_token`.
16. Given an `enrol` challenge, when it is sent to P1, then 422 `challenge_token`. Given a `verify` challenge sent to P2/P3, then 422 `challenge_token`.
17. Given a user with 2FA on and a wrong password, then login returns the normal 422 "Invalid email or password." and no challenge row is created.
18. Given a challenge, when the user is deactivated (or removed from their last branch) before P1, then P1 returns 422 `challenge_token` and no token is created.
19. Given an inactive user with 2FA on and the right password, then login returns the existing 403 "Your account is inactive…" and no challenge is created.

**Replay**

20. Given a code at T was used to sign in, when the same code is sent at T+10 s on a new challenge, then 422 `code`.
21. Given a code for step s+1 was used, when a still-valid code for step s is sent on a new challenge, then 422 `code`.
22. Given two P1 requests with the same valid code on two challenges sent in parallel, then exactly one returns a token and the other returns 422 `code`.
23. Given a code was used to sign in, when the same code is sent to M5 (disable), then 422 `code`.

**Recovery codes**

24. Given an unused recovery code, when P1 gets it as `recovery_code` (upper-case and without the dash), then 200 with `recovery_code_used: true` and `recovery_codes_left: 9`. `auth.two_factor_recovery_used` `{remaining:9, context:"sign_in"}` is written, and the user gets the "recovery code used" email.
25. Given a used recovery code, when it is sent again, then 422 `recovery_code`.
26. Given M4 with the password and a current code, then 200 with 10 new codes, every older unused code returns 422 at P1, and `auth.two_factor_recovery_regenerated` `{unused: n} -> {recovery_codes:10}` is written.
27. Given P1 with both `code` and `recovery_code`, or neither, then 422.

**Rate limits and lockout**

28. Given a challenge, when 5 wrong codes are sent, then each returns 422 `code`, and the 6th request (even with the right code) returns 422 `challenge_token`.
29. Given 10 wrong codes in 15 minutes across three challenges, when the right password is entered at login, then 429 "Too many wrong codes. Try again in 15 minutes." and no challenge is created. Exactly one `auth.two_factor_locked` row is written, the user gets the lock email, and, if the user is an admin, every other active Super Admin gets the alert.
30. Given that lock, when a wrong password is entered at login, then 422 "Invalid email or password." (not 429). At lock start + 15 min + 1 s, the right password returns a challenge.
31. Given 11 P1 requests from one IP in one minute, then the 11th returns 429.
32. Given a locked user who completes a password reset by email, then login with the new password still returns 429 until the lock ends.

**Disable, move phone, reset**

33. Given a user with 2FA on and not required, when M5 gets the right password and a current code, then 204. `two_factor_confirmed_at`, `two_factor_secret` and `two_factor_last_step` are null, every recovery code row has `superseded_at`, no row is deleted, and `auth.two_factor_disabled` is written. Login then returns `{token}` directly. If the user is an admin, the other Super Admins are alerted. A Cashier triggers no alert.
34. Given M5 with a wrong password or a wrong code, then 422 and 2FA stays on.
35. Given a required admin, when M5 is called with valid credentials, then 422 `two_factor` "Two-factor sign-in is required for your account and cannot be turned off." On S5 the "Turn off" button is not shown.
36. Given 2FA on, when M2 gets the password and a recovery code, and M3 then gets a code from the new secret, then the old secret's current code is rejected and the new one is accepted. The recovery codes left go down by 1 and stay otherwise unchanged. `auth.two_factor_setup_started` `{mode:"move", recovery_code_used:true}` and `auth.two_factor_moved` are written, and other tokens are revoked.
37. Given a move started but not confirmed, then the old secret still works at sign-in.
38. Given Super Admin A (2FA on) and Cashier C with 2FA on and 2 tokens, when A calls A1 with a 12-character reason, A's password and A's current code, then 200 `{tokens_revoked:2}`. C's tokens get 401, C's open challenges are consumed, and C's 2FA is off. `auth.two_factor_reset` is written with actor A, auditable C and the reason. C gets the "reset" email naming A, and every other active Super Admin gets the alert.
39. Given A1 with a reason of 9 characters, a wrong password, or a missing code while A has 2FA on, then 422 and nothing changes.
40. Given a KDY Branch Admin, when they call A1, then 403 "Super Admin only.". Given a Super Admin calling A1 on themselves, then 422 `user`.
41. Given `php artisan auth:two-factor-reset sa@x.com --reason="Lost phone, verified in person"`, then the Super Admin's 2FA is off, all their tokens are revoked, and `auth.two_factor_reset` is written with `via:"console"` and a null actor. Given an unknown email or a user with 2FA off, then it exits 1 and nothing changes.

**Enforcement**

42. Given Super Admin S with 2FA off, when `PUT /settings {two_factor_required_for_admins:true}`, then 422 with "Turn on two-factor sign-in for your own account first.". With S's 2FA on: 200, one `settings.updated` row, and the other Super Admins are alerted.
43. Given the setting is on, when users log in with the right password and 2FA off, then:
    - a KDY Branch Admin gets `{two_factor:"enrol"}`;
    - a user whose custom role has only `user.create` gets `enrol`;
    - an Accountant gets `{token}`;
    - a user whose only admin role is **inactive** gets `{token}`.
44. Given an enrol challenge, when P2 then P3 are called with a valid code, then 200 with `token` and 10 `recovery_codes`. `auth.two_factor_enabled` has `forced:true`, and `auth.login` has `two_factor:"enrolled"`.
45. Given an enrol challenge older than 15 minutes, then P2 and P3 return 422 `challenge_token`, and 2FA stays off.
46. Given a Branch Admin with a token from before the setting was turned on, then `GET /transactions` returns 403 with `code:"two_factor_enrolment_required"`. `GET /me`, `POST /auth/logout`, `GET /me/two-factor` and `POST /me/two-factor/setup` still work. After M3 confirms, `GET /transactions` returns 200 with the same token.
47. Given the setting is on and a Cashier with a token is given the KDY Branch Admin role, then their next `GET /transactions` returns 403 `two_factor_enrolment_required`.
48. Given `GET /settings` with the setting on and 3 admins without 2FA (one of them inactive), then `two_factor_pending_admins.count` = 2.
49. Given the setting is turned off, then users who have 2FA keep it, and the required admins from AC 46 get 200 again.

**Visibility, audit, no secrets**

50. Given a KDY Branch Admin with `user.view`, then `GET /users` and `GET /users/{id}` have no `two_factor` key. For a Super Admin, both have `two_factor.enabled`, `required` and `recovery_codes_left`.
51. Given every flow in ACs 5–49 has run, when the `audit_logs`, `jobs` and `notifications` tables and `storage/logs/laravel.log` are searched for any secret, TOTP code, recovery code or challenge token used in the test, then none is found. Every `auth.two_factor_*` row has `branch_id` null, and its label is listed under the audit group "Logins".
52. There is no route that deletes a recovery code, challenge, user or audit row. The `DELETE` method on every new path returns 405.

**UI**

53. Given `/login` for a 2FA user, after the password the code step shows. Typing 6 digits submits without a click. A wrong code clears the field and shows the S2 message. A reload returns to the email and password step, and localStorage holds no challenge token.
54. Given the code step, when "Use a recovery code instead" is tapped, then the recovery field shows. A successful recovery-code sign-in shows the toast "Recovery code used. 9 left."
55. Given S3, then the setup key shows in groups of 4 and "Copy key" copies it without spaces. With no QR library approved, no QR element is rendered and enrolment still completes using the key. Only `two-factor-qr.component.ts` imports a QR library (checked by grep).
56. Given S4, then "Done" is disabled until "I have saved these codes" is ticked. "Download .txt" saves a file with the 10 codes and no secret. After "Done", the codes are not shown anywhere again.
57. Given a 403 `two_factor_enrolment_required`, then the app shows `/account/security?required=1` with the banner, without the shell menu or branch switcher, and with "Log out" visible.
58. Given the Super Admin user detail of a user with 2FA on, then "Reset two-factor" is shown. It is hidden on the Super Admin's own detail and for Branch Admins.
59. Given a 390px viewport, on `/login` (both steps), S3, S4 and `/account/security`, there is no horizontal scroll, inputs and primary buttons are full width, recovery codes are in one column, and the setup key wraps inside the card.

## 8. Open questions (assumption used)
1. **QR library (product owner).** May we add a JS QR library to `web/package.json`?
   - **Assume** the decision is pending. The key is always shown, and the QR lives only in S7.
   - If approved: one small MIT, dependency-free, client-side library, with the lock file committed.
   - Never an online QR service (the secret would leave the system).
2. **Who counts as "admin" (product owner).** **Assume** Super Admins plus anyone with an active role holding any of `user.create`, `user.edit` or `role.manage` (section 2). The alternative is the narrower `BranchAdmins` definition (both `user.edit` and `role.manage`), which would leave users who can only create users unprotected.
3. **AG-002 Super Admin email reset switch (product owner).** The password-reset review said to revisit the default when 2FA ships. **Assume** no change in this item: `AUTH_SUPER_ADMIN_EMAIL_RESET` stays `false` by default. 2FA does not turn off with a password reset (R13), so turning the switch on later is lower risk.
4. **Recovery code hashing.** **Assume** HMAC-SHA256 with the app key, not bcrypt. The codes have about 49 bits of entropy and are rate-limited, and bcrypt would mean up to 10 slow checks per attempt.
5. **Pruning challenge rows.** **Assume** a 30-day prune of `two_factor_challenges`. They are sign-in records like `password_reset_tokens`, not business records, and the audit log keeps the history.
6. **Lock scope.** The 2FA lock is per user, not per IP. Someone who already knows the password could lock the real user out for 15 minutes. **Assume** this is acceptable, because the lock emails the user to change their password and alerts Super Admins about admins.
7. **Turning the setting on with existing sessions.** **Assume** sessions are not revoked. They are limited by the middleware until setup (R9), so nobody loses unsaved work.
