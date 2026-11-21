<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AG-005 R7: one recovery code, stored as HMAC-SHA256 only. Valid while used_at and superseded_at are both null.
 * Rows are never deleted. Written only by App\Services\TwoFactor.
 */
class TwoFactorRecoveryCode extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected $casts = ['used_at' => 'datetime', 'superseded_at' => 'datetime', 'created_at' => 'datetime'];
}
