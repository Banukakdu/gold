<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array'];

    public static function record(string $event, ?Model $model = null, ?array $old = null, ?array $new = null, ?int $branchId = null): self
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
            'ip' => $req?->ip(),
            'user_agent' => substr((string) $req?->userAgent(), 0, 255),
        ]);
    }
}
