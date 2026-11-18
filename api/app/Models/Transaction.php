<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use BranchScoped;

    public const CAPITAL = 'capital';

    public const OPENING_ALLOCATION = 'opening_allocation';

    public const FUND_ALLOCATION = 'fund_allocation';

    public const FUND_RETURN = 'fund_return';

    public const BRANCH_TRANSFER = 'branch_transfer';

    public const INTERNAL_TRANSFER = 'internal_transfer';

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    public const REVERSAL = 'reversal';

    /** AG-092 R7: one per branch per year-end close; changes only by reopening the year. */
    public const YEAR_END_CLOSE = 'year_end_close';

    public const TRANSFER_TYPES = [self::OPENING_ALLOCATION, self::FUND_ALLOCATION, self::FUND_RETURN, self::BRANCH_TRANSFER, self::INTERNAL_TRANSFER];

    protected $guarded = ['id'];

    protected $casts = ['txn_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'approved_at' => 'datetime'];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id')->withoutGlobalScopes();
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id')->withoutGlobalScopes();
    }

    /** AG-062 evidence files (income and expense only). */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
