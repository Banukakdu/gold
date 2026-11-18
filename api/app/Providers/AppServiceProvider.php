<?php

namespace App\Providers;

use App\Support\BranchContext;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BranchContext::class);
        // AG-092 R1: settings are read once per request (memoised); a saved Setting flushes the memo.
        $this->app->scoped(Settings::class);
    }

    public const ATTACHMENT_UPLOADS_PER_MINUTE = 30;

    public const ATTACHMENT_DOWNLOADS_PER_MINUTE = 60;

    public function boot(): void
    {
        // Password reset spec R10: the one password policy. Every endpoint (and the console command) that accepts a
        // new password uses ['required', 'string', Password::defaults(), 'confirmed'] and keeps no rule of its own.
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Password reset spec R4. The per-email limits apply to any address, so a 429 says nothing about an account.
        RateLimiter::for('password-forgot', fn (Request $r) => [
            Limit::perMinute(5)->by('pw-forgot-ip-m:'.$r->ip())->response(self::tooMany(...)),
            Limit::perHour(20)->by('pw-forgot-ip-h:'.$r->ip())->response(self::tooMany(...)),
            Limit::perMinutes(15, 3)->by('pw-forgot-email:'.self::emailKey($r))->response(self::tooMany(...)),
        ]);
        RateLimiter::for('password-reset', fn (Request $r) => [
            Limit::perMinute(10)->by('pw-reset-ip:'.$r->ip())->response(self::tooMany(...)),
            Limit::perMinutes(15, 10)->by('pw-reset-email:'.self::emailKey($r))->response(self::tooMany(...)),
        ]);

        // AG-062 (reviewer S1, N4): every download writes an audit row and reads up to 5 MB, so both routes are
        // limited per user (per IP before sign-in, which the auth middleware refuses anyway).
        RateLimiter::for('attachments-upload', fn (Request $r) => Limit::perMinute(self::ATTACHMENT_UPLOADS_PER_MINUTE)
            ->by('upload:'.($r->user()?->id ?: $r->ip())));
        RateLimiter::for('attachments-download', fn (Request $r) => Limit::perMinute(self::ATTACHMENT_DOWNLOADS_PER_MINUTE)
            ->by('download:'.($r->user()?->id ?: $r->ip())));
    }

    private static function emailKey(Request $r): string
    {
        $email = $r->input('email');

        return is_string($email) ? mb_strtolower(trim($email)) : '';
    }

    /** R4: "Too many requests. Try again in N minutes." with N rounded up, minimum 1. */
    private static function tooMany(Request $r, array $headers)
    {
        $minutes = max(1, (int) ceil(((int) ($headers['Retry-After'] ?? 60)) / 60));

        return response()->json(['message' => "Too many requests. Try again in {$minutes} minutes."], 429, $headers);
    }
}
