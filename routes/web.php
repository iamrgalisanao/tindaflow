<?php

use App\Http\Controllers\AuthController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// openapi.yaml Auth tag. Session-authenticated, same-origin JSON API
// (Module A Decision Register SS5) -- registered under the `web`
// middleware group (session + CSRF) via routes/web.php's own routing
// registration, never the stateless `api` group, even though these paths
// live under the /api/v1 server base path the contract declares.
Route::prefix('api/v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware(['auth', EnsureUserIsActive::class]);
});
