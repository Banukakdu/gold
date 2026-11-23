<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AG-088 one scheduled email report (docs/specs/scheduled-reports.md section 5). Not branch-owned: a schedule carries
 * its own branch scope (`branch_ids`, [] = All branches) and only Super Admins manage it, outside the `branch`
 * middleware. Never deleted: paused or archived (the model refuses and a BEFORE DELETE trigger refuses too).
 */
class ReportSchedule extends Model
{
    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const ARCHIVED = 'archived';

    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    /** The four AG-082..085 reports a schedule may send (section 1). */
    public const REPORTS = ['income-expense', 'branch-comparison', 'transfers', 'cash-book'];

    protected $guarded = [];

    protected $casts = [
        'params' => 'array', 'branch_ids' => 'array', 'weekday' => 'integer', 'owner_id' => 'integer', 'created_by' => 'integer',
        'updated_by' => 'integer', 'archived_by' => 'integer', 'consecutive_failures' => 'integer',
        'next_run_at' => 'datetime', 'archived_at' => 'datetime', 'resumed_at' => 'datetime', 'last_failure_planned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new ImmutableRecordException('Report schedules cannot be deleted. Archive them instead.'));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(ReportScheduleRecipient::class, 'schedule_id');
    }

    /** The recipients on the list now (removed_at null). */
    public function currentRecipients(): HasMany
    {
        return $this->recipients()->whereNull('removed_at');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportScheduleRun::class, 'schedule_id');
    }

    /** @return int[] the explicit branch ids ([] = All branches) */
    public function branchIds(): array
    {
        return array_values(array_map('intval', (array) $this->branch_ids));
    }

    /** R1: exactly one branch = branch mode of that branch. */
    public function singleBranchId(): ?int
    {
        $ids = $this->branchIds();

        return count($ids) === 1 ? $ids[0] : null;
    }

    /** R13: the branch when exactly one is in scope, else null (as report.exported). */
    public function auditBranchId(): ?int
    {
        return $this->singleBranchId();
    }
}
