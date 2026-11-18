<?php

namespace App\Models;

use App\Services\ChartOfAccounts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Income / expense category. Each node is a ledger account in every branch where it can be used
 * (double-entry spec R1): ledger_code is assigned on create, and the accounts follow its name and status.
 */
class Category extends Model
{
    use HasFactory;

    protected $fillable = ['parent_id', 'branch_id', 'type', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** QA B1: the DB default, known before the first save, so new category accounts are created active. */
    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::creating(function (Category $c) {
            $c->ledger_code ??= app(ChartOfAccounts::class)->nextCategoryCode($c);
        });
        static::created(fn (Category $c) => app(ChartOfAccounts::class)->createCategoryAccounts($c));
        static::updated(function (Category $c) {
            if ($c->wasChanged(['name', 'is_active'])) {
                app(ChartOfAccounts::class)->syncCategoryAccounts($c);
            }
        });
    }

    /**
     * Categories spec R6: a sub-category is effectively inactive when it or its parent is inactive. Only for choosing a
     * category for new money and for account status; void, reverse, the reclassify source and the year-end close
     * never check it (accounting review finding 4).
     */
    public function isEffectivelyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->parent_id === null || (bool) Category::whereKey($this->parent_id)->value('is_active');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
