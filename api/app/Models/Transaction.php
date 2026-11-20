<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /** AG-051 R17: the over-commitment warning of a request just made (not a column; the API adds it as `warning`). */
    public ?string $requestWarning = null;

    /** AG-064 R2b: why an expense request was held (threshold | same_day_total), and the same-day total that counted. */
    public ?string $holdReason = null;

    public ?string $sameDayTotal = null;

    protected $casts = ['txn_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'approved_at' => 'datetime',
        'received_on' => 'date:Y-m-d', 'receipt_at' => 'datetime',
        // AG-051 transfer approvals.
        'requested_date' => 'date:Y-m-d', 'requested_on' => 'date:Y-m-d', 'approval_threshold' => 'decimal:2', 'decided_at' => 'datetime'];

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

    /**
     * AG-065: the original of a reversal and the reversal of an original. Without the branch scope: the linked row
     * shares branch_id and related_branch_id with a row already in scope, and only its ref is read.
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id')->withoutGlobalScopes();
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id')->withoutGlobalScopes();
    }

    /**
     * AG-052: who confirmed receipt or reported the discrepancy. Loaded, it serialises as `receipt_by: {id, name}` in
     * place of the id (section 5); `$t->receipt_by` still reads the id.
     */
    public function receiptBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receipt_by');
    }

    /**
     * AG-051: who approved, rejected or cancelled the request (null for expiry). Loaded, it serialises as
     * `decided_by: {id, name}` in place of the id; `$t->decided_by` still reads the id.
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
