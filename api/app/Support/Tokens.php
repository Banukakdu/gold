<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** API token housekeeping shared by commands. */
final class Tokens
{
    /** Signs everyone out (AG-093 R10 step 9). Returns how many tokens were revoked. */
    public static function revokeAll(): int
    {
        return DB::table('personal_access_tokens')->delete();
    }
}
