<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read-only once written (AG-090). The database refuses UPDATE/DELETE too (triggers). */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableRecordException('Audit log rows cannot be changed.'));
        static::deleting(fn () => throw new ImmutableRecordException('Audit log rows cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * $ip / $userAgent override the current request's: a queued job runs in the worker, so it passes the requester's
     * values captured in the request (password reset spec R12, accounting review finding 3).
     */
    public static function record(string $event, ?Model $model = null, ?array $old = null, ?array $new = null, ?int $branchId = null,
        ?string $ip = null, ?string $userAgent = null): self
    {
        $req = request();

        return static::create([
            'user_id' => auth()->id(),
            'branch_id' => $branchId ?? $model?->branch_id ?? null,
            'event' => $event,
            'auditable_type' => $model ? $model::class : null,
            'auditable_id' => $model?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip' => $ip ?? $req?->ip(),
            'user_agent' => substr((string) ($userAgent ?? $req?->userAgent()), 0, 255),
        ]);
    }
}
