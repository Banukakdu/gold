<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\TwoFactorChallenge;
use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use App\Notifications\TwoFactorAlertNotification;
use App\Notifications\TwoFactorChangedNotification;
use App\Support\BusinessDate;
use App\Support\Minutes;
use App\Support\Settings;
use App\Support\Totp;
use App\Support\TwoFactorPolicy;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * AG-005 two-factor sign-in (docs/specs/two-factor.md). The only writer of the users.two_factor_* columns and of the
 * two_factor_recovery_codes and two_factor_challenges tables.
 *  - R5 replay protection: a TOTP step is used with one conditional UPDATE (only the request that moves
 *    two_factor_last_step forward wins); a recovery code with one conditional UPDATE of used_at.
 *  - R4 challenges: the attempt is counted with a conditional UPDATE BEFORE the code is checked, so parallel guesses
 *    on one challenge cannot pass the limit of 5; a challenge is consumed with UPDATE ... WHERE consumed_at IS NULL in
 *    the transaction that creates the token.
 *  - R6 per-user locks: failures are kept in the cache per user (a short 15-minute list and a rolling 24-hour list,
 *    updated under a cache lock); a lock starts with Cache::add, so exactly one request writes its audit row.
 * No audit row, log line or email holds a secret, a code, a recovery code, a challenge token or a hash of them (R11).
 */
final class TwoFactor
{
    public const SETUP_MINUTES = 15;

    public const VERIFY_MINUTES = 5;

    public const ENROL_MINUTES = 15;

    public const MAX_CHALLENGE_ATTEMPTS = 5;

    public const MAX_STARTS = 5;

    public const SHORT_FAILURES = 10;

    public const SHORT_SECONDS = 15 * 60;

    public const LONG_FAILURES = 30;

    public const LONG_SECONDS = 24 * 60 * 60;

    public const RECOVERY_COUNT = 10;

    public const RECOVERY_ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';

    public const RECENT_LOCK_DAYS = 7;

    public const MAX_LOCK_IPS = 20;

    public const CODE_MESSAGE = "That code didn't work. Check that the time on your phone is set automatically.";

    public const RECOVERY_MESSAGE = 'That recovery code is not valid or has already been used.';

    public const CHALLENGE_MESSAGE = 'Your sign-in has expired. Enter your email and password again.';

    public const SETUP_MESSAGE = 'This setup has expired. Start again.';

    public const PASSWORD_MESSAGE = 'The password is incorrect.';

    public const OFF_MESSAGE = 'Two-factor sign-in is off.';

    public const REQUIRED_MESSAGE = 'Two-factor sign-in is required for your account and cannot be turned off.';

    // ------------------------------------------------------------------ status

    public static function recoveryCodesLeft(User $user): int
    {
        return TwoFactorRecoveryCode::where('user_id', $user->id)->whereNull('used_at')->whereNull('superseded_at')->count();
    }

    /** M1 */
    public static function status(User $user): array
    {
        $pendingUntil = $user->two_factor_pending_secret !== null && $user->two_factor_pending_at
            ? $user->two_factor_pending_at->copy()->addMinutes(self::SETUP_MINUTES) : null;

        return [
            'enabled' => $user->hasTwoFactor(),
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'required' => TwoFactorPolicy::requiredFor($user),
            'recovery_codes_left' => $user->hasTwoFactor() ? self::recoveryCodesLeft($user) : 0,
            'setup_pending_until' => $pendingUntil && $pendingUntil->isFuture() ? $pendingUntil->toIso8601String() : null,
        ];
    }

    // ------------------------------------------------------------------ challenges (R4)

