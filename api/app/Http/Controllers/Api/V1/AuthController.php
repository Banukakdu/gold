<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendPasswordResetLink;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\PasswordResets;
use App\Services\TwoFactor;
use App\Support\DummyHash;
use App\Support\LoginThrottle;
use App\Support\Minutes;
use App\Support\TwoFactorPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $email = mb_strtolower($data['email']);
        $ip = (string) $request->ip();

        if ($seconds = LoginThrottle::lockedFor($email, $ip)) {
            return $this->lockedResponse($seconds);
        }
        // Reviewer R2: count first, so parallel guesses cannot all get past the limit during the slow check.
        $attempt = LoginThrottle::hit($email, $ip);
        if ($attempt > LoginThrottle::MAX_FAILURES) {
            $this->lock($email, $ip);

            return $this->lockedResponse(LoginThrottle::lockedFor($email, $ip) ?? LoginThrottle::DECAY_SECONDS);
        }

        $user = User::findByEmail($email);
        // G3: an unknown email is checked against the dummy hash, so it takes as long as a known one.
        $valid = $user ? Hash::check($data['password'], $user->password) : DummyHash::check($data['password']);
        if (! $user || ! $valid) {
            if ($attempt >= LoginThrottle::MAX_FAILURES) {
                $this->lock($email, $ip);
            }

            return response()->json(['message' => 'Invalid email or password.'], 422);
        }
        LoginThrottle::clear($email, $ip);
        if ($user->status !== 'active') {
            return response()->json(['message' => 'Your account is inactive. Contact your administrator.'], 403);
        }

        if (! $user->is_super_admin && ! $user->branches()->exists()) {
            return response()->json(['message' => 'You do not have access to any branch. Contact your administrator.'], 403); // B12
        }

        // AG-005: the 2FA lock (R6), then a challenge instead of a token when 2FA is on or required (R4, R8). No token,
        // no last_login_at and no auth.login row until the second step.
        if ($seconds = TwoFactor::lockedFor($user)) {
            return TwoFactor::lockedResponse($seconds);
        }
        $purpose = match (true) {
            $user->hasTwoFactor() => 'verify',
            TwoFactorPolicy::requiredFor($user) => 'enrol',
            default => null,
        };
        if ($purpose !== null) {
            [$token, $expires] = TwoFactor::createChallenge($user, $purpose, $request);

            return ['two_factor' => $purpose === 'verify' ? 'challenge' : 'enrol', 'challenge_token' => $token,
                'expires_at' => $expires->toIso8601String()];
        }

        $user->forceFill(['last_login_at' => now()])->save();
        auth()->setUser($user);
        AuditLog::record('auth.login', $user, null, ['two_factor' => null]);

        return ['token' => $user->createToken('web')->plainTextToken, 'user' => self::profile($user)];
    }

    /** G4: one auth.locked_out row per lock, written by the one request whose Cache::add started it. */
    private function lock(string $email, string $ip): void
    {
        if (LoginThrottle::lock($email, $ip)) {
            AuditLog::record('auth.locked_out', User::findByEmail($email), null, ['email' => $email]);
        }
    }

    private function lockedResponse(int $seconds)
    {
        return response()->json(['message' => 'Too many failed attempts. Try again in '.Minutes::text($seconds).'.'], 429);
    }

    /** N5: invited user sets their password with the emailed token (AG-020, 60-minute link, single use). */
    public function setPassword(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'string', PasswordRule::defaults(), 'confirmed'],
        ]);
        $user = User::findByEmail($data['email']);
        // R9: invitations only; a user who has logged in before uses the reset flow (its audit row and alerts). A Super
        // Admin is never invited, so their link goes through here only when an email reset would be allowed (R11).
        $allowed = $user && $user->last_login_at === null && (! $user->is_super_admin || PasswordResets::emailResetAllowed($user));

        $ok = PasswordResets::consume($user, mb_strtolower($data['email']), $data['token'], (bool) $allowed, function (User $user) use ($data) {
            PasswordResets::setPassword($user, $data['password']); // also deletes any API token (none expected)
            auth()->setUser($user);
            AuditLog::record('user.password_set', $user);
        });
        if (! $ok) {
            throw ValidationException::withMessages(['token' => 'This link has expired. Ask your administrator to resend the invitation.']);
        }

        return response()->noContent();
    }

    /** N1 (AG-002): always the same 202; the user-dependent work runs in the queued job (R2). */
    public function forgotPassword(Request $request)
    {
        $email = mb_strtolower($request->validate(['email' => 'required|email|max:255'])['email']);
        $job = new SendPasswordResetLink($email, $request->ip(), $request->userAgent());
        if (config('queue.default') === 'sync') {
            // After the response. defer() rather than dispatch()->afterResponse(): its callbacks are forgotten once run,
            // so a long-lived app (tests, Octane) never runs one request's job again after a later request.
            defer(fn () => dispatch_sync($job));
        } else {
            dispatch($job);
        }

        return response()->json(['message' => 'If an account exists for this email, a reset link has been sent.'], 202);
    }

    /** N2 (AG-002): set a new password with the emailed token, revoke every session, then tell the user. */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'string', PasswordRule::defaults(), 'confirmed'],
        ]);
        $user = User::findByEmail($data['email']);
        // Unknown, inactive (R5) and refused Super Admin (R11) all end in the same token error, after the same one check.
        $allowed = $user && PasswordResets::emailResetAllowed($user);

        $ok = PasswordResets::consume($user, mb_strtolower($data['email']), $data['token'], (bool) $allowed, function (User $user) use ($data) {
            $firstPassword = $user->last_login_at === null; // R7
            $revoked = PasswordResets::setPassword($user, $data['password']);
            auth()->setUser($user); // accounting review finding 4: the actor is the user
            AuditLog::record('auth.password_reset', $user, null, [
                'user_id' => $user->id, 'email' => $user->email, 'first_password' => $firstPassword, 'tokens_revoked' => $revoked,
            ]);
        });
        if (! $ok) {
            throw ValidationException::withMessages(['token' => PasswordResets::TOKEN_MESSAGE]);
        }

        LoginThrottle::clear($user->email, (string) $request->ip());
        PasswordResets::notify($user, $request->ip(), 'email');

        return response()->noContent();
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        AuditLog::record('auth.logout', $request->user()); // G4

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return self::profile($request->user());
    }

    /** Profile + branches the user can switch to + permissions per branch, and the user's own 2FA summary (AG-005). */
    public static function profile(User $user): array
    {
        $twoFactor = TwoFactor::status($user);

        $branches = $user->is_super_admin
            ? Branch::orderByDesc('is_main')->orderBy('name')->get()
            : $user->branches()->orderBy('name')->get();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_super_admin' => $user->is_super_admin,
            'branches' => $branches->map(function (Branch $b) use ($user) {
                setPermissionsTeamId($b->id);
                $user->unsetRelation('roles')->unsetRelation('permissions');

                return [
                    'id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'is_main' => $b->is_main,
                    'roles' => $user->getRoleNames()->values(),
                    'permissions' => $user->is_super_admin ? ['*'] : $user->getAllPermissions()->pluck('name')->values(),
                ];
            })->values(),
            'two_factor' => array_intersect_key($twoFactor, array_flip(['enabled', 'required', 'recovery_codes_left'])),
        ];
    }
}
