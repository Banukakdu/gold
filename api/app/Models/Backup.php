<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-093 one backup run (docs/specs/backups.md section 5). Global (no branch). Never deleted: the model refuses and
 * a BEFORE DELETE trigger refuses too. Updates are allowed while the run progresses and for prune / verify results.
 */
class Backup extends Model
{
    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const DAILY = 'daily';

    public const MONTHLY = 'monthly';

    public const YEAR_END = 'year_end';

    public const COPIED = 'copied';

    public const OFFSITE_FAILED = 'failed';

    public const NOT_CONFIGURED = 'not_configured';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime', 'finished_at' => 'datetime', 'local_pruned_at' => 'datetime', 'verified_at' => 'datetime',
        'size_bytes' => 'integer', 'files_count' => 'integer', 'encrypted' => 'boolean', 'manifest' => 'array', 'fiscal_year' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new ImmutableRecordException('Backup rows cannot be deleted.'));
    }

    public function scopeSucceeded(Builder $q): Builder
    {
        return $q->where('status', self::SUCCEEDED);
    }

    public function yearEndClose(): BelongsTo
    {
        return $this->belongsTo(YearEndClose::class);
    }

    public function durationSeconds(): ?int
    {
        return $this->finished_at && $this->started_at ? (int) $this->started_at->diffInSeconds($this->finished_at, true) : null;
    }

    /** "FY 2026-27" for a year-end snapshot. */
    public function fiscalYearLabel(): ?string
    {
        return $this->fiscal_year ? app(Settings::class)->fiscalYearStarting($this->fiscal_year)->label() : null;
    }
}
