<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware: perm:expense.create  (Super Admin always passes). */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        foreach ($permissions as $p) {
            if (! $user->canDo($p)) {
                return response()->json(['message' => "Missing permission: {$p}"], 403);
            }
        }

        return $next($request);
    }
}
