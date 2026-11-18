<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodLock extends Model
{
    protected $fillable = ['branch_id', 'period', 'locked_by'];

    public static function isLocked(int $branchId, string $date): bool
    {
        $period = substr($date, 0, 7);

        return static::where('period', $period)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->exists();
    }
}
