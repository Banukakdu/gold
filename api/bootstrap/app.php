<?php

use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SetBranchContext;
use App\Http\Middleware\SuperAdminOnly;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'branch' => SetBranchContext::class,
            'perm' => RequirePermission::class,
            'super' => SuperAdminOnly::class,
        ]);
        // The active branch must be known before route-model binding, so bound models are branch-scoped too.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            SetBranchContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // AG-062 R4.1 (QA Q2): a file larger than post_max_size makes PHP drop the whole body and Laravel answer 413
        // before routing. On the attachment upload route, answer like any other too-large file.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->isMethod('POST') && preg_match('#^api/v1/transactions/\d+/attachments$#', $request->path())) {
                return response()->json(['message' => 'Each file may be at most 5 MB.', 'errors' => ['file' => ['Each file may be at most 5 MB.']]], 422);
            }

            return null;
        });
    })->create();
