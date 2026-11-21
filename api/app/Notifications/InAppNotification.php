<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * AG-094 one in-app notification (docs/specs/notifications.md section 5 "Storage"): database channel only, never
 * queued (R3). `type` holds the kind (e.g. transfer.approval_requested); `data` is the snapshot
 * {title, body, branch_id, branch_code, url, transaction_id?, export_id?} built when it is created (R1). It is a pointer
 * to something already recorded and audited elsewhere: it posts nothing and changes no accounting record.
 */
class InAppNotification extends Notification
{
    public const APPROVAL_REQUESTED = 'transfer.approval_requested';

    public const APPROVAL_DECIDED = 'transfer.approval_decided';

    /** AG-064 R11: an expense waits for approval; the requester learns the outcome; an R4b approval is flagged. */
    public const EXPENSE_APPROVAL_REQUESTED = 'expense.approval_requested';

    public const EXPENSE_APPROVAL_DECIDED = 'expense.approval_decided';

    public const EXPENSE_APPROVAL_FLAGGED = 'expense.approval_flagged';

    public const RECEIPT_REQUESTED = 'transfer.receipt_requested';

    public const RECEIPT_DISPUTED = 'transfer.receipt_disputed';

    public const RECEIPT_SETTLED = 'transfer.receipt_settled';

    public const EXPORT_READY = 'export.ready';

    public const EXPORT_FAILED = 'export.failed';

    public const BACKUP_FAILED = 'backup.failed';

    /** AG-088 S5 owner alerts of scheduled email reports (branch = null, url = /scheduled-reports/<id>). */
    public const SCHEDULE_RUN_FAILED = 'report_schedule.run_failed';

    public const SCHEDULE_MISSED = 'report_schedule.missed';

    public const SCHEDULE_RECIPIENTS_SKIPPED = 'report_schedule.recipients_skipped';

    public const SCHEDULE_DELIVERY_FAILED = 'report_schedule.delivery_failed';

    public const SCHEDULE_PAUSED = 'report_schedule.paused';

    public function __construct(public string $kind, public array $data) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->kind;
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }
}
