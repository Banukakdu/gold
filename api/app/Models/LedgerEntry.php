<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Posted ledger lines are never changed or deleted (controls spec R1). The database refuses it too (triggers). */
class LedgerEntry extends Model
{
    protected $guarded = ['id'];

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

    public function contraOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contra_of_id');
    }
}
