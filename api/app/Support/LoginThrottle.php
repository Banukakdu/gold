<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AG-001 login lockout, keyed by email|ip (password reset spec section 7).
 * G1: the 5th failure within 15 minutes starts a separate 15-minute lock from that moment, so the lock always lasts
 * the full 15 minutes. The IPs seen for an email are remembered so the console recovery command can clear every lock.
 */
final class LoginThrottle
{
    public const MAX_FAILURES = 5;

    public const DECAY_SECONDS = 15 * 60;

    /** Seconds left on the lock, or null when not locked. */
    public static function lockedFor(string $email, string $ip): ?int
    {
        $lock = self::lockKey($email, $ip);

        return RateLimiter::tooManyAttempts($lock, 1) ? max(1, RateLimiter::availableIn($lock)) : null;
    }

    /** Count a failure. Returns true when this failure started a lock (G4: audit it once). */
    public static function fail(string $email, string $ip): bool
    {
        $attempts = self::attemptsKey($email, $ip);
        RateLimiter::hit($attempts, self::DECAY_SECONDS);
        $ips = Cache::get(self::ipsKey($email), []);
        if (! in_array($ip, $ips, true)) {
            $ips[] = $ip;
        }
        Cache::put(self::ipsKey($email), $ips, now()->addSeconds(2 * self::DECAY_SECONDS));

        if (RateLimiter::attempts($attempts) < self::MAX_FAILURES) {
            return false;
        }
        RateLimiter::hit(self::lockKey($email, $ip), self::DECAY_SECONDS);
        RateLimiter::clear($attempts); // a fresh window after the lock ends

        return true;
    }

    public static function clear(string $email, string $ip): void
    {
        RateLimiter::clear(self::attemptsKey($email, $ip));
        RateLimiter::clear(self::lockKey($email, $ip));
    }

    /** Clear the failures and locks of every IP seen for the email (console recovery, accounting review finding 2). */
    public static function clearAll(string $email): void
    {
        foreach (Cache::pull(self::ipsKey($email), []) as $ip) {
            self::clear($email, $ip);
        }
    }

    private static function attemptsKey(string $email, string $ip): string
    {
        return 'login:'.mb_strtolower($email).'|'.$ip;
    }

    private static function lockKey(string $email, string $ip): string
    {
        return 'login-lock:'.mb_strtolower($email).'|'.$ip;
    }

    private static function ipsKey(string $email): string
    {
        return 'login-ips:'.mb_strtolower($email);
    }
}
