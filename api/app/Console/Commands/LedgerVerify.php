<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Support\LegacyVoids;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Controls spec R6 + accounting review finding 6. Run before and after deploy.
 *  - lists legacy voids (status void, no ledger entries) with ref, date, amount, account, voided-by and voided-at,
 *    and flags any without a `transaction.voided` audit row (documentary check only, no correction);
 *  - checks accounts.current_balance == ledger balance for every account. Exits 1 on any difference.
 */
class LedgerVerify extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'List legacy voids and check every cached account balance against the ledger';

    public function handle(): int
    {
        $legacy = LegacyVoids::all();
        $audits = AuditLog::with('user:id,name')->where('event', 'transaction.voided')->where('auditable_type', Transaction::class)
            ->whereIn('auditable_id', $legacy->pluck('id'))->get()->keyBy('auditable_id');

        $this->info("Legacy voids (voided before contra entries; ledger lines were not kept): {$legacy->count()}");
        if ($legacy->isNotEmpty()) {
            $this->table(['Ref', 'Date', 'Amount', 'Account', 'Voided by', 'Voided at', 'Check'], $legacy->map(function (Transaction $t) use ($audits) {
                $a = $audits[$t->id] ?? null;

                return [
                    $t->ref_no ?? "#{$t->id}",
                    $t->txn_date->format('Y-m-d'),
                    Money::format($t->amount),
                    trim(($t->branch->code ?? '').' '.($t->account->name ?? '')),
                    $a?->user?->name ?? '-',
                    $a?->created_at?->setTimezone('Asia/Colombo')->format('Y-m-d H:i') ?? '-',
                    $a ? 'ok' : 'NO transaction.voided AUDIT ROW',
                ];
            })->all());
            $missing = $legacy->reject(fn ($t) => isset($audits[$t->id]))->count();
            if ($missing) {
                $this->warn("{$missing} legacy void(s) have no transaction.voided audit row. Review them before go-live.");
            }
        }

        $bad = 0;
        foreach (Account::withoutGlobalScopes()->with('branch:id,code')->orderBy('id')->get() as $acc) {
            $ledger = $acc->ledgerBalance();
            if (Money::cmp($acc->current_balance, $ledger) !== 0) {
                $bad++;
                $this->error("Account #{$acc->id} {$acc->branch->code} {$acc->name}: current_balance ".Money::add($acc->current_balance).' != ledger '.Money::add($ledger));
            }
        }
        if ($bad) {
            $this->error("{$bad} account balance(s) differ from the ledger.");

            return self::FAILURE;
        }
        $this->info('All account balances match the ledger.');

        return self::SUCCESS;
    }
}
