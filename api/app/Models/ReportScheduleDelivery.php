<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-088 one recipient of one run (section 5). `email` is the address checked at build and the only address the email
 * goes to (accounting review A2); the database refuses a change to it, to the run or to the user, and any DELETE.
 */
class ReportScheduleDelivery extends Model
{
    public const QUEUED = 'queued';

    /** Claimed by a mail job; the mailer is being called (review S1). */
    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer', 'attempts' => 'integer',
        'queued_at' => 'datetime', 'sending_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new ImmutableRecordException('Delivery rows cannot be deleted.'));
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReportScheduleRun::class, 'run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
