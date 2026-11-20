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
     * $client overrides the current request's ip / user_agent (each key that is present, null included): a queued job
     * runs in the worker, so it passes the requester's values captured in the request (password reset spec R12,
     * accounting review finding 3); the console passes ['ip' => null, 'user_agent' => 'console'] (reviewer N6).
     * `user_id` (when present, null included) replaces the signed-in user: a background export records its requester,
     * and an expired export none (background exports R11).
     *
     * @param  array{ip?: ?string, user_agent?: ?string, user_id?: ?int}|null  $client
     */
    public static function record(string $event, ?Model $model = null, ?array $old = null, ?array $new = null, ?int $branchId = null,
        ?array $client = null): self
    {
        $req = request();

        return static::create([
            'user_id' => $client !== null && array_key_exists('user_id', $client) ? $client['user_id'] : auth()->id(),
            'branch_id' => $branchId ?? $model?->branch_id ?? null,
            'event' => $event,
            'auditable_type' => $model ? $model::class : null,
            'auditable_id' => $model?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip' => $client !== null && array_key_exists('ip', $client) ? $client['ip'] : $req?->ip(),
            'user_agent' => substr((string) ($client !== null && array_key_exists('user_agent', $client) ? $client['user_agent'] : $req?->userAgent()), 0, 255),
        ]);
    }
}
