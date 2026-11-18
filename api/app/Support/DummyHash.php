<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Password reset spec G3 / reviewer R1: when there is nothing real to check (unknown email, no or expired reset row,
 * refused user), the code checks against this hash instead, so every request runs exactly one bcrypt check and the
 * response time does not tell whether an account or a link exists. Made once at the configured cost and cached.
 */
final class DummyHash
{
    public static function value(): string
    {
        $key = 'auth:dummy-hash:'.config('hashing.driver').':'.config('hashing.bcrypt.rounds');

        return Cache::rememberForever($key, fn () => Hash::make(Str::random(40)));
    }

    public static function check(string $value): bool
    {
        Hash::check($value, self::value());

        return false;
    }
}
