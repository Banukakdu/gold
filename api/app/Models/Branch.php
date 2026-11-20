<?php

namespace App\Models;

use App\Services\ChartOfAccounts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'address', 'phone', 'is_main', 'status'];

    protected $casts = ['is_main' => 'boolean'];

    /** Double-entry R1: a new branch gets its chart (inter-branch pair with MAIN, category accounts) in the same DB transaction. */
    protected static function booted(): void
    {
        static::created(fn (Branch $b) => app(ChartOfAccounts::class)->setupBranch($b));
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_default')->withTimestamps();
    }

    public static function main(): self
    {
        return static::where('is_main', true)->firstOrFail();
    }

    /**
     * Every branch row FOR UPDATE, in id order (one lock order everywhere, so no deadlock). The year-end close and
     * reopen, and period lock / unlock, all take it first, so they run one at a time (settings spec R8 / R13).
     */
    public static function lockAll(): void
    {
        static::query()->orderBy('id')->lockForUpdate()->get(['id']);
    }
}
