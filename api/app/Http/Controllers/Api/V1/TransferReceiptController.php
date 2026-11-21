<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\TransferReceipts;
use Illuminate\Http\Request;

/** AG-052 (docs/specs/transfer-receipt.md section 5): the receiving branch confirms a transfer, or reports a discrepancy. */
class TransferReceiptController extends Controller
{
    public function __construct(private TransferReceipts $receipts) {}

    /** GET /transfer-receipts (S2 widget). */
    public function index(Request $request)
    {
        $d = $request->validate(['state' => ['nullable', 'string', function (string $a, mixed $v, \Closure $fail) {
            foreach (explode(',', (string) $v) as $s) {
                if (! in_array(trim($s), TransferReceipts::OPEN, true)) {
                    $fail('State must be awaiting, disputed or both.');

                    return;
                }
            }
        }]]);
        $states = isset($d['state']) ? array_values(array_unique(array_map('trim', explode(',', $d['state'])))) : TransferReceipts::OPEN;

        return $this->receipts->widget($states, $request->user());
    }

    /** POST /transactions/{id}/receipt. */
    public function store(Request $request, int $transactionId)
    {
        $t = $this->receipts->act($transactionId, $request->only(['action', 'received_on', 'note', 'expected_status']), $request->user());

        return self::present($t->load('receiptBy:id,name,is_super_admin'));
    }

    /** The receipt fields of a transaction (section 5). */
    public static function present(Transaction $t): array
    {
        return [
            'id' => $t->id,
            'ref_no' => $t->ref_no,
            'receipt_status' => $t->receipt_status,
            'received_on' => $t->received_on?->format('Y-m-d'),
            'receipt_note' => $t->receipt_note,
            'receipt_by' => $t->receiptBy ? ['id' => $t->receiptBy->id, 'name' => $t->receiptBy->name, 'is_super_admin' => (bool) $t->receiptBy->is_super_admin] : null,
            'receipt_at' => $t->receipt_at?->toIso8601String(),
        ];
    }
}
