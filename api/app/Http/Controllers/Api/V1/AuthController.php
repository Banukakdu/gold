<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = 'login:'.strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many failed attempts. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.'], 429);
        }

        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 15 * 60);

            return response()->json(['message' => 'Invalid email or password.'], 422);
        }
        if ($user->status !== 'active') {
            return response()->json(['message' => 'Your account is inactive. Contact your administrator.'], 403);
        }

        if (! $user->is_super_admin && ! $user->branches()->exists()) {
            return response()->json(['message' => 'You do not have access to any branch. Contact your administrator.'], 403); // B12
        }

        RateLimiter::clear($key);
        $user->forceFill(['last_login_at' => now()])->save();
        auth()->setUser($user);
        AuditLog::record('auth.login', $user);

        return ['token' => $user->createToken('web')->plainTextToken, 'user' => $this->profile($user)];
    }

    /** N5: invited user sets their password with the emailed token (AG-020, 60-minute link, single use). */
    public function setPassword(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/|confirmed',
        ]);
        $data['email'] = mb_strtolower($data['email']);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            auth()->setUser($user);
            AuditLog::record('user.password_set', $user);
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'This link has expired. Ask your administrator to resend the invitation.']);
        }

        return response()->noContent();
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return $this->profile($request->user());
    }

    /** Profile + branches the user can switch to + permissions per branch. */
    private function profile(User $user): array
    {
        $branches = $user->is_super_admin
            ? Branch::orderByDesc('is_main')->orderBy('name')->get()
            : $user->branches()->orderBy('name')->get();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_super_admin' => $user->is_super_admin,
            'branches' => $branches->map(function (Branch $b) use ($user) {
                setPermissionsTeamId($b->id);
                $user->unsetRelation('roles')->unsetRelation('permissions');

                return [
                    'id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'is_main' => $b->is_main,
                    'roles' => $user->getRoleNames()->values(),
                    'permissions' => $user->is_super_admin ? ['*'] : $user->getAllPermissions()->pluck('name')->values(),
                ];
            })->values(),
        ];
    }
}
