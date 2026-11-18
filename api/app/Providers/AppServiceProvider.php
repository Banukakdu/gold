<?php

namespace App\Providers;

use App\Support\BranchContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(BranchContext::class);
    }

    public function boot(): void
    {
        //
    }
}
