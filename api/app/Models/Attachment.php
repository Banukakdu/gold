<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-062 evidence file of an income or expense entry (docs/specs/attachments.md).
 * Never deleted; the only change allowed is the one-time removal. The database refuses anything else too (triggers).
 */
class Attachment extends Model
{
    use BranchScoped;

    public const DISK = 'attachments';

    public const MAX_ACTIVE = 5;

    public const MAX_TOTAL = 10;

    public const MAX_BYTES = 5_242_880;

    /** Detected type => [mime, canonical extension, label]. */
    public const TYPES = [
        'jpeg' => ['image/jpeg', 'jpg', 'JPG'],
        'png' => ['image/png', 'png', 'PNG'],
        'pdf' => ['application/pdf', 'pdf', 'PDF'],
    ];

    public const REMOVAL_COLUMNS = ['removed_at', 'removed_by', 'removal_reason', 'updated_at'];

    protected $guarded = ['id'];

    protected $hidden = ['disk', 'path'];

    protected $casts = ['removed_at' => 'datetime', 'size_bytes' => 'integer'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new ImmutableRecordException('Attachments cannot be deleted. Remove them instead.'));
        static::updating(function (Attachment $a) {
            $extra = array_diff(array_keys($a->getDirty()), self::REMOVAL_COLUMNS);
            if ($extra || $a->getOriginal('removed_at') !== null) {
                throw new ImmutableRecordException('Attachments cannot be changed; only a one-time removal is allowed.');
            }
        });
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function extension(): string
    {
        foreach (self::TYPES as [$mime, $ext]) {
            if ($mime === $this->mime) {
                return $ext;
            }
        }

        return '';
    }
}
