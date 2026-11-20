<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-033: a tick on one bank ledger line with its cleared date. Never deleted: an untick sets unmarked_at/by and a
 * later tick adds a new row. Active = unmarked_at null (one per line, unique active_key). Frozen = statement_id points
 * to a `completed` statement.
 */
class BankReconciliationMark extends Model
{
    use BranchScoped;

    protected $guarded = ['id'];

    protected $hidden = ['active_key'];

    protected $casts = ['cleared_date' => 'date:Y-m-d', 'marked_at' => 'datetime', 'unmarked_at' => 'datetime'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull($q->getModel()->getTable().'.unmarked_at');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id');
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'statement_id')->withoutGlobalScopes();
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
