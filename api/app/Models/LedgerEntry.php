<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['entry_date' => 'date:Y-m-d', 'debit' => 'decimal:2', 'credit' => 'decimal:2'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withoutGlobalScopes();
    }
}