    /** @return array{0: string, 1: CarbonInterface} [plain token, expires at] */
    public static function createChallenge(User $user, string $purpose, Request $request): array
    {
        $token = Str::random(40);
        $expires = now()->addMinutes($purpose === 'enrol' ? self::ENROL_MINUTES : self::VERIFY_MINUTES);
        TwoFactorChallenge::create([
            'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'purpose' => $purpose,
            'expires_at' => $expires, 'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ]);

        return [$token, $expires];
    }

    /**
     * An open challenge of this purpose whose user may still sign in: active, the branch rule (B12), and for `verify`
     * 2FA still on, for `enrol` still off. Anything else is the same 422 challenge_token (R4).
     */
    public static function openChallenge(mixed $token, string $purpose): TwoFactorChallenge
    {
        $c = is_string($token) && $token !== ''
            ? TwoFactorChallenge::where('token_hash', hash('sha256', $token))->first() : null;
        $user = $c?->user;
        $ok = $c && $user && $c->purpose === $purpose && $c->consumed_at === null && $c->expires_at?->isFuture()
            && $c->attempts < self::MAX_CHALLENGE_ATTEMPTS && $user->status === 'active'
            && ($user->is_super_admin || $user->branches()->exists())
            && ($purpose === 'verify' ? $user->hasTwoFactor() : ! $user->hasTwoFactor());
        if (! $ok) {
            throw ValidationException::withMessages(['challenge_token' => self::CHALLENGE_MESSAGE]);
        }

        return $c;
    }

    /** Count the attempt before the code is checked (released again on success). False when the challenge is used up. */
    public static function reserveAttempt(TwoFactorChallenge $c): bool
    {
        return DB::table('two_factor_challenges')->where('id', $c->id)->whereNull('consumed_at')
            ->where('attempts', '<', self::MAX_CHALLENGE_ATTEMPTS)->where('expires_at', '>', now())
            ->update(['attempts' => DB::raw('attempts + 1')]) === 1;
    }

    /** The reserved attempt was not a code check (for example an expired setup). */
    public static function releaseAttempt(TwoFactorChallenge $c): void
    {
        DB::table('two_factor_challenges')->where('id', $c->id)->where('attempts', '>', 0)->update(['attempts' => DB::raw('attempts - 1')]);
    }

    /** R6: 5 wrong codes consume the challenge; the user must enter the password again. */
    public static function wrongAttempt(TwoFactorChallenge $c): void
    {
        DB::table('two_factor_challenges')->where('id', $c->id)->whereNull('consumed_at')
            ->where('attempts', '>=', self::MAX_CHALLENGE_ATTEMPTS)->update(['consumed_at' => now()]);
    }

    /** Inside the transaction that creates the token: only one request consumes it. The successful attempt is released. */
    public static function consumeChallenge(TwoFactorChallenge $c): bool
    {
        return DB::table('two_factor_challenges')->where('id', $c->id)->whereNull('consumed_at')
            ->update(['consumed_at' => now(), 'attempts' => DB::raw('case when attempts > 0 then attempts - 1 else 0 end')]) === 1;
    }

    /** R4 / R14: a reset or a deactivation consumes every open challenge of the user. Returns how many. */
    public static function consumeOpenChallenges(User $user): int
    {
        return DB::table('two_factor_challenges')->where('user_id', $user->id)->whereNull('consumed_at')
            ->where('expires_at', '>', now())->update(['consumed_at' => now()]);
    }

    /** P2: more than 5 setups on one enrol challenge are refused. */
    public static function countStart(TwoFactorChallenge $c): bool
    {
        return DB::table('two_factor_challenges')->where('id', $c->id)->whereNull('consumed_at')
            ->where('starts', '<', self::MAX_STARTS)->update(['starts' => DB::raw('starts + 1')]) === 1;
    }

    // ------------------------------------------------------------------ codes (R5, R7)

    /** R5: the code counts only when this request moves two_factor_last_step forward (one conditional UPDATE). */
    public static function useTotp(User $user, string $code): bool
    {
        $secret = User::whereKey($user->id)->first()?->two_factor_secret;
        $step = $secret ? Totp::match($secret, $code, now()->getTimestamp()) : null;
        if ($step === null) {
            return false;
        }

        return DB::table('users')->where('id', $user->id)->whereNotNull('two_factor_confirmed_at')
            ->where(fn ($w) => $w->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $step))
            ->update(['two_factor_last_step' => $step]) === 1;
    }

    /**
     * R5 / R7: use one recovery code (one conditional UPDATE). Writes auth.two_factor_recovery_used (actor = the user)
     * and emails the user. Returns the codes left, or null when the code is not valid.
     */
    public static function useRecoveryCode(User $user, string $input, ?string $ip, string $context): ?int
    {
        $code = self::normaliseRecoveryCode($input);
        if ($code === null) {
            return null;
        }
        $row = TwoFactorRecoveryCode::where('user_id', $user->id)->whereIn('code_hash', self::recoveryHashes($code))
            ->whereNull('used_at')->whereNull('superseded_at')->first();
        if (! $row) {
            return null;
        }
        $used = DB::table('two_factor_recovery_codes')->where('id', $row->id)->whereNull('used_at')->whereNull('superseded_at')
            ->update(['used_at' => now(), 'used_ip' => $ip]);
        if ($used !== 1) {
            return null;
        }
        $remaining = self::recoveryCodesLeft($user);
        AuditLog::record('auth.two_factor_recovery_used', $user, null, ['remaining' => $remaining, 'context' => $context], null,
            ['user_id' => $user->id]);
        self::tellUser($user, 'recovery_used', $ip, ['remaining' => $remaining, 'context' => $context]);

        return $remaining;
    }

    /** Upper case, no dash or spaces; null unless 10 characters of the recovery alphabet. */
    public static function normaliseRecoveryCode(string $input): ?string
    {
        $code = strtoupper(str_replace(['-', ' '], '', trim($input)));

        return preg_match('/^['.strtoupper(self::RECOVERY_ALPHABET).']{10}$/', $code) ? $code : null;
    }

    /**
     * R7 / accounting review 7: HMAC-SHA256 keyed with a purpose sub-key of APP_KEY, never APP_KEY itself. The first
     * hash uses the current key; the others the APP_PREVIOUS_KEYS, so a key rotation does not cut anyone off.
     *
     * @return list<string>
     */
    public static function recoveryHashes(string $normalised): array
    {
        $keys = array_merge([config('app.key')], (array) config('app.previous_keys', []));

        return array_values(array_unique(array_map(
            fn ($key) => hash_hmac('sha256', $normalised, hash_hmac('sha256', 'two-factor-recovery', self::keyBytes((string) $key))),
            array_filter($keys, fn ($k) => is_string($k) && $k !== ''),
        )));
    }

    private static function keyBytes(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }

    /** @return list<string> 10 new plain codes (xxxxx-xxxxx); only their HMAC is stored. */
    private static function newRecoveryCodes(User $user): array
    {
        $batch = (string) Str::uuid();
        $codes = [];
        while (count($codes) < self::RECOVERY_COUNT) {
            $chars = '';
            for ($i = 0; $i < 10; $i++) {
                $chars .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[substr($chars, 0, 5).'-'.substr($chars, 5)] = true;
        }
        $now = now();
        $rows = [];
        foreach (array_keys($codes) as $plain) {
            $rows[] = ['user_id' => $user->id, 'code_hash' => self::recoveryHashes(self::normaliseRecoveryCode($plain))[0],
                'batch' => $batch, 'created_at' => $now];
        }
        DB::table('two_factor_recovery_codes')->insert($rows);

        return array_keys($codes);
    }

    /** Every unused code of the user stops working (no row is deleted). */
    private static function supersedeCodes(User $user): int
    {
        return DB::table('two_factor_recovery_codes')->where('user_id', $user->id)->whereNull('used_at')->whereNull('superseded_at')
            ->update(['superseded_at' => now()]);
    }

    // ------------------------------------------------------------------ locks (R6)

    /** Seconds left on the user's 2FA lock (short or extended), or null. */
    public static function lockedFor(User $user): ?int
    {
        $now = now()->getTimestamp();
        $left = max((int) Cache::get(self::key($user, 'lock-short'), 0), (int) Cache::get(self::key($user, 'lock-long'), 0)) - $now;

        return $left > 0 ? $left : null;
    }

    public static function lockedResponse(int $seconds): JsonResponse
    {
        return response()->json(['message' => 'Too many wrong codes. Try again in '.Minutes::text($seconds).'.'], 429);
    }

    /** 429 when the user is locked (R6). */
    public static function assertNotLocked(User $user): void
    {
        if ($seconds = self::lockedFor($user)) {
            throw new HttpResponseException(self::lockedResponse($seconds));
        }
    }

    /**
     * R6: one wrong code or step-up password. 10 in 15 minutes start the short lock, 30 in a rolling 24 hours the
     * extended one, each for its period from that moment. The lock row, the email and the alerts are sent once per lock.
     */
    public static function failed(User $user, ?string $ip): void
    {
        $started = Cache::lock(self::key($user, 'fail-mutex'), 10)->block(5, function () use ($user, $ip) {
            $now = now()->getTimestamp();
            $short = array_values(array_filter((array) Cache::get(self::key($user, 'fail-short'), []), fn ($f) => $f[0] > $now - self::SHORT_SECONDS));
            $long = array_values(array_filter((array) Cache::get(self::key($user, 'fail-long'), []), fn ($f) => $f[0] > $now - self::LONG_SECONDS));
            $short[] = [$now, $ip];
            $long[] = [$now, $ip];
            $level = null;
            $failures = [];
            if (count($long) >= self::LONG_FAILURES && Cache::add(self::key($user, 'lock-long'), $now + self::LONG_SECONDS, self::LONG_SECONDS)) {
                [$level, $failures, $long, $short] = ['extended', $long, [], []];
            } elseif (count($short) >= self::SHORT_FAILURES && Cache::add(self::key($user, 'lock-short'), $now + self::SHORT_SECONDS, self::SHORT_SECONDS)) {
                [$level, $failures, $short] = ['short', $short, []];
            }
            Cache::put(self::key($user, 'fail-short'), $short, self::SHORT_SECONDS);
            Cache::put(self::key($user, 'fail-long'), $long, self::LONG_SECONDS);

            return $level ? [$level, $failures, $now] : null;
        });
        if ($started) {
            self::lockStarted($user, ...$started);
        }
    }

    private static function lockStarted(User $user, string $level, array $failures, int $at): void
    {
        $until = now()->setTimestamp($at + ($level === 'extended' ? self::LONG_SECONDS : self::SHORT_SECONDS));
        $ips = array_slice(array_values(array_unique(array_filter(array_column($failures, 1), fn ($ip) => $ip !== null))), 0, self::MAX_LOCK_IPS);
        AuditLog::record('auth.two_factor_locked', $user, null, [
            'level' => $level, 'failures' => count($failures), 'until' => $until->toIso8601String(), 'ips' => $ips,
        ], null, ['user_id' => $user->id]);
        $ip = request()?->ip();
        self::tellUser($user, 'locked', $ip, ['level' => $level]);
        // R12: an admin's lock, and an extended lock of anyone (accounting review 3).
        if ($level === 'extended' || TwoFactorPolicy::isAdmin($user)) {
            AccessAlerts::twoFactor(new TwoFactorAlertNotification('locked', self::who($user), null, [
                'level' => $level, 'until' => $until->copy()->setTimezone(BusinessDate::timezone())->format('Y-m-d H:i'), 'ip' => $ip,
            ]), $user->id);
        }
    }

    /** A successful code clears the 15-minute counter only (the 24-hour one runs out on its own). */
    public static function succeeded(User $user): void
    {
        Cache::forget(self::key($user, 'fail-short'));
    }

    /** R16: a reset clears both locks and both counters. True when a lock was active. */
    private static function clearLocks(User $user): bool
    {
        $active = self::lockedFor($user) !== null;
        foreach (['lock-short', 'lock-long', 'fail-short', 'fail-long'] as $k) {
            Cache::forget(self::key($user, $k));
        }

        return $active;
    }

    private static function key(User $user, string $what): string
    {
        return "two-factor:{$what}:{$user->id}";
    }

    /** The latest auth.two_factor_locked row of the user. */
    public static function latestLock(User $user): ?AuditLog
    {
        return AuditLog::where('event', 'auth.two_factor_locked')->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)->orderByDesc('id')->first();
    }

    /**
     * R16 (accounting review 2): a lock in the last 7 days with no password reset after it started. Someone else may
     * know the password, so 2FA must not be reset until the password is. Returns that lock row, or null.
     */
    public static function resetBlockedBy(User $user): ?AuditLog
    {
        $lock = self::latestLock($user);
        if (! $lock || $lock->created_at->lt(now()->subDays(self::RECENT_LOCK_DAYS))) {
            return null;
        }
        $passwordReset = AuditLog::where('event', 'auth.password_reset')->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)->where('id', '>', $lock->id)->exists();

        return $passwordReset ? null : $lock;
    }

