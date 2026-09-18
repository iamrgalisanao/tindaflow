<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\RoleCapabilityCatalog;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => LoginRateLimiter::limit()->by(LoginRateLimiter::key($request)));

        // Module A Decision Register SS14: one Gate ability per fixed
        // capability, backed by RoleCapabilityCatalog -- the same table
        // UserSummaryResource reads -- never a second, independently
        // maintained mapping.
        foreach (RoleCapabilityCatalog::CAPABILITIES as $capability) {
            Gate::define($capability, fn (User $user): bool => RoleCapabilityCatalog::has($user->role, $capability));
        }
    }
}
