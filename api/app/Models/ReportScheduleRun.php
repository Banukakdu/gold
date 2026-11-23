<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AG-088 one planned send of a schedule (section 5). It stores the report, filters, period and branch scope it was
 * planned with, so an edit of the schedule never changes it (R11). Kept for ever: the model refuses delete() and the
 * database refuses DELETE and a change to the period, filters or a set sha256.
 */
class ReportScheduleRun extends Model
{
    use HasUlids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const MISSED = 'missed';

    public const ACTIVE = [self::QUEUED, self::RUNNING];

    /** R15 delivery outcome of a settled run (every delivery sent, skipped or failed). */
    public const DELIVERED = 'delivered';

    public const NOT_DELIVERED = 'not_delivered';

    public const NOTHING_SENT = 'nothing_sent';

    protected $guarded = [];

    protected $casts = [
        'params' => 'array', 'branch_ids' => 'array', 'schedule_id' => 'integer', 'owner_id' => 'integer', 'branch_id' => 'integer',
        'rows' => 'integer', 'attached' => 'boolean', 'size_bytes' => 'integer', 'attempts' => 'integer', 'missed_count' => 'integer',
        'planned_at' => 'datetime', 'file_removed_at' => 'datetime', 'data_as_at' => 'datetime', 'started_at' => 'datetime',
        'finished_at' => 'datetime', 'settled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new ImmutableRecordException('Scheduled report runs cannot be deleted.'));
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'schedule_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportScheduleDelivery::class, 'run_id');
    }

    public function periodFrom(): string
    {
        return substr((string) $this->getRawOriginal('period_from'), 0, 10);
    }

    public function periodTo(): string
    {
        return substr((string) $this->getRawOriginal('period_to'), 0, 10);
    }

    public function partPath(): string
    {
        return "scheduled/{$this->id}.pdf.part";
    }

    public function finalPath(): string
    {
        return "scheduled/{$this->id}.pdf";
    }

    /** R13: the branch when exactly one is in scope, else null. */
    public function auditBranchId(): ?int
    {
        $ids = (array) $this->branch_ids;

        return count($ids) === 1 ? (int) $ids[0] : null;
    }
}
