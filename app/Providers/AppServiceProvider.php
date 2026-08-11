<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Versioned key: isolates this 30-second policy from the legacy
        // throttle:3,60 cache entries that share the sha1(domain|ip) key.
        RateLimiter::for('forgot-password-v2', function (Request $request) {
            return Limit::perMinutes(0.5, 3)->by($request->ip());
        });
    }
}