    public static function resetBlockedMessage(AuditLog $lock): string
    {
        return "Someone entered this user's password without the code on ".self::local($lock->created_at)
            .'. Ask them to reset their password first, then reset two-factor.';
    }

    // ------------------------------------------------------------------ step-up (M2-M5, A1, settings)

    /**
     * The current password and, when $second is not 'none', a code ('code') or a code or recovery code ('either').
     * Wrong answers count towards the R6 lock. Returns whether a recovery code was used.
     */
    public static function stepUp(User $user, Request $request, string $second, string $context = 'disable'): bool
    {
        self::assertNotLocked($user);
        $password = $request->input('password');
        if (! is_string($password) || $password === '') {
            throw ValidationException::withMessages(['password' => 'Enter your password.']);
        }
        if (! Hash::check($password, $user->password)) {
            self::failed($user, $request->ip());
            throw ValidationException::withMessages(['password' => self::PASSWORD_MESSAGE]);
        }
        if ($second === 'none') {
            return false;
        }
        [$code, $recovery] = self::secondFactor($request, $second === 'either');
        if ($code !== null) {
            if (! self::useTotp($user, $code)) {
                self::failed($user, $request->ip());
                throw ValidationException::withMessages(['code' => self::CODE_MESSAGE]);
            }
        } elseif (self::useRecoveryCode($user, $recovery, $request->ip(), $context) === null) {
            self::failed($user, $request->ip());
            throw ValidationException::withMessages(['recovery_code' => self::RECOVERY_MESSAGE]);
        }
        self::succeeded($user);

        return $code === null;
    }

