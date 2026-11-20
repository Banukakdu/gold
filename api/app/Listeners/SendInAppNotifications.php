<?php

namespace App\Listeners;

use App\Events\ExportFinished;
use App\Events\TransferApprovalDecided;
use App\Events\TransferApprovalRequested;
use App\Events\TransferReceiptChanged;
use App\Events\TransferReceiptRequested;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Export;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\InAppNotification as N;
use App\Notifications\TransferApprovalRequestedMail;
use App\Services\BackgroundExports;
use App\Services\InAppNotifications;
use App\Services\TransferApprovals;
use App\Services\TransferReceipts;
use App\Support\Money;
use Throwable;

/**
 * AG-094 R1-R3, R6, R7 (docs/specs/notifications.md): the in-app rows of the five events, written synchronously
 * (database channel only) since the events already fire after commit. Any exception is reported and swallowed
 * (AG-051 review m4): a failed notification never fails the request or the export job. The existing email listeners
 * are not changed; the only email sent from here is the approval request (R7).
 *
 * Text is a snapshot built now (amounts with Money::format). Free text typed by users (reject comments, dispute
 * notes, export errors) is never copied; the link shows it. Nothing here posts or changes an accounting record.
 */
class SendInAppNotifications
{
    public function __construct(private InAppNotifications $inApp) {}

    /** R1 approval request: MAIN approvers and Super Admins except the requester (R2), plus the optional email (R7). */
    public function handleApprovalRequested(TransferApprovalRequested $event): void
    {
        $this->guard(function () use ($event) {
            $t = $this->transfer($event->transactionId);
            if (! $t) {
                return;
            }
            $main = Branch::main();
            $users = $this->inApp->eligible($this->inApp->approvers(), $event->userId)->except([(int) $t->created_by]);
            $data = $this->data($t, $main, "{$t->ref_no} needs approval",
                $this->route($t).', '.Money::format($t->amount).', requested by '.$this->name($t->created_by));
            foreach ($users as $u) {
                // R7: the email links to this user's own row, so a failed row write skips the email (reported).
                $this->guard(function () use ($u, $t, $data) {
                    $id = $this->inApp->write($u, N::APPROVAL_REQUESTED, $data);
                    if ($u->approval_request_email ?? true) {
                        $u->notify(new TransferApprovalRequestedMail($t->id, $id));
                    }
                });
            }
        });
    }

    /** R6: any decision closes the approvers' rows; R1: approved / rejected / expired (not cancelled) tell the requester. */
    public function handleApprovalDecided(TransferApprovalDecided $event): void
    {
        $this->guard(fn () => $this->inApp->markReadFor(N::APPROVAL_REQUESTED, $event->transactionId));
        if (! in_array($event->state, [TransferApprovals::APPROVED, TransferApprovals::REJECTED, TransferApprovals::EXPIRED], true)) {
            return;
        }
        $this->guard(function () use ($event) {
            $t = $this->transfer($event->transactionId);
            $requester = $t?->creator()->first();
            if (! $t || ! $requester) {
                return;
            }
            $by = $this->name($event->userId);
            [$title, $detail] = match ($event->state) {
                TransferApprovals::APPROVED => ["Your transfer request {$t->ref_no} was approved and posted.", "approved by {$by}"],
                TransferApprovals::REJECTED => ["Your transfer request {$t->ref_no} was rejected by {$by}.", "rejected by {$by}"],
                default => ["Your transfer request {$t->ref_no} expired after ".TransferApprovals::EXPIRY_DAYS.' days.',
                    'requested on '.$t->requested_on?->format('Y-m-d')],
            };
            // The event names the actor; null is the expiry job (no actor), whoever is signed in.
            $this->inApp->send([$requester], $event->userId, N::APPROVAL_DECIDED,
                $this->data($t, $this->branch($t->branch_id), $title, $this->route($t).', '.Money::format($t->amount).', '.$detail));
        });
    }

    /** R1: `transfer.acknowledge` holders of the receiving branch, never the creator or a Super Admin (R2). */
    public function handleReceiptRequested(TransferReceiptRequested $event): void
    {
        $this->guard(function () use ($event) {
            $t = $this->transfer($event->transactionId);
            if (! $t || ! $t->related_branch_id) {
                return;
            }
            $users = $this->inApp->holders('transfer.acknowledge', (int) $t->related_branch_id)
                ->reject(fn (User $u) => $u->is_super_admin || (int) $u->id === (int) $t->created_by);
            $this->inApp->send($users, $event->userId, N::RECEIPT_REQUESTED, $this->data($t, $this->branch($t->related_branch_id),
                "Confirm receipt of {$t->ref_no} from {$this->code($t->fromAccount)}: ".Money::format($t->amount),
                'From '.$this->side($t->fromAccount).' to '.$this->side($t->toAccount).', sent by '.$this->name($t->created_by).'.'));
        });
    }

