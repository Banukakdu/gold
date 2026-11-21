<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Services\Backups\BackupStatus;
use App\Services\ExpenseApprovals;
use App\Services\LedgerService;
use App\Services\TransferApprovals;
use App\Services\TransferReceipts;
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

        // AG-093 R15 (backups review F9): information only, it never blocks the close.
        $fy = $this->yearEnd->year($startYear);

        // AG-052 R8 / R14: transfers in transit at the year end, every branch. Information only, not a check.
        return $this->yearEnd->preview($fy) + ['backup_warning' => BackupStatus::warning()]
            + app(TransferReceipts::class)->inTransitSummary($fy->end())
            // AG-051 finding 15: requests dated in the year and still pending; information only.
            + app(TransferApprovals::class)->pendingSummary($fy->start(), $fy->end())
            // AG-064 S8 / SHOULD 7: expense requests dated in the year, with the list so they can be decided before
            // closing. Information only; the close is not refused because of them.
            + app(ExpenseApprovals::class)->pendingSummary($fy->start(), $fy->end())
            + ['expenses_pending_approval_list' => app(ExpenseApprovals::class)->pendingRows($fy->start(), $fy->end())['data']];
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
