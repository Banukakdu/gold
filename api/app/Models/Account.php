<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use BranchScoped, HasFactory;

    protected $fillable = ['branch_id', 'name', 'type', 'bank_name', 'account_no', 'currency', 'is_main_account', 'status'];

    protected $casts = ['is_main_account' => 'boolean', 'current_balance' => 'decimal:2'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
