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

// A2 test-only harness: no real CATALOG_MANAGE-gated controller exists
// yet (Stage 4's Catalog endpoints are unimplemented), so this proves
// the real `auth` + EnsureUserIsActive + `can:` middleware chain end to
// end over actual HTTP without building production controllers merely
// to exercise the x-capability inventory. Unreachable outside the
// testing environment -- never a production route.
if (app()->environment('testing')) {
    Route::get('/api/v1/_test/requires-catalog-manage', fn () => response()->json(['ok' => true]))
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
}