    /**
     * Exactly one of `code` (6 digits, spaces removed) or `recovery_code` (only when $allowRecovery).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function secondFactor(Request $request, bool $allowRecovery): array
    {
        $code = $request->input('code');
        $recovery = $allowRecovery ? $request->input('recovery_code') : null;
        $code = is_string($code) || is_int($code) ? str_replace(' ', '', (string) $code) : null;
        $recovery = is_string($recovery) ? trim($recovery) : null;
        $code = $code === '' ? null : $code;
        $recovery = $recovery === '' ? null : $recovery;
        if ($code !== null && $recovery !== null) {
            throw ValidationException::withMessages(['code' => 'Enter the code or a recovery code, not both.']);
        }
        if ($code === null && $recovery === null) {
            throw ValidationException::withMessages(['code' => 'Enter the 6-digit code from your authenticator app.']);
        }
        if ($code !== null && ! preg_match('/^\d{6}$/', $code)) {
            throw ValidationException::withMessages(['code' => 'Enter the 6 digits of the code.']);
        }

        return [$code, $recovery];
    }

    // ------------------------------------------------------------------ setup, confirm (R3)

    /** P2 / M2: a new pending secret replaces any earlier one. 2FA does not change until it is confirmed. */
    public static function startSetup(User $user, string $mode, bool $recoveryUsed): array
    {
        $secret = Totp::generateSecret();
        $at = now();
        $user->forceFill(['two_factor_pending_secret' => $secret, 'two_factor_pending_at' => $at])->save();
        AuditLog::record('auth.two_factor_setup_started', $user, null, ['mode' => $mode, 'recovery_code_used' => $recoveryUsed], null,
            ['user_id' => $user->id]);

        return [
            'secret' => $secret,
            'otpauth_uri' => Totp::uri(app(Settings::class)->companyName(), $user->email, $secret),
            'expires_at' => $at->copy()->addMinutes(self::SETUP_MINUTES)->toIso8601String(),
        ];
    }

