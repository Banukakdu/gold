<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Posted ledger lines are never changed or deleted (controls spec R1). The database refuses it too (triggers).
 * origin: posting | reclass | backfill (double-entry spec). Inserts are checked by the ledger_entries_line_check trigger (D1-D3).
 */
class LedgerEntry extends Model
{
    // Explicit list: a $guarded model caches its table columns per process, which would drop `origin` silently when the
    // model was first used before the double-entry migration added it.
    protected $fillable = ['transaction_id', 'contra_of_id', 'account_id', 'branch_id', 'entry_date', 'debit', 'credit', 'origin'];

    protected $casts = ['entry_date' => 'date:Y-m-d', 'debit' => 'decimal:2', 'credit' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableRecordException('Ledger entries cannot be changed.'));
        static::deleting(fn () => throw new ImmutableRecordException('Ledger entries cannot be deleted.'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withoutGlobalScopes();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function contraOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contra_of_id');
    }
}
