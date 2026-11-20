<?php

namespace App\Services\Backups;

use App\Models\Account;
use App\Support\LedgerChecks;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * R3a `control.json` (backups review F2): proof that a snapshot's books balanced, from the same LedgerChecks code as
 * `ledger:verify`. Everything is bounded by the max ids read before the dump (R1 step 7), so the figures describe
 * exactly rows the dump holds; `backup:verify` recomputes them on the restored DB with the same bounds.
 */
final class ControlTotals
{
    /** Manifest row counts (R3). */
    public const COUNTED = ['transactions', 'ledger_entries', 'accounts', 'attachments', 'audit_logs', 'users', 'branches'];

    /** @return array<string, int> max id per counted table (0 when empty) */
    public static function maxIds(): array
    {
        $out = [];
        foreach (self::COUNTED as $t) {
            $out[$t] = (int) DB::table($t)->max('id');
        }

        return $out;
    }

    /** @return array<string, int> */
    public static function counts(array $maxIds): array
    {
        $out = [];
        foreach (self::COUNTED as $t) {
            $out[$t] = DB::table($t)->where('id', '<=', (int) ($maxIds[$t] ?? 0))->count();
        }

        return $out;
    }

    public static function compute(array $maxIds): array
    {
        $lineMax = (int) ($maxIds['ledger_entries'] ?? 0);
        $branches = LedgerChecks::sumsByBranch($lineMax);
        $balances = LedgerChecks::accountBalances($lineMax);
        $accounts = Account::withoutGlobalScopes()->with('branch:id,code')->where('id', '<=', (int) ($maxIds['accounts'] ?? 0))->orderBy('id')
            ->get(['id', 'code', 'name', 'branch_id'])
            ->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'branch' => $a->branch?->code, 'balance' => $balances[$a->id] ?? 0])
            ->all();

        return [
            'branches' => $branches,
            'total' => ['debit' => array_sum(array_column($branches, 'debit')), 'credit' => array_sum(array_column($branches, 'credit'))],
            'accounts' => $accounts,
            'max_ids' => array_intersect_key($maxIds, array_flip(['ledger_entries', 'transactions', 'audit_logs'])),
        ];
    }

    /** @return string[] branches (and "All branches") whose debits differ from credits */
    public static function unbalanced(array $control): array
    {
        $out = [];
        foreach ($control['branches'] as $code => $s) {
            if ($s['debit'] !== $s['credit']) {
                $out[] = $code.' (debits '.self::fmt($s['debit']).', credits '.self::fmt($s['credit']).')';
            }
        }
        if ($control['total']['debit'] !== $control['total']['credit']) {
            $out[] = 'All branches (debits '.self::fmt($control['total']['debit']).', credits '.self::fmt($control['total']['credit']).')';
        }

        return $out;
    }

    /**
     * R11 step 6: every difference between the archived control.json and the one recomputed from the restored DB,
     * naming the branch or account. Names and codes are labels only; amounts are compared by branch code and account id.
     *
     * @return string[]
     */
    public static function differences(array $archived, array $restored): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($archived['branches'] ?? []), array_keys($restored['branches'] ?? []))) as $code) {
            $a = $archived['branches'][$code] ?? ['debit' => 0, 'credit' => 0];
            $r = $restored['branches'][$code] ?? ['debit' => 0, 'credit' => 0];
            if ((int) $a['debit'] !== (int) $r['debit'] || (int) $a['credit'] !== (int) $r['credit']) {
                $out[] = "Branch {$code}: archive debits ".self::fmt($a['debit']).' / credits '.self::fmt($a['credit'])
                    .', restored debits '.self::fmt($r['debit']).' / credits '.self::fmt($r['credit']).'.';
            }
        }
        $byId = fn (array $c) => collect($c['accounts'] ?? [])->keyBy('id');
        $aa = $byId($archived);
        $ra = $byId($restored);
        foreach ($aa->keys()->merge($ra->keys())->unique()->sort() as $id) {
            $a = $aa[$id] ?? null;
            $r = $ra[$id] ?? null;
            if ((int) ($a['balance'] ?? 0) !== (int) ($r['balance'] ?? 0) || ($a === null) !== ($r === null)) {
                $x = $a ?? $r;
                $out[] = "Account #{$id} {$x['code']} {$x['name']} ({$x['branch']}): archive ".($a ? self::fmt($a['balance']) : 'missing')
                    .', restored '.($r ? self::fmt($r['balance']) : 'missing').'.';
            }
        }

        return $out;
    }

    private static function fmt(int|string $cents): string
    {
        return Money::format(Money::fromCents((int) $cents));
    }
}