    /**
     * P3 / M3: a correct code from the pending secret, within 15 minutes and newer than the last used step (R5), turns
     * 2FA on (with 10 recovery codes) or moves it to the new phone. Every other token of the user is revoked (R10).
     * $inside runs in the same transaction (P3 consumes its challenge and creates the token there).
     *
     * @return array{error: ?string, recovery_codes?: ?list<string>, tokens_revoked?: int, moved?: bool, inside?: mixed}
     */
    public static function confirmSetup(User $user, string $code, bool $forced, ?int $keepTokenId, ?string $ip, ?Closure $inside = null): array
    {
        $result = DB::transaction(function () use ($user, $code, $forced, $keepTokenId, $inside) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->first();
            $pending = $fresh->two_factor_pending_secret;
            if ($pending === null || ! $fresh->two_factor_pending_at || $fresh->two_factor_pending_at->lte(now()->subMinutes(self::SETUP_MINUTES))) {
                return ['error' => 'setup'];
            }
            $step = Totp::match($pending, $code, now()->getTimestamp());
            if ($step === null || ($fresh->two_factor_last_step !== null && $step <= $fresh->two_factor_last_step)) {
                return ['error' => 'code'];
            }
            $moving = $fresh->hasTwoFactor();
            $afterReset = ! $moving && self::firstSetupAfterReset($fresh);
            $fresh->forceFill([
                'two_factor_secret' => $pending, 'two_factor_pending_secret' => null, 'two_factor_pending_at' => null,
                'two_factor_last_step' => $step, 'two_factor_confirmed_at' => $moving ? $fresh->two_factor_confirmed_at : now(),
            ])->save();
            $revoked = $fresh->tokens()->when($keepTokenId !== null, fn ($q) => $q->whereKeyNot($keepTokenId))->delete();
            if ($moving) {
                AuditLog::record('auth.two_factor_moved', $fresh, null, ['tokens_revoked' => $revoked], null, ['user_id' => $fresh->id]);
                $codes = null;
            } else {
                self::supersedeCodes($fresh);
                $codes = self::newRecoveryCodes($fresh);
                AuditLog::record('auth.two_factor_enabled', $fresh, null, [
                    'forced' => $forced, 'recovery_codes' => count($codes), 'tokens_revoked' => $revoked,
                ], null, ['user_id' => $fresh->id]);
            }
            $out = $inside ? $inside($fresh) : null;

            return ['error' => null, 'recovery_codes' => $codes, 'tokens_revoked' => $revoked, 'moved' => $moving,
                'after_reset' => $afterReset, 'inside' => $out];
        });
        if ($result['error'] !== null) {
            return $result;
        }
        $user->refresh();
        self::succeeded($user);
        self::tellUser($user, $result['moved'] ? 'moved' : 'enabled', $ip);
        if ($result['after_reset']) {
            // R12 / accounting review 9: closes the loop on every reset.
            AccessAlerts::twoFactor(new TwoFactorAlertNotification('re_enrolled', self::who($user), null, ['ip' => $ip]), $user->id);
        }

