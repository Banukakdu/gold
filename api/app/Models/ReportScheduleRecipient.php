<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-088 a user on a schedule's recipient list. Removed by setting `removed_at`, never deleted; adding the same user
 * back creates a new row (section 5).
 */
class ReportScheduleRecipient extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'schedule_id' => 'integer', 'user_id' => 'integer', 'added_by' => 'integer', 'removed_by' => 'integer',
        'added_at' => 'datetime', 'removed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new ImmutableRecordException('Recipient rows cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
