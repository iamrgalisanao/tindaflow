<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\RoleCapabilityCatalog;
use App\Services\Invoicing\HtmlInvoicePrinter;
use App\Services\Invoicing\InvoicePrinter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ADR-007: one printing seam, one implementation (browser HTML) today.
        $this->app->bind(InvoicePrinter::class, HtmlInvoicePrinter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => LoginRateLimiter::limit()->by(LoginRateLimiter::key($request)));

        // A generous ceiling on the whole API, per signed-in user: far above what a till produces, low enough that
        // a runaway client or a stolen session cannot hammer the database or the import/export endpoints. (Laravel
        // orders `auth` ahead of `throttle`, so a guest is answered 401 before it is counted; the only route a
        // guest can use, login, has its own limiter.) The 429 is the catalogued RATE_LIMITED.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('tindaflow.api_throttle.per_minute'))
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Module A Decision Register SS14: one Gate ability per fixed
        // capability, backed by RoleCapabilityCatalog -- the same table
        // UserSummaryResource reads -- never a second, independently
        // maintained mapping.
        foreach (RoleCapabilityCatalog::CAPABILITIES as $capability) {
            Gate::define($capability, fn (User $user): bool => RoleCapabilityCatalog::has($user->role, $capability));
        }
    }
}
