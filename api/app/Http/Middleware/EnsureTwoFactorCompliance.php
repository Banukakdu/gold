<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use App\Support\TwoFactorPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AG-005 R9: a user who must use two-factor sign-in (R8) and has not set it up can only reach the allow list below with
 * an existing token; everything else is 403 `two_factor_enrolment_required`. Checked on every request, so it applies
 * at once to new admins, role changes and role permission edits. When the setting is off this costs one (memoised)
 * settings read.
 */
class EnsureTwoFactorCompliance
{
    /** [method, path below api/v1/] */
    public const ALLOW = [
        ['GET', 'me'],
        ['POST', 'auth/logout'],
        ['GET', 'settings/public'],
        ['GET', 'settings/logo'],
        ['GET', 'me/two-factor'],
        ['POST', 'me/two-factor/setup'],
        ['POST', 'me/two-factor/confirm'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! app(Settings::class)->twoFactorRequiredForAdmins() || $user->hasTwoFactor() || $this->allowed($request)) {
            return $next($request);
        }
        if (TwoFactorPolicy::isAdmin($user)) {
            return response()->json(['message' => 'Set up two-factor sign-in to continue.', 'code' => 'two_factor_enrolment_required'], 403);
        }

        return $next($request);
    }

    private function allowed(Request $request): bool
    {
        foreach (self::ALLOW as [$method, $path]) {
            if ($request->isMethod($method) && $request->is('api/v1/'.$path)) {
                return true;
            }
        }

        return false;
    }
}
