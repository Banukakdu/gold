<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AG-001 login lockout, keyed by email|ip (password reset spec section 7).
 * - G1: the 5th failure within 15 minutes starts a separate 15-minute lock from that moment.
 * - Reviewer R2: an attempt is counted BEFORE the slow password check, so parallel guesses cannot all pass the
 *   check-then-count gap; attempts over the limit are refused without a password check.
 * - The lock is written with Cache::add, so exactly one request starts it (G4: one auth.locked_out row per lock).
 * - Reviewer R4: the keys carry a per-email generation; clearAll() bumps it, which drops every IP's counter and lock.
 */
final class LoginThrottle
{
    public const MAX_FAILURES = 5;

    public const DECAY_SECONDS = 15 * 60;

    /** Seconds left on the lock, or null when not locked. */
    public static function lockedFor(string $email, string $ip): ?int
    {
        $until = (int) Cache::get(self::lockKey($email, $ip), 0);
        $left = $until - now()->getTimestamp();

        return $left > 0 ? $left : null;
    }

    /** Count an attempt before checking the password. Returns the attempt number within the window. */
    public static function hit(string $email, string $ip): int
    {
        return RateLimiter::hit(self::attemptsKey($email, $ip), self::DECAY_SECONDS);
    }

    /** Start the lock. True only for the one request that started it (audit it then). */
    public static function lock(string $email, string $ip): bool
    {
        $started = Cache::add(self::lockKey($email, $ip), now()->getTimestamp() + self::DECAY_SECONDS, self::DECAY_SECONDS);
        if ($started) {
            RateLimiter::clear(self::attemptsKey($email, $ip)); // a fresh window after the lock ends
        }

        return $started;
    }

    public static function clear(string $email, string $ip): void
    {
        RateLimiter::clear(self::attemptsKey($email, $ip));
        Cache::forget(self::lockKey($email, $ip));
    }

    /** Clear the counters and locks of every IP for the email (console recovery, accounting review finding 2). */
    public static function clearAll(string $email): void
    {
        $key = self::generationKey($email);
        Cache::add($key, 0);
        Cache::increment($key);
    }

    private static function attemptsKey(string $email, string $ip): string
    {
        return 'login:'.self::generation($email).':'.mb_strtolower($email).'|'.$ip;
    }

    private static function lockKey(string $email, string $ip): string
    {
        return 'login-lock:'.self::generation($email).':'.mb_strtolower($email).'|'.$ip;
    }

    private static function generation(string $email): int
    {
        return (int) Cache::get(self::generationKey($email), 0);
    }

    private static function generationKey(string $email): string
    {
        return 'login-gen:'.mb_strtolower($email);
    }
}
