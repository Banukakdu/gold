<?php

namespace App\Services;

use App\Models\Transaction;

/**
 * Double-entry posting rules (docs/specs/double-entry.md R3), as plain line specs in integer cents.
 * Used by LedgerService to post and by the back-fill planner to find the missing side of existing entries.
 *
 * A line spec is ['ref' => account reference, 'branch_id' => int, 'debit' => int, 'credit' => int].
 * Account references: [MONEY, account_id], [CATEGORY, category_id, branch_id], [HEAD_OFFICE, branch_id] (in that
 * branch), [BRANCH_CURRENT, branch_id] (in MAIN, for that branch), [OWNER_CAPITAL] (in MAIN).
 * The money line comes first in each branch's set.
 */
class PostingRules
{
    public const MONEY = 'money';

    public const CATEGORY = 'category';

    public const HEAD_OFFICE = 'head_office';

    public const BRANCH_CURRENT = 'branch_current';

    public const OWNER_CAPITAL = 'owner_capital';

    /** Income: Dr money, Cr income C. Expense: Dr expense C, Cr money (both in the money account's branch). */
    public function incomeExpense(string $type, int $accountId, int $branchId, int $categoryId, int $cents): array
    {
        $cat = [self::CATEGORY, $categoryId, $branchId];

        return $type === Transaction::INCOME
            ? [self::line([self::MONEY, $accountId], $branchId, $cents, 0), self::line($cat, $branchId, 0, $cents)]
            : [self::line([self::MONEY, $accountId], $branchId, 0, $cents), self::line($cat, $branchId, $cents, 0)];
    }

    /**
     * Capital into MAIN: Dr A, Cr Owner's capital. Into an is_main_account A of non-main branch B (4 lines):
     * MAIN Dr BCA(B), Cr Owner's capital; B Dr A, Cr HO in B.
     */
    public function capital(int $accountId, int $branchId, int $mainId, int $cents): array
    {
        if ($branchId === $mainId) {
            return [self::line([self::MONEY, $accountId], $mainId, $cents, 0), self::line([self::OWNER_CAPITAL], $mainId, 0, $cents)];
        }

        return [
            self::line([self::BRANCH_CURRENT, $branchId], $mainId, $cents, 0),
            self::line([self::OWNER_CAPITAL], $mainId, 0, $cents),
            self::line([self::MONEY, $accountId], $branchId, $cents, 0),
            self::line([self::HEAD_OFFICE, $branchId], $branchId, 0, $cents),
        ];
    }

    /**
     * Money from account $from (branch S) to account $to (branch R).
     *  - same branch: Dr to, Cr from;
     *  - MAIN -> B (opening allocation, top-up): MAIN Cr from, Dr BCA(B); B Dr to, Cr HO in B;
     *  - B -> MAIN (fund return): B Cr from, Dr HO in B; MAIN Dr to, Cr BCA(B);
     *  - B -> G (branch transfer, 6 lines): B Cr from, Dr HO in B; MAIN Dr BCA(G), Cr BCA(B); G Dr to, Cr HO in G.
     */
    public function transfer(int $fromId, int $fromBranch, int $toId, int $toBranch, int $mainId, int $cents): array
    {
        if ($fromBranch === $toBranch) {
            return [self::line([self::MONEY, $fromId], $fromBranch, 0, $cents), self::line([self::MONEY, $toId], $toBranch, $cents, 0)];
        }
        $lines = [self::line([self::MONEY, $fromId], $fromBranch, 0, $cents)];
        $lines[] = $fromBranch === $mainId
            ? self::line([self::BRANCH_CURRENT, $toBranch], $mainId, $cents, 0)
            : self::line([self::HEAD_OFFICE, $fromBranch], $fromBranch, $cents, 0);
        if ($fromBranch !== $mainId && $toBranch !== $mainId) {
            $lines[] = self::line([self::BRANCH_CURRENT, $toBranch], $mainId, $cents, 0);
            $lines[] = self::line([self::BRANCH_CURRENT, $fromBranch], $mainId, 0, $cents);
        }
        $lines[] = self::line([self::MONEY, $toId], $toBranch, $cents, 0);
        $lines[] = $toBranch === $mainId
            ? self::line([self::BRANCH_CURRENT, $fromBranch], $mainId, 0, $cents)
            : self::line([self::HEAD_OFFICE, $toBranch], $toBranch, 0, $cents);

        return $lines;
    }

    /** Expense C -> C2: Dr C2, Cr C. Income C -> C2: Dr C, Cr C2 (R3 reclassification). */
    public function reclass(string $type, int $branchId, int $fromCategoryId, int $toCategoryId, int $cents): array
    {
        $from = [self::CATEGORY, $fromCategoryId, $branchId];
        $to = [self::CATEGORY, $toCategoryId, $branchId];

        return $type === Transaction::EXPENSE
            ? [self::line($to, $branchId, $cents, 0), self::line($from, $branchId, 0, $cents)]
            : [self::line($from, $branchId, $cents, 0), self::line($to, $branchId, 0, $cents)];
    }

    /** The same lines with debit and credit swapped (reversal). */
    public static function mirror(array $lines): array
    {
        return array_map(fn ($l) => ['debit' => $l['credit'], 'credit' => $l['debit']] + $l, $lines);
    }

    public static function line(array $ref, int $branchId, int $debit, int $credit): array
    {
        return ['ref' => $ref, 'branch_id' => $branchId, 'debit' => $debit, 'credit' => $credit];
    }
}
