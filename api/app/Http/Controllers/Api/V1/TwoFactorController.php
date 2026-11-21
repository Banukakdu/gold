<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\TwoFactorChallengeTaken;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Services\TwoFactor;
use App\Support\Minutes;
use App\Support\TwoFactorPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * AG-005 two-factor sign-in (docs/specs/two-factor.md section 5).
 *  - P1-P3: public, challenge-based (throttle `two-factor`). P1 and P3 are the only places that create a token for a
 *    user who has 2FA on or is required (R4).
 *  - M1-M5: the signed-in user's own 2FA (outside `branch`; M1-M3 are on the R9 allow list).
 *  - A1: a Super Admin resets another user's 2FA (`super`, outside `branch`).
 * The rules and every write are in App\Services\TwoFactor.
 */
class TwoFactorController extends Controller
{
    public const SETUPS_PER_WINDOW = 5;

    // ------------------------------------------------------------------ P1-P3

    /** P1: the code (or a recovery code) of a `verify` challenge. */
    public function verify(Request $request)
    {
        $request->validate(['challenge_token' => 'required|string|max:100']);
        [$code, $recovery] = TwoFactor::secondFactor($request, true);
        $challenge = TwoFactor::openChallenge($request->input('challenge_token'), 'verify');
        $user = $challenge->user;
        $ip = (string) $request->ip();
        $reservation = $this->reserve($challenge, $user, $ip);

        $result = $this->attempt($user, $reservation, function () use ($user, $code, $recovery, $challenge, $ip) {
            $remaining = null;
            if ($code !== null) {
                if (! TwoFactor::useTotp($user, $code)) {
                    return null;
                }
            } elseif (($remaining = TwoFactor::useRecoveryCode($user, $recovery, $ip, 'sign_in')) === null) {
                return null;
            }

            return $this->signIn($user, $challenge, $code !== null ? 'totp' : 'recovery') + ['remaining' => $remaining];
        });
        if ($result === null) {
            $this->wrong($challenge, $user, $reservation, $code !== null ? 'code' : 'recovery_code');
        }
        TwoFactor::succeeded($user, $reservation);

        return [
            'token' => $result['token'],
            'user' => AuthController::profile($user->fresh()),
            'recovery_codes_left' => $result['remaining'] ?? TwoFactor::recoveryCodesLeft($user),
            'recovery_code_used' => $code === null,
        ];
    }

    /** P2: start (or restart) the forced set-up of an `enrol` challenge. */
    public function enrolStart(Request $request)
    {
        $request->validate(['challenge_token' => 'required|string|max:100']);
        $challenge = TwoFactor::openChallenge($request->input('challenge_token'), 'enrol');
        $user = $challenge->user;
        TwoFactor::assertNotLocked($user);
        if (! TwoFactor::countStart($challenge)) {
            return response()->json(['message' => 'Too many set-up attempts. Sign in again.'], 429);
        }

        return TwoFactor::startSetup($user, 'forced', false);
    }

    /** P3: confirm the forced set-up; 2FA on, the token issued and the recovery codes returned in one step. */
    public function enrolConfirm(Request $request)
    {
        $request->validate(['challenge_token' => 'required|string|max:100']);
        [$code] = TwoFactor::secondFactor($request, false);
        $challenge = TwoFactor::openChallenge($request->input('challenge_token'), 'enrol');
        $user = $challenge->user;
        $ip = (string) $request->ip();
        $reservation = $this->reserve($challenge, $user, $ip);

        $result = $this->attempt($user, $reservation, fn () => TwoFactor::confirmSetup($user, $code, true, null, $ip,
            fn (User $fresh) => $this->signIn($fresh, $challenge, 'enrolled')));
        if ($result['error'] === 'setup') {
            TwoFactor::releaseAttempt($challenge);
            TwoFactor::refund($user, $reservation);
            throw ValidationException::withMessages(['setup' => TwoFactor::SETUP_MESSAGE]);
        }
        if ($result['error'] === 'code') {
            $this->wrong($challenge, $user, $reservation, 'code');
        }
        TwoFactor::succeeded($user, $reservation);

        return ['token' => $result['inside']['token'], 'user' => AuthController::profile($user->fresh()),
            'recovery_codes' => $result['recovery_codes']];
    }

    /**
     * Review M1: the user's R6 attempt is reserved first (429 when locked or the window is full), then the challenge
     * attempt (R4). Both before the code is checked. Returns the R6 reservation.
     */
    private function reserve(TwoFactorChallenge $challenge, User $user, string $ip): string
    {
        $reservation = TwoFactor::reserve($user, $ip, 'sign_in');
        if (! TwoFactor::reserveAttempt($challenge)) {
            TwoFactor::refund($user, $reservation);
            throw ValidationException::withMessages(['challenge_token' => TwoFactor::CHALLENGE_MESSAGE]);
        }

        return $reservation;
    }

    /**
     * The code check and the sign-in in one transaction. When a parallel request consumed the challenge first, the
     * code use (TOTP step or recovery code) is rolled back with it, the attempt is not counted and the answer is the
     * challenge error.
     */
    private function attempt(User $user, string $reservation, \Closure $work): ?array
    {
        try {
            return DB::transaction($work);
        } catch (TwoFactorChallengeTaken) {
            TwoFactor::refund($user, $reservation);
            throw ValidationException::withMessages(['challenge_token' => TwoFactor::CHALLENGE_MESSAGE]);
        }
    }

    /** Inside the transaction: consume the challenge (only one request wins), then the token and the login row. */
    private function signIn(User $user, TwoFactorChallenge $challenge, string $how): array
    {
        return DB::transaction(function () use ($user, $challenge, $how) {
            if (! TwoFactor::consumeChallenge($challenge)) {
                throw new TwoFactorChallengeTaken;
            }
            $user->forceFill(['last_login_at' => now()])->save();
            auth()->setUser($user);
            AuditLog::record('auth.login', $user, null, ['two_factor' => $how]);

            return ['token' => $user->createToken('web')->plainTextToken];
        });
    }

