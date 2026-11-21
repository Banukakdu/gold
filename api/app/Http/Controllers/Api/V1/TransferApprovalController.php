<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TransferApprovals;
use Illuminate\Http\Request;

/** AG-051 (docs/specs/transfer-approvals.md section 5): Main approves, rejects; the requester cancels. */
class TransferApprovalController extends Controller
{
    public function __construct(private TransferApprovals $approvals) {}

    /** GET /transfer-approvals (S3 / S4 widgets). */
    public function index(Request $request)
    {
        return $this->approvals->widget($request->user());
    }

    /** POST /transfer-approvals/{id}/approve. A posting_date in the body is ignored (R6). */
    public function approve(Request $request, int $transactionId)
    {
        return $this->approvals->approve($transactionId, $request->only('comment'), $request->user())
            ->load(['entries' => fn ($q) => $q->orderBy('id'), 'decidedBy:id,name']);
    }

    public function reject(Request $request, int $transactionId)
    {
        return $this->approvals->reject($transactionId, $request->only('comment'), $request->user())->load('decidedBy:id,name');
    }

    public function cancel(Request $request, int $transactionId)
    {
        return $this->approvals->cancel($transactionId, $request->only('comment'), $request->user())->load('decidedBy:id,name');
    }
}
