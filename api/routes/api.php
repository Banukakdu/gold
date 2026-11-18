<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PeriodLockController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/set-password', [AuthController::class, 'setPassword'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // Everything below runs in the context of the branch in X-Branch-Id
        Route::middleware('branch')->group(function () {
            Route::get('dashboard', DashboardController::class)->middleware('perm:report.dashboard');

            // AG-082..085 reports (docs/specs/reports.md)
            Route::get('reports/income-expense', [ReportController::class, 'incomeExpense'])->middleware('perm:report.view');
            Route::get('reports/branch-comparison', [ReportController::class, 'branchComparison'])->middleware('perm:report.view');
            Route::get('reports/transfers', [ReportController::class, 'transfers'])->middleware('perm:report.view');
            Route::get('reports/cash-book', [ReportController::class, 'cashBook'])->middleware('perm:report.view');
            Route::post('reports/{name}/export', [ReportController::class, 'export'])->middleware('perm:report.view,report.export');

            Route::get('branches', [BranchController::class, 'index']);
            Route::post('branches', [BranchController::class, 'store'])->middleware('super');
            Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('super');

            Route::get('accounts', [AccountController::class, 'index'])->middleware('perm:account.view');
            Route::get('accounts/destinations', [AccountController::class, 'destinations'])->middleware('perm:transfer.create');
            Route::post('accounts', [AccountController::class, 'store'])->middleware('perm:account.manage');
            Route::put('accounts/{account}', [AccountController::class, 'update'])->middleware('perm:account.manage');
            Route::get('accounts/{account}/statement', [AccountController::class, 'statement'])->middleware('perm:account.view');

            Route::get('categories', [CategoryController::class, 'index']);
            Route::post('categories', [CategoryController::class, 'store'])->middleware('perm:category.manage');
            Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware('perm:category.manage');

            Route::get('transactions', [TransactionController::class, 'index'])->middleware('perm:transaction.view');
            Route::get('transactions/creators', [TransactionController::class, 'creators'])->middleware('perm:transaction.view');
            Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->middleware('perm:transaction.view');
            Route::put('transactions/{transaction}', [TransactionController::class, 'update']);
            Route::post('transactions/{transaction}/void', [TransactionController::class, 'void']);
            Route::post('transactions/{transaction}/reverse', [TransferController::class, 'reverse'])->middleware('perm:transfer.reverse');
            // AG-062 attachments (docs/specs/attachments.md): evidence on income/expense entries. Remove is a POST
            // action; there is deliberately no DELETE route. {attachment} must belong to {transaction} (404 otherwise).
            Route::scopeBindings()->middleware('perm:transaction.view')->group(function () {
                Route::get('transactions/{transaction}/attachments', [AttachmentController::class, 'index']);
                Route::post('transactions/{transaction}/attachments', [AttachmentController::class, 'store'])->middleware('throttle:30,1');
                Route::get('transactions/{transaction}/attachments/{attachment}/download', [AttachmentController::class, 'download']);
                Route::post('transactions/{transaction}/attachments/{attachment}/remove', [AttachmentController::class, 'remove']);
            });
            Route::post('incomes', [TransactionController::class, 'storeIncome'])->middleware('perm:income.create');
            Route::post('expenses', [TransactionController::class, 'storeExpense'])->middleware('perm:expense.create');

            Route::post('capital', [TransferController::class, 'capital'])->middleware('super');
            Route::post('allocations', [TransferController::class, 'allocate'])->middleware('super');
            Route::post('transfers', [TransferController::class, 'transfer'])->middleware('perm:transfer.create');

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

            // Nothing is hard-deleted: there is deliberately no DELETE route for users, roles or templates (AC 27).
        });
    });
});
