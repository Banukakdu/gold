<?php

namespace App\Providers;

use App\Support\BranchContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BranchContext::class);
    }

    public const ATTACHMENT_UPLOADS_PER_MINUTE = 30;

    public const ATTACHMENT_DOWNLOADS_PER_MINUTE = 60;

    public function boot(): void
    {
        // AG-062 (reviewer S1, N4): every download writes an audit row and reads up to 5 MB, so both routes are
        // limited per user (per IP before sign-in, which the auth middleware refuses anyway).
        RateLimiter::for('attachments-upload', fn (Request $r) => Limit::perMinute(self::ATTACHMENT_UPLOADS_PER_MINUTE)
            ->by('upload:'.($r->user()?->id ?: $r->ip())));
        RateLimiter::for('attachments-download', fn (Request $r) => Limit::perMinute(self::ATTACHMENT_DOWNLOADS_PER_MINUTE)
            ->by('download:'.($r->user()?->id ?: $r->ip())));
    }
}
