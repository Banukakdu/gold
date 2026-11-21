<?php

namespace App\Models;

use App\Services\ChartOfAccounts;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'address', 'phone', 'is_main', 'status'];

    protected $casts = ['is_main' => 'boolean'];

    /** AG-064: the API shows the limit as `expense_approval_threshold` (decimal string), never the cents column. */
    protected $hidden = ['expense_approval_threshold_cents'];

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

    /** AG-064 R2: expenses above this many cents need approval; null = never, 0 = every expense. */
    public function expenseApprovalThresholdCents(): ?int
    {
        $v = $this->getAttribute('expense_approval_threshold_cents');

        return $v === null ? null : (int) $v;
    }

    /** The limit as a decimal string ("50000.00") or null, for the API. */
    public function expenseApprovalThreshold(): ?string
    {
        $c = $this->expenseApprovalThresholdCents();

        return $c === null ? null : Money::fromCents($c);
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
