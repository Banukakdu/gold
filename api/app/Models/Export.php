<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-087 one background export (docs/specs/background-exports.md section 5). Owned by its requester, not by a branch:
 * it is never read through the branch scope; every read filters on user_id (only the requester sees or downloads it)
 * and access is re-checked against the stored branch context (R2, R6). Never deleted: the model refuses and a BEFORE
 * DELETE trigger refuses too (R15).
 */
class Export extends Model
{
    use HasUlids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const ACTIVE = [self::QUEUED, self::RUNNING];

    public const KIND_REPORT = 'report';

    public const KIND_TRANSACTIONS = 'transactions';

    public const DISK = 'exports';

    protected $guarded = [];

    protected $casts = [
        'params' => 'array', 'branch_ids' => 'array', 'rows' => 'integer', 'size_bytes' => 'integer', 'download_count' => 'integer',
        'attempts' => 'integer', 'branch_id' => 'integer', 'user_id' => 'integer',
        'requested_at' => 'datetime', 'started_at' => 'datetime', 'data_as_at' => 'datetime', 'ready_at' => 'datetime',
        'expires_at' => 'datetime', 'downloaded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new ImmutableRecordException('Export rows cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ready and not yet expired by the clock (expiry is checked at the request, not only by prune, R6). */
    public function isDownloadable(): bool
    {
        return $this->status === self::READY && $this->expires_at !== null && now()->lt($this->expires_at);
    }

    /** The status shown to the user: a ready export past its expiry reads "expired" before prune has run (F5). */
    public function displayStatus(): string
    {
        return $this->status === self::READY && ! $this->isDownloadable() ? self::EXPIRED : $this->status;
    }

    public function partPath(): string
    {
        return "{$this->user_id}/{$this->id}.xlsx.part";
    }

    public function finalPath(): string
    {
        return "{$this->user_id}/{$this->id}.xlsx";
    }

    /** R11: the single branch when exactly one branch is in scope, else null (as report.exported). */
    public function auditBranchId(): ?int
    {
        $ids = (array) $this->branch_ids;

        return count($ids) === 1 ? (int) $ids[0] : null;
    }
}
