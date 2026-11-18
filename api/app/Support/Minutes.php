<?php

namespace App\Support;

/** "1 minute" / "N minutes" for a wait in seconds, rounded up, at least 1 (QA Q4). */
final class Minutes
{
    public static function text(int $seconds): string
    {
        $n = max(1, (int) ceil($seconds / 60));

        return $n === 1 ? '1 minute' : "{$n} minutes";
    }
}
