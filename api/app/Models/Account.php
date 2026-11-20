<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use App\Services\ChartOfAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ledger account of one branch (double-entry spec R1): the money accounts (cash, bank, wallet) and the chart
 * accounts the system creates (category, head office / branch current, owner's capital).
 * current_balance is debit - credit for every class (credit-normal accounts hold negative values) and is written by
 * LedgerService only.
 */
class Account extends Model
{
    use BranchScoped, HasFactory;

    public const MONEY_TYPES = ['cash', 'bank', 'wallet'];

    public const INTERBRANCH_TYPES = ['head_office', 'branch_current'];

    public const CLASSES = ['asset', 'liability', 'equity', 'income', 'expense'];

    protected $fillable = ['branch_id', 'code', 'name', 'type', 'class', 'category_id', 'counterparty_branch_id',
        'bank_name', 'account_no', 'currency', 'is_main_account', 'status'];

    protected $casts = ['is_main_account' => 'boolean', 'current_balance' => 'decimal:2'];

    protected static function booted(): void
    {
        // R1: a cash / bank / wallet account gets the next 11xxx code of its branch when it is created.
        static::creating(function (Account $a) {
            $a->type ??= 'cash';
            if ($a->isMoney()) {
                $a->class = 'asset';
                $a->code ??= app(ChartOfAccounts::class)->nextMoneyCode((int) $a->branch_id);
            }
        });
    }

    public function scopeMoney(Builder $q): Builder
    {
        return $q->whereIn($q->getModel()->getTable().'.type', self::MONEY_TYPES);
    }

    public function isMoney(): bool
    {
        return in_array($this->type, self::MONEY_TYPES, true);
    }

    /** Chart group (S1 / S2 order): asset (money), liability, equity, interbranch, income, expense. */
    public function group(): string
    {
        return in_array($this->type, self::INTERBRANCH_TYPES, true) ? 'interbranch' : $this->class;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function counterpartyBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'counterparty_branch_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** Balance recalculated from the ledger (source of truth). */
    public function ledgerBalance(): string
    {
        return (string) $this->entries()->withoutGlobalScopes()->selectRaw('COALESCE(SUM(debit - credit),0) as b')->value('b');
    }
}
