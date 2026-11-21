<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\BankReconciliationController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ChartOfAccountsController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExpenseApprovalController;
use App\Http\Controllers\Api\V1\ExpenseTemplateController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\FiscalYearController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PeriodLockController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReportScheduleController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\TransferApprovalController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\TransferReceiptController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/set-password', [AuthController::class, 'setPassword'])->middleware('throttle:10,1');
    // AG-002 password reset (docs/specs/password-reset.md N1, N2), public, rate limited per IP and per email (R4).
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-forgot');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
    // AG-005 two-factor sign-in (docs/specs/two-factor.md P1-P3): public, challenge-based, 10 per minute per IP.
    Route::middleware('throttle:two-factor')->group(function () {
        Route::post('auth/two-factor/verify', [TwoFactorController::class, 'verify']);
        Route::post('auth/two-factor/enrol/start', [TwoFactorController::class, 'enrolStart']);
        Route::post('auth/two-factor/enrol/confirm', [TwoFactorController::class, 'enrolConfirm']);
    });

    // AG-005 R9: `two-factor` limits a user who must set up 2FA to its allow list (EnsureTwoFactorCompliance).
    Route::middleware(['auth:sanctum', 'two-factor'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // AG-005 M1-M5: the caller's own two-factor sign-in. Outside `branch`: 2FA belongs to the person (R18). There is
        // deliberately no DELETE route (recovery codes and challenges are kept).
        Route::get('me/two-factor', [TwoFactorController::class, 'status']);
        Route::post('me/two-factor/setup', [TwoFactorController::class, 'setup']);
        Route::post('me/two-factor/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('me/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
        Route::post('me/two-factor/disable', [TwoFactorController::class, 'disable']);

        // AG-092 settings (docs/specs/settings.md section 5): outside `branch`, like `me`. The logo is removed with a POST
        // action; there is deliberately no DELETE route (nothing is hard-deleted).
        Route::get('settings/public', [SettingsController::class, 'publicSettings']);
        Route::get('settings/logo', [SettingsController::class, 'logo']);
        Route::middleware('super')->group(function () {
            Route::get('settings', [SettingsController::class, 'show']);
            Route::put('settings', [SettingsController::class, 'update']);
            Route::post('settings/logo', [SettingsController::class, 'uploadLogo'])->middleware('throttle:attachments-upload');
            Route::post('settings/logo/remove', [SettingsController::class, 'removeLogo']);
            // AG-005 A1: reset another user's two-factor sign-in (R16). Outside `branch`: 2FA is per user (R18).
            Route::post('users/{user}/two-factor/reset', [TwoFactorController::class, 'reset'])->whereNumber('user');
            // AG-093 S1 backups (docs/specs/backups.md section 5): read-only. There is deliberately no POST, PUT or
            // DELETE route: backups are started, restored and pruned on the server only.
            Route::get('backups', [BackupController::class, 'index']);

            // AG-088 scheduled email reports (docs/specs/scheduled-reports.md section 5): outside `branch`, like
            // settings; a schedule carries its own branch scope. There is deliberately no DELETE route (R11).
            Route::get('report-schedules', [ReportScheduleController::class, 'index']);
            Route::post('report-schedules', [ReportScheduleController::class, 'store']);
            Route::get('report-schedules/recipient-options', [ReportScheduleController::class, 'recipientOptions']);
            Route::get('report-schedules/preview-time', [ReportScheduleController::class, 'previewTime']);
            Route::get('report-schedules/{id}', [ReportScheduleController::class, 'show'])->whereNumber('id');
            Route::put('report-schedules/{id}', [ReportScheduleController::class, 'update'])->whereNumber('id');
            Route::get('report-schedules/{id}/runs', [ReportScheduleController::class, 'runs'])->whereNumber('id');
            Route::post('report-schedules/{id}/pause', [ReportScheduleController::class, 'pause'])->whereNumber('id');
            Route::post('report-schedules/{id}/resume', [ReportScheduleController::class, 'resume'])->whereNumber('id');
            Route::post('report-schedules/{id}/archive', [ReportScheduleController::class, 'archive'])->whereNumber('id');
            Route::post('report-schedules/{id}/restore', [ReportScheduleController::class, 'restore'])->whereNumber('id');
        });

        // AG-087 background exports (docs/specs/background-exports.md section 5): the requester's own only (404
        // otherwise). Outside `branch` (reviewer m1): they never use the active branch, and download re-checks access
        // against the export's stored branch context, so a user who lost that branch still reaches the audited 403.
        Route::get('exports', [ExportController::class, 'index']);
        Route::get('exports/{exportId}', [ExportController::class, 'show']);
        Route::get('exports/{exportId}/download', [ExportController::class, 'download']);

        // AG-094 notifications (docs/specs/notifications.md section 5): the caller's own rows only (404 otherwise, a Super
        // Admin's included). Outside `branch`: a notification belongs to a user, not to the active branch (R5). There is
        // deliberately no DELETE route; rows are pruned after 90 days by notifications:prune (R11).
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
        Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences']);
        Route::get('notifications/{id}', [NotificationController::class, 'show'])->whereUuid('id');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id');

        // Everything below runs in the context of the branch in X-Branch-Id
        Route::middleware('branch')->group(function () {
            Route::get('dashboard', DashboardController::class)->middleware('perm:report.dashboard');

            // AG-082..085 reports (docs/specs/reports.md)
            Route::get('reports/income-expense', [ReportController::class, 'incomeExpense'])->middleware('perm:report.view');
            Route::get('reports/branch-comparison', [ReportController::class, 'branchComparison'])->middleware('perm:report.view');
            Route::get('reports/transfers', [ReportController::class, 'transfers'])->middleware('perm:report.view');
            Route::get('reports/cash-book', [ReportController::class, 'cashBook'])->middleware('perm:report.view');
            Route::get('reports/cash-flow', [ReportController::class, 'cashFlow'])->middleware('perm:report.view'); // AG-086
            Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->middleware('perm:report.view');
            // AG-033 S3 Unreconciled bank entries (export through reports/{name}/export).
            Route::get('reports/bank-reconciliation', [ReportController::class, 'bankReconciliation'])->middleware('perm:report.view');
            Route::post('reports/{name}/export', [ReportController::class, 'export'])->middleware('perm:report.view,report.export');

            Route::get('branches', [BranchController::class, 'index']);
            Route::post('branches', [BranchController::class, 'store'])->middleware('super');
            Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('super');

            Route::get('accounts', [AccountController::class, 'index'])->middleware('perm:account.view');
            Route::get('accounts/destinations', [AccountController::class, 'destinations'])->middleware('perm:transfer.create');
            Route::post('accounts', [AccountController::class, 'store'])->middleware('perm:account.manage');
            Route::put('accounts/{account}', [AccountController::class, 'update'])->middleware('perm:account.manage');
            Route::get('accounts/{account}/statement', [AccountController::class, 'statement'])->middleware('perm:account.view');
            // AG-033 bank reconciliation (docs/specs/bank-reconciliation.md): posts nothing. Undo is a POST action; there is
            // deliberately no DELETE route. {statementId} must belong to {account} (404 otherwise).
            Route::middleware('perm:account.reconcile')->group(function () {
                Route::get('accounts/{account}/reconciliation', [BankReconciliationController::class, 'show']);
                Route::post('accounts/{account}/reconciliation/marks', [BankReconciliationController::class, 'marks']);
                Route::post('accounts/{account}/reconciliation/complete', [BankReconciliationController::class, 'complete']);
                Route::post('accounts/{account}/reconciliation/statements/{statementId}/undo', [BankReconciliationController::class, 'undo'])
                    ->whereNumber('statementId');
            });
            // Double-entry S1: the chart of accounts of one branch (structure only, no balances).
            Route::get('chart-of-accounts', ChartOfAccountsController::class)->middleware('perm:account.view');

            Route::get('categories', [CategoryController::class, 'index']);
            Route::post('categories', [CategoryController::class, 'store'])->middleware('perm:category.manage');
            Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware('perm:category.manage');
            // Categories spec R9 (AG-071): a branch-only sub-category becomes global. No DELETE route (AG-072, AC 24).
            Route::post('categories/{category}/promote', [CategoryController::class, 'promote'])->middleware('super');

            Route::get('transactions', [TransactionController::class, 'index'])->middleware('perm:transaction.view');
            Route::get('transactions/creators', [TransactionController::class, 'creators'])->middleware('perm:transaction.view');
            // AG-065 R14: the filtered list as xlsx; both permissions required, as for reports/{name}/export.
            Route::post('transactions/export', [TransactionController::class, 'export'])->middleware('perm:transaction.view,report.export');
            // Double-entry R10: the detail is resolved without the branch scope; the controller checks access by line branches.
            Route::get('transactions/{transactionId}', [TransactionController::class, 'show'])->whereNumber('transactionId')->middleware('perm:transaction.view');
            Route::put('transactions/{transaction}', [TransactionController::class, 'update']);
            Route::post('transactions/{transaction}/void', [TransactionController::class, 'void']);
            Route::post('transactions/{transaction}/reverse', [TransferController::class, 'reverse'])->middleware('perm:transfer.reverse');
            // AG-052 transfer receipt: resolved without the branch scope; the service checks the receiving branch (R4).
            Route::get('transfer-receipts', [TransferReceiptController::class, 'index'])->middleware('perm:transaction.view');
            Route::post('transactions/{transactionId}/receipt', [TransferReceiptController::class, 'store'])->whereNumber('transactionId')
                ->middleware('perm:transfer.acknowledge');
            // AG-062 attachments (docs/specs/attachments.md): evidence on income/expense entries. Remove is a POST
            // action; there is deliberately no DELETE route. {attachment} must belong to {transaction} (404 otherwise).
            Route::scopeBindings()->middleware('perm:transaction.view')->group(function () {
                Route::get('transactions/{transaction}/attachments', [AttachmentController::class, 'index']);
                Route::post('transactions/{transaction}/attachments', [AttachmentController::class, 'store'])->middleware('throttle:attachments-upload');
                Route::get('transactions/{transaction}/attachments/{attachment}/download', [AttachmentController::class, 'download'])
                    ->middleware('throttle:attachments-download');
                Route::post('transactions/{transaction}/attachments/{attachment}/remove', [AttachmentController::class, 'remove']);
            });
            Route::post('incomes', [TransactionController::class, 'storeIncome'])->middleware('perm:income.create');
            Route::post('expenses', [TransactionController::class, 'storeExpense'])->middleware('perm:expense.create');
            // AG-066 expense templates (docs/specs/recurring-expenses.md): post nothing. Reading needs expense.create or
            // expense.edit (controller check). Archive / restore are POST actions; there is deliberately no DELETE route.
            Route::get('expense-templates', [ExpenseTemplateController::class, 'index']);
            Route::get('expense-templates/{templateId}', [ExpenseTemplateController::class, 'show'])->whereNumber('templateId');
            Route::middleware('perm:expense.edit')->group(function () {
                Route::post('expense-templates', [ExpenseTemplateController::class, 'store']);
                Route::put('expense-templates/{templateId}', [ExpenseTemplateController::class, 'update'])->whereNumber('templateId');
                Route::post('expense-templates/{templateId}/archive', [ExpenseTemplateController::class, 'archive'])->whereNumber('templateId');
                Route::post('expense-templates/{templateId}/restore', [ExpenseTemplateController::class, 'restore'])->whereNumber('templateId');
            });

            Route::post('capital', [TransferController::class, 'capital'])->middleware('super');
            Route::post('allocations', [TransferController::class, 'allocate'])->middleware('super');
            Route::post('transfers', [TransferController::class, 'transfer'])->middleware('perm:transfer.create');
            // AG-051 transfer approvals: rows are read without the branch scope; the service checks Main (R3) and the
            // requester (R4) itself.
            Route::get('transfer-approvals', [TransferApprovalController::class, 'index'])->middleware('perm:transaction.view');
            Route::post('transfer-approvals/{transactionId}/approve', [TransferApprovalController::class, 'approve'])->whereNumber('transactionId')
                ->middleware('perm:transfer.approve');
            Route::post('transfer-approvals/{transactionId}/reject', [TransferApprovalController::class, 'reject'])->whereNumber('transactionId')
                ->middleware('perm:transfer.approve');
            Route::post('transfer-approvals/{transactionId}/cancel', [TransferApprovalController::class, 'cancel'])->whereNumber('transactionId')
                ->middleware('perm:transfer.create');
            // AG-064 expense approvals: rows are read without the branch scope; the service checks the expense's branch (R3),
            // the requester (R4) and branch user managers (R4a) itself.
            Route::get('expense-approvals', [ExpenseApprovalController::class, 'index'])->middleware('perm:transaction.view');
            Route::post('expense-approvals/{transactionId}/approve', [ExpenseApprovalController::class, 'approve'])->whereNumber('transactionId')
                ->middleware('perm:expense.approve');
            Route::post('expense-approvals/{transactionId}/reject', [ExpenseApprovalController::class, 'reject'])->whereNumber('transactionId')
                ->middleware('perm:expense.approve');
            Route::post('expense-approvals/{transactionId}/cancel', [ExpenseApprovalController::class, 'cancel'])->whereNumber('transactionId')
                ->middleware('perm:expense.create');

            Route::get('permissions', [RoleController::class, 'permissions'])->middleware('perm:role.view');
            Route::get('roles', [RoleController::class, 'index'])->middleware('perm:role.view');
            Route::post('roles', [RoleController::class, 'store'])->middleware('perm:role.manage');
            Route::post('roles/copy-templates', [RoleController::class, 'copyTemplates'])->middleware('super');
            Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('perm:role.manage');

            Route::get('users', [UserController::class, 'index'])->middleware('perm:user.view');
            Route::post('users', [UserController::class, 'store'])->middleware('perm:user.create');
            Route::get('users/{user}', [UserController::class, 'show'])->middleware('perm:user.view');
            Route::put('users/{user}', [UserController::class, 'update'])->middleware('perm:user.edit');
            Route::post('users/{user}/role', [UserController::class, 'assignRole'])->middleware('perm:user.edit');
            Route::post('users/{user}/remove-from-branch', [UserController::class, 'removeFromBranch'])->middleware('perm:user.edit');
            Route::post('users/{user}/resend-invite', [UserController::class, 'resendInvite'])->middleware('perm:user.edit');
            // AG-090 audit log (docs/specs/controls.md): read-only, no PUT/PATCH/DELETE routes (AC 28).
            Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('perm:audit.view');
            Route::get('audit-logs/actors', [AuditLogController::class, 'actors'])->middleware('perm:audit.view');
            Route::post('audit-logs/export', [AuditLogController::class, 'export'])->middleware('perm:audit.view');

            // AG-091 period locks: Super Admin only; an unlock keeps the row as history.
            Route::get('period-locks/status', [PeriodLockController::class, 'status']); // read-only banner (R11), any branch user
            Route::get('period-locks', [PeriodLockController::class, 'index'])->middleware('super');
            Route::get('period-locks/review', [PeriodLockController::class, 'review'])->middleware('super');
            Route::post('period-locks', [PeriodLockController::class, 'store'])->middleware('super');
            Route::post('period-locks/{periodLock}/unlock', [PeriodLockController::class, 'unlock'])->middleware('super');

            // AG-092 fiscal years and the year-end close (S5): Super Admin only, any switcher mode.
            Route::middleware('super')->group(function () {
                Route::get('fiscal-years', [FiscalYearController::class, 'index']);
                Route::get('fiscal-years/{startYear}/preview', [FiscalYearController::class, 'preview'])->whereNumber('startYear');
                Route::post('fiscal-years/{startYear}/close', [FiscalYearController::class, 'close'])->whereNumber('startYear');
                Route::post('fiscal-years/{startYear}/reopen', [FiscalYearController::class, 'reopen'])->whereNumber('startYear');
            });

            // Nothing is hard-deleted: there is deliberately no DELETE route for users, roles or templates (AC 27).
        });
    });
});
