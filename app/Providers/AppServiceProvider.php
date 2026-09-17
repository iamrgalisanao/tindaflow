<?php

namespace App\Providers;

use App\Services\Auth\LoginRateLimiter;
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
        RateLimiter::for('login', fn (Request $request) => LoginRateLimiter::limit()->by(LoginRateLimiter::key($request)));
    }
}
