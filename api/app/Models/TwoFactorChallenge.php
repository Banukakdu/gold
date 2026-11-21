<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-005 R4: the second sign-in step (verify) or a forced enrolment (enrol). Only the sha256 of the token is stored.
 * Rows are kept (accounting review 6): a used one gets consumed_at, an expired one is refused by expires_at.
 * Written only by App\Services\TwoFactor.
 */
class TwoFactorChallenge extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'created_at' => 'datetime',
        'attempts' => 'integer', 'starts' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
