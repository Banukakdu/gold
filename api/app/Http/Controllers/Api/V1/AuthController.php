<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendPasswordResetLink;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\PasswordResets;
use App\Support\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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
            return response()->json(['message' => 'Too many failed attempts. Try again in '.ceil($seconds / 60).' minutes.'], 429);
        }

        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        // G3: an unknown email is checked against a dummy hash, so it takes as long as a known one.
        $valid = $user ? Hash::check($data['password'], $user->password) : Hash::check($data['password'], self::dummyHash());
        if (! $user || ! $valid) {
            if (LoginThrottle::fail($email, $ip)) {
                AuditLog::record('auth.locked_out', $user, null, ['email' => $email]); // G4: once per lock
            }

            return response()->json(['message' => 'Invalid email or password.'], 422);
        }
        if ($user->status !== 'active') {
            return response()->json(['message' => 'Your account is inactive. Contact your administrator.'], 403);
        }

        if (! $user->is_super_admin && ! $user->branches()->exists()) {
            return response()->json(['message' => 'You do not have access to any branch. Contact your administrator.'], 403); // B12
        }

        LoginThrottle::clear($email, $ip);
        $user->forceFill(['last_login_at' => now()])->save();
        auth()->setUser($user);
        AuditLog::record('auth.login', $user);

        return ['token' => $user->createToken('web')->plainTextToken, 'user' => $this->profile($user)];
    }

    /** N5: invited user sets their password with the emailed token (AG-020, 60-minute link, single use). */
    public function setPassword(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'string', PasswordRule::defaults(), 'confirmed'],
        ]);
        $data['email'] = mb_strtolower($data['email']);
        $expired = fn () => ValidationException::withMessages(['token' => 'This link has expired. Ask your administrator to resend the invitation.']);

        // R9: invitations only. A user who has logged in before must use the reset flow (its audit row and alerts).
        $user = User::whereRaw('lower(email) = ?', [$data['email']])->first();
        // A Super Admin is never invited; a reset link of theirs must not get through here while the R11 switch is off.
        if ($user && ($user->last_login_at !== null || ($user->is_super_admin && ! config('auth.super_admin_email_reset')))) {
            throw $expired();
        }

        $status = DB::transaction(fn () => Password::reset($data, function (User $user, string $password) {
            PasswordResets::setPassword($user, $password); // also deletes any API token (none expected)
            auth()->setUser($user);
            AuditLog::record('user.password_set', $user);
        }));
        if ($status !== Password::PASSWORD_RESET) {
            throw $expired();
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
        $data['email'] = mb_strtolower($data['email']);
        $invalid = fn () => ValidationException::withMessages(['token' => PasswordResets::TOKEN_MESSAGE]);

        $user = User::whereRaw('lower(email) = ?', [$data['email']])->first();
        if ($user && ! PasswordResets::emailResetAllowed($user)) {
            throw $invalid(); // inactive (R5) or a Super Admin while email reset is off (R11)
        }

        $status = DB::transaction(fn () => Password::reset($data, function (User $user, string $password) {
            $firstPassword = $user->last_login_at === null; // R7
            $revoked = PasswordResets::setPassword($user, $password);
            auth()->setUser($user); // accounting review finding 4: the actor is the user
            AuditLog::record('auth.password_reset', $user, null, [
                'user_id' => $user->id, 'email' => $user->email, 'first_password' => $firstPassword, 'tokens_revoked' => $revoked,
            ]);
        }));
        if ($status !== Password::PASSWORD_RESET) {
            throw $invalid();
        }

        LoginThrottle::clear($data['email'], (string) $request->ip());
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
        return $this->profile($request->user());
    }

    /** Profile + branches the user can switch to + permissions per branch. */
    private function profile(User $user): array
    {
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
        ];
    }

    /** G3: a bcrypt hash of a random value at the configured cost, made once and kept in the cache. */
    private static function dummyHash(): string
    {
        $key = 'auth:dummy-hash:'.config('hashing.driver').':'.config('hashing.bcrypt.rounds');

        return Cache::rememberForever($key, fn () => Hash::make(Str::random(40)));
    }
}
