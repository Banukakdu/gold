<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AG-033: a completed reconciliation of one bank account up to statement_date (docs/specs/bank-reconciliation.md).
 * Never deleted: an undo sets status `undone` with who, when and why. Amounts are integer cents.
 */
class BankStatement extends Model
{
    use BranchScoped;

    public const COMPLETED = 'completed';

    public const UNDONE = 'undone';

    protected $guarded = ['id'];

    protected $hidden = ['active_key'];

    protected $casts = ['statement_date' => 'date:Y-m-d', 'completed_at' => 'datetime', 'undone_at' => 'datetime',
        'statement_balance_cents' => 'integer', 'reconciled_cents' => 'integer', 'book_balance_cents' => 'integer',
        'uncleared_receipts_cents' => 'integer', 'uncleared_payments_cents' => 'integer', 'cleared_booked_later_cents' => 'integer',
        'self_count' => 'integer', 'lines_count' => 'integer'];

    public function scopeCompleted(Builder $q): Builder
    {
        return $q->where('status', self::COMPLETED);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    /** AG-033b: the bank statement copy (removed ones included). */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