        return $result;
    }

    /** The user had a reset (A1 / C1) and has not turned 2FA on since. */
    private static function firstSetupAfterReset(User $user): bool
    {
        $reset = AuditLog::where('event', 'auth.two_factor_reset')->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)->max('id');

        return $reset !== null && ! AuditLog::where('event', 'auth.two_factor_enabled')->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)->where('id', '>', $reset)->exists();
    }

    // ------------------------------------------------------------------ recovery codes, disable, reset

    /** M4: 10 new codes; every older unused code stops working. */
    public static function regenerate(User $user, ?string $ip): array
    {
        $codes = DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $unused = self::supersedeCodes($user);
            $codes = self::newRecoveryCodes($user);
            AuditLog::record('auth.two_factor_recovery_regenerated', $user, ['unused' => $unused], ['recovery_codes' => count($codes)], null,
                ['user_id' => $user->id]);

            return $codes;
        });
        self::tellUser($user, 'recovery_regenerated', $ip);

        return $codes;
    }

    /** M5: secrets cleared (not kept), recovery codes superseded. Tokens are not changed (R10). */
    public static function disable(User $user, ?string $ip): void
    {
        DB::transaction(function () use ($user) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->first();
            $old = ['confirmed_at' => $fresh->two_factor_confirmed_at?->toIso8601String()];
            self::clearColumns($fresh);
            $superseded = self::supersedeCodes($fresh);
            AuditLog::record('auth.two_factor_disabled', $fresh, $old, ['superseded_codes' => $superseded], null, ['user_id' => $fresh->id]);
        });
        $user->refresh();
        self::tellUser($user, 'disabled', $ip);
        if (TwoFactorPolicy::isAdmin($user)) {
            AccessAlerts::twoFactor(new TwoFactorAlertNotification('disabled', self::who($user), self::who($user), ['ip' => $ip]), $user->id);
        }
    }

    /**
     * A1 / C1 (R16): 2FA off, recovery codes superseded, open challenges consumed, every token revoked and both locks
     * cleared. $actor null = the server console ($console holds os_user and host). The caller has checked R16.
     */
    public static function reset(User $target, ?User $actor, string $reason, array $console = []): int
    {
        $viaConsole = $actor === null;
        [$revoked, $lockActive] = DB::transaction(function () use ($target, $actor, $reason, $console, $viaConsole) {
            $fresh = User::whereKey($target->id)->lockForUpdate()->first();
            $lastLock = self::latestLock($fresh);
            $old = [
                'confirmed_at' => $fresh->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_left' => self::recoveryCodesLeft($fresh),
                'last_locked_at' => $lastLock?->created_at?->toIso8601String(),
            ];
            self::clearColumns($fresh);
            $superseded = self::supersedeCodes($fresh);
            $challenges = self::consumeOpenChallenges($fresh);
            $revoked = $fresh->tokens()->delete();
            $recentLock = $lastLock && $lastLock->created_at->gte(now()->subDays(self::RECENT_LOCK_DAYS));
            $lockActive = self::lockedFor($fresh) !== null || $recentLock;
            $new = ['reason' => $reason, 'via' => $viaConsole ? 'console' : 'app', 'tokens_revoked' => $revoked,
                'superseded_codes' => $superseded, 'challenges_consumed' => $challenges, 'lock_cleared' => $lockActive];
            if ($viaConsole) {
                $new += ['os_user' => $console['os_user'] ?? null, 'host' => $console['host'] ?? null];
            }
            AuditLog::record('auth.two_factor_reset', $fresh, $old, $new, null,
                $viaConsole ? ['ip' => null, 'user_agent' => 'console', 'user_id' => null] : ['user_id' => $actor->id]);

            return [$revoked, $lockActive];
        });
        self::clearLocks($target);
        $target->refresh();
        $ip = $viaConsole ? null : request()?->ip();
        self::tellUser($target, 'reset', $ip, ['by' => $actor?->name]);
        AccessAlerts::twoFactor(new TwoFactorAlertNotification('reset', self::who($target), $actor ? self::who($actor) : null,
            ['reason' => $reason, 'ip' => $ip]), $viaConsole ? $target->id : $actor->id);

        return $revoked;
    }

    private static function clearColumns(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null,
            'two_factor_pending_secret' => null, 'two_factor_pending_at' => null,
        ])->save();
    }

    // ------------------------------------------------------------------ helpers

    /** The id of the request's Sanctum token (kept when other sessions are revoked), or null. */
    public static function currentTokenId(User $user): ?int
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;
    }

    private static function tellUser(User $user, string $kind, ?string $ip, array $details = []): void
    {
        try {
            $user->notify(new TwoFactorChangedNotification($kind, self::local(now()), $ip, $details));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function local(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone(BusinessDate::timezone())->format('Y-m-d H:i').' ('.BusinessDate::timezone().')';
    }

    public static function who(User $user): string
    {
        return "{$user->name} <{$user->email}>";
    }
}
