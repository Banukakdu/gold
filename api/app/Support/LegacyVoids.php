<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Voids made before contra entries existed (controls spec R6): status `void` and no ledger entries.
 * Their postings were deleted, so they cannot be rebuilt. They are only identified, never fabricated.
 */
final class LegacyVoids
{
    /** @return Collection<int, Transaction> */
    public static function all(): Collection
    {
        return Transaction::withoutGlobalScopes()->with(['branch:id,code', 'account:id,name'])
            ->where('status', 'void')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ledger_entries')->whereColumn('ledger_entries.transaction_id', 'transactions.id'))
            ->orderBy('id')->get();
    }

    /** Writes one `ledger.legacy_voids_identified` row (branch null) when legacy voids exist. */
    public static function recordIdentified(): ?AuditLog
    {
        $ids = self::all()->pluck('id')->all();
        if (! $ids) {
            return null;
        }

        return AuditLog::create([
            'user_id' => null,
            'branch_id' => null,
            'event' => 'ledger.legacy_voids_identified',
            'new_values' => ['count' => count($ids), 'transaction_ids' => $ids],
        ]);
    }
}
