<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Services\YearEnd;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * AG-092 Year-end screen (docs/specs/settings.md S5, R7-R11). Super Admin only (`super`), in any switcher mode.
 * Posting is LedgerService::closeYear / reopenYear; this controller only reads and calls them.
 */
class FiscalYearController extends Controller
{
    public function __construct(private YearEnd $yearEnd, private LedgerService $ledger) {}

    public function index()
    {
        return ['data' => $this->yearEnd->years()];
    }

    public function preview(int $startYear)
    {
        abort_unless($this->yearEnd->inList($startYear), 404, 'Unknown fiscal year.');

        return $this->yearEnd->preview($this->yearEnd->year($startYear));
    }

    public function close(Request $request, int $startYear)
    {
        abort_unless($this->yearEnd->inList($startYear), 404, 'Unknown fiscal year.');
        $confirm = (string) $request->validate(['confirm' => 'required|string|max:20'])['confirm'];
        $out = $this->ledger->closeYear($startYear, $confirm);
        $close = $out['close']->load(['closedBy:id,name', 'reopenedBy:id,name']);

        return response()->json([
            'close' => YearEnd::present($close),
            'transactions' => array_map(fn (Transaction $t) => [
                'id' => $t->id,
                'ref_no' => $t->ref_no,
                'branch_code' => $t->branch->code,
                'lines' => $t->entries->map(fn (LedgerEntry $e) => ['branch_id' => $e->branch_id, 'account_id' => $e->account_id,
                    'debit' => Money::add($e->debit), 'credit' => Money::add($e->credit)])->values()->all(),
            ], $out['transactions']),
        ], 201);
    }

    public function reopen(Request $request, int $startYear)
    {
        abort_unless($this->yearEnd->inList($startYear), 404, 'Unknown fiscal year.');
        $reason = $request->validate(['reason' => 'required|string|min:10|max:500'])['reason'];
        $close = $this->ledger->reopenYear($startYear, $reason)->load(['closedBy:id,name', 'reopenedBy:id,name']);

        return ['close' => YearEnd::present($close)];
    }
}