    /**
     * A wrong code: counted on the challenge (5 consume it) and on the user (R6), then 422. Review N1: when the
     * challenge was consumed meanwhile (a double submit of the right code that the other request used), nothing is
     * counted and the answer is the challenge error.
     */
    private function wrong(TwoFactorChallenge $challenge, User $user, string $reservation, string $field): never
    {
        if (TwoFactorChallenge::whereKey($challenge->id)->whereNotNull('consumed_at')->exists()) {
            TwoFactor::releaseAttempt($challenge);
            TwoFactor::refund($user, $reservation);
            throw ValidationException::withMessages(['challenge_token' => TwoFactor::CHALLENGE_MESSAGE]);
        }
        TwoFactor::wrongAttempt($challenge);
        TwoFactor::failed($user, $reservation);
        throw ValidationException::withMessages([$field => $field === 'code' ? TwoFactor::CODE_MESSAGE : TwoFactor::RECOVERY_MESSAGE]);
    }

    // ------------------------------------------------------------------ M1-M5

    /** M1 */
    public function status(Request $request)
    {
        return TwoFactor::status($request->user());
    }

    /** M2: password (and, when 2FA is on, a code or a recovery code), then a pending secret. */
    public function setup(Request $request)
    {
        $user = $request->user();
        TwoFactor::assertNotLocked($user);
        $key = 'two-factor-setup:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::SETUPS_PER_WINDOW)) {
            return response()->json(['message' => 'Too many set-ups. Try again in '.Minutes::text(RateLimiter::availableIn($key)).'.'], 429);
        }
        RateLimiter::hit($key, TwoFactor::SETUP_MINUTES * 60);
        $moving = $user->hasTwoFactor();
        $recoveryUsed = TwoFactor::stepUp($user, $request, $moving ? 'either' : 'none', 'move');
        $mode = $moving ? 'move' : (TwoFactorPolicy::requiredFor($user) ? 'forced' : 'enable');

        return TwoFactor::startSetup($user, $mode, $recoveryUsed);
    }

    /** M3: a code from the pending secret turns 2FA on or completes the move. */
    public function confirm(Request $request)
    {
        $user = $request->user();
        TwoFactor::assertNotLocked($user);
        [$code] = TwoFactor::secondFactor($request, false);
        $forced = ! $user->hasTwoFactor() && TwoFactorPolicy::requiredFor($user);
        $reservation = TwoFactor::reserve($user, $request->ip(), 'step_up');
        $result = TwoFactor::confirmSetup($user, $code, $forced, TwoFactor::currentTokenId($user), (string) $request->ip());
        if ($result['error'] === 'setup') {
            TwoFactor::refund($user, $reservation);
            throw ValidationException::withMessages(['setup' => TwoFactor::SETUP_MESSAGE]);
        }
        if ($result['error'] === 'code') {
            TwoFactor::failed($user, $reservation);
            throw ValidationException::withMessages(['code' => TwoFactor::CODE_MESSAGE]);
        }
        TwoFactor::succeeded($user, $reservation);

        return ['recovery_codes' => $result['recovery_codes'], 'tokens_revoked' => $result['tokens_revoked']];
    }

    /** M4 */
    public function recoveryCodes(Request $request)
    {
        $user = $request->user();
        if (! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['two_factor' => TwoFactor::OFF_MESSAGE]);
        }
        TwoFactor::stepUp($user, $request, 'code');

        return ['recovery_codes' => TwoFactor::regenerate($user, (string) $request->ip())];
    }

    /** M5 */
    public function disable(Request $request)
    {
        $user = $request->user();
        if (! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['two_factor' => TwoFactor::OFF_MESSAGE]);
        }
        if (TwoFactorPolicy::requiredFor($user)) {
            throw ValidationException::withMessages(['two_factor' => TwoFactor::REQUIRED_MESSAGE]);
        }
        TwoFactor::stepUp($user, $request, 'either', 'disable');
        TwoFactor::disable($user, (string) $request->ip());

        return response()->noContent();
    }

    // ------------------------------------------------------------------ A1

    /** A1: a Super Admin resets another user's 2FA (R16). */
    public function reset(Request $request, int $id)
    {
        $actor = $request->user();
        $user = User::findOrFail($id);
        $request->merge(['reason' => is_string($request->input('reason')) ? trim($request->input('reason')) : $request->input('reason')]);
        $data = $request->validate([
            'reason' => 'required|string|min:10|max:500',
            'password' => 'required|string',
        ], [
            'reason.required' => 'Enter a reason of 10 to 500 characters.',
            'reason.min' => 'Enter a reason of 10 to 500 characters.',
            'reason.max' => 'Enter a reason of 10 to 500 characters.',
        ]);
        if ($actor->id === $user->id) {
            throw ValidationException::withMessages(['user' => 'Use Security to change your own two-factor sign-in.']);
        }
        if ($user->status !== 'active') {
            throw ValidationException::withMessages(['user' => 'This user is inactive.']);
        }
        if (! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['user' => 'Two-factor sign-in is not on for this user.']);
        }
        if ($lock = TwoFactor::resetBlockedBy($user)) {
            throw ValidationException::withMessages(['user' => TwoFactor::resetBlockedMessage($lock)]);
        }
        TwoFactor::stepUp($actor, $request, $actor->hasTwoFactor() ? 'code' : 'none');

        return ['tokens_revoked' => TwoFactor::reset($user, $actor, $data['reason'])];
    }
}
