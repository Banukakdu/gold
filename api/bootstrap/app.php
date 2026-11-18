<?php

use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SetBranchContext;
use App\Http\Middleware\SuperAdminOnly;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        //
    })->create();