    /**
     * R6: leaving "awaiting" closes the confirmers' rows. R1 + accounting review 1: a dispute and its settlement go to
     * the creator, the sending branch's reversers (Super Admins instead for a fund return) and every active Super Admin.
     */
    public function handleReceiptChanged(TransferReceiptChanged $event): void
    {
        if ($event->oldState === TransferReceipts::AWAITING) {
            $this->guard(fn () => $this->inApp->markReadFor(N::RECEIPT_REQUESTED, $event->transactionId));
        }
        $kind = match (true) {
            $event->newState === TransferReceipts::DISPUTED => N::RECEIPT_DISPUTED,
            $event->oldState === TransferReceipts::DISPUTED && $event->newState === TransferReceipts::RECEIVED => N::RECEIPT_SETTLED,
            default => null,
        };
        if ($kind === null) {
            return;
        }
        $this->guard(function () use ($event, $kind) {
            $t = $this->transfer($event->transactionId);
            if (! $t) {
                return;
            }
            $users = collect([$t->creator()->first()])->filter()
                ->concat($t->type === Transaction::FUND_RETURN ? [] : $this->inApp->holders('transfer.reverse', (int) $t->branch_id))
                ->concat($this->inApp->superAdmins());
            $to = $this->code($t->toAccount);
            $title = $kind === N::RECEIPT_DISPUTED
                ? "{$to} reported a discrepancy on {$t->ref_no} (".Money::format($t->amount).')'
                : "{$to} confirmed receipt of {$t->ref_no} after a discrepancy";
            $this->inApp->send($users, $event->userId, $kind, $this->data($t, $this->branch($t->branch_id), $title,
                $this->route($t).', '.Money::format($t->amount).'. Open the transfer for the details.'));
        });
    }

    /** R1: the requester, with no branch tag. Sent whatever `exports.email` says (that setting is for the email only). */
    public function handleExportFinished(ExportFinished $event): void
    {
        $this->guard(function () use ($event) {
            $e = $event->export->fresh() ?? $event->export;
            $user = User::find($e->user_id);
            if (! $user) {
                return;
            }
            $ready = $e->status === Export::READY;
            $body = BackgroundExports::summaryLine($e);
            if ($ready && $e->expires_at) {
                $body .= ' Download before '.$e->expires_at->copy()->setTimezone('Asia/Colombo')->format('Y-m-d H:i').'.';
            }
            // A system event: the requester is not its actor, so only R2's active rule applies.
            $this->inApp->send([$user], null, $ready ? N::EXPORT_READY : N::EXPORT_FAILED, [
                'title' => $ready ? 'Your export is ready' : 'Your export could not be prepared',
                'body' => $body, 'branch_id' => null, 'branch_code' => null, 'url' => '/exports?id='.$e->id, 'export_id' => $e->id,
            ]);
        });
    }

    // ------------------------------------------------------------------ helpers

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function transfer(int $id): ?Transaction
    {
        return Transaction::withoutGlobalScopes()->with(['fromAccount.branch:id,code', 'toAccount.branch:id,code'])->find($id);
    }

    private function data(Transaction $t, ?Branch $branch, string $title, string $body): array
    {
        return ['title' => $title, 'body' => $body, 'branch_id' => $branch?->id, 'branch_code' => $branch?->code,
            'url' => "/transactions/{$t->id}", 'transaction_id' => $t->id];
    }

    private function branch(?int $id): ?Branch
    {
        return $id ? Branch::find($id) : null;
    }

    private function code(?Account $a): string
    {
        return (string) ($a?->branch?->code ?? '');
    }

    private function side(?Account $a): string
    {
        return $a ? trim($this->code($a).' '.$a->name) : '';
    }

    /** "KDY -> GAL" */
    private function route(Transaction $t): string
    {
        return $this->code($t->fromAccount).' -> '.$this->code($t->toAccount);
    }

    private function name(?int $userId): string
    {
        return ($userId ? User::whereKey($userId)->value('name') : null) ?? 'another user';
    }
}
