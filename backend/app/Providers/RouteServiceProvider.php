<?php

namespace HiEvents\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(config('app.api_rate_limit_per_minute'))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('self-service-email', function (Request $request) {
            return Limit::perHour(20)->by($request->route('order_short_id') ?? $request->ip());
        });

        RateLimiter::for('self-service-edit', function (Request $request) {
            return Limit::perHour(20)->by($request->route('order_short_id') ?? $request->ip());
        });

        RateLimiter::for('contact-lookup', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perHour(3)->by(
                    strtolower((string) $request->input('email')) ?: $request->ip()
                ),
            ];
        });

        // Token-backed prefill: tighter per-IP, since only legitimate returning
        // contacts ever hit this via email click-through.
        RateLimiter::for('contact-prefill', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Self-service profile portal (GET/PATCH /public/contacts/me). Same
        // bucket as the prefill — per-IP is enough because the token itself
        // is the primary access control.
        RateLimiter::for('contact-portal', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
