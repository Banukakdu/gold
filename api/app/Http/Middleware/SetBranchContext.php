<?php

namespace App\Http\Middleware;

use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads X-Branch-Id and makes it the active branch for this request.
 *  - Normal users must be a member of the branch (403 otherwise) and must send one.
 *  - Super Admin may send a branch id, or "all" (or nothing) for consolidated mode.
 * The branch is also set as the spatie permission "team", so roles resolve per branch.
 */
class SetBranchContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $header = $request->header('X-Branch-Id');
        $ctx = app(BranchContext::class);

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account inactive.'], 403);
        }

        if ($user->is_super_admin && ($header === null || $header === '' || $header === 'all')) {
            $ctx->set(null, true);
        } else {
            if (! ctype_digit((string) $header)) {
                return response()->json(['message' => 'Select a branch (X-Branch-Id header).'], 400);
            }
            $branchId = (int) $header;
            if (! $user->belongsToBranch($branchId)) {
                return response()->json(['message' => 'You do not have access to this branch.'], 403);
            }
            $ctx->set($branchId);
        }

        setPermissionsTeamId($ctx->branchId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $next($request);
    }
}
