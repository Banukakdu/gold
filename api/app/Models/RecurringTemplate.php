<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AG-066 recurring expense template (docs/specs/recurring-expenses.md): a saved pre-fill of the Record expense form,
 * owned by one branch (R2). It posts nothing (R1); an expense saved from it links back through
 * transactions.expense_template_id. Never deleted: archived and restored (R10). Amounts are integer cents (R12).
 */
class RecurringTemplate extends Model
{
    use BranchScoped;

    public const EXPENSE = 'expense';

    public const FREQUENCIES = ['monthly', 'quarterly', 'yearly', 'none'];

    /** The fields an expense from the template is compared on (R11 template_changes), in this order. */
    public const COMPARED = ['account_id', 'category_id', 'amount', 'payee_payer', 'payment_method', 'description'];

    protected $guarded = ['id'];

    protected $casts = ['amount_cents' => 'integer', 'version' => 'integer', 'archived_at' => 'datetime'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Same branch as the template (R4); read without the scope, like Transaction::account. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /** The expenses saved from the template (same branch). */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'expense_template_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
