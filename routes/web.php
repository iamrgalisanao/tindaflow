<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\TerminalController;
use App\Http\Middleware\ComposeAuthoritativeContext;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveTerminalContext;
use Illuminate\Support\Facades\Route;

// Stage 7: the SPA shell. Also the CSRF-bootstrap entry point a fresh
// browser must load before POSTing to /api/v1/auth/login (already
// proven end-to-end by AuthenticationSessionTest's CSRF-bootstrap test,
// which only checks cookies/headers, never this view's markup).
Route::get('/', function () {
    return view('app');
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

    // openapi.yaml Terminal tag (ADR-011, A3). terminalCreateEnrollmentToken/
    // terminalEnroll/terminalList/terminalGet/terminalRevoke require only
    // the human session + TERMINAL_MANAGE (back-office, no terminal
    // credential needed yet -- that's what these operations establish/
    // manage).
    Route::post('/terminal-enrollment-tokens', [TerminalController::class, 'createEnrollmentToken'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:TERMINAL_MANAGE']);
    Route::post('/terminal/enroll', [TerminalController::class, 'enroll'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:TERMINAL_MANAGE']);
    Route::get('/terminals', [TerminalController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:TERMINAL_MANAGE']);
    Route::get('/terminals/{terminalId}', [TerminalController::class, 'get'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:TERMINAL_MANAGE']);
    Route::post('/terminals/{terminalId}/revoke', [TerminalController::class, 'revoke'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:TERMINAL_MANAGE']);

    // terminalCurrent is the one POS_TERMINAL-classified operation in
    // this group. A4 now exists: ComposeAuthoritativeContext enforces
    // user.store_id == terminal.store_id after ResolveTerminalContext,
    // so a Store-A user carrying a Store-B terminal's credential can no
    // longer observe that terminal here -- production exposure was
    // deferred (A3 closeout) exactly until this middleware existed.
    Route::get('/terminal/current', [TerminalController::class, 'current'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);

    // openapi.yaml Sales tag (ADR-003, A6). security: cookieAuth AND
    // terminalCookieAuth conjunctively (§14 Ruling 3) -- no x-capability
    // is declared for saleFinalize, so any authenticated user on an
    // enrolled, store-coherent terminal may check out; CheckoutService's
    // own OPEN-shift/cashier-match check is the remaining gate.
    Route::post('/sales', [SaleController::class, 'finalize'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);

    // openapi.yaml Shifts tag. security: cookieAuth AND terminalCookieAuth
    // conjunctively, same as saleFinalize -- no x-capability declared, so
    // any authenticated user on an enrolled, store-coherent terminal may
    // open/query a shift. shiftOpen atomically resolves-or-opens the
    // terminal's FiscalDay too (api-design.md SS10, V1 operating policy).
    Route::post('/shifts/open', [ShiftController::class, 'open'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);
    Route::get('/shifts/current', [ShiftController::class, 'current'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);

    // openapi.yaml Catalog tag -- productList only (see ProductController's
    // own docblock for what remains out of scope). No x-capability, no
    // terminal credential required (operation-inventory.md).
    Route::get('/products', [ProductController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
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

// Stage 7: client-side routing catch-all, so a direct load or refresh of
// e.g. /login serves the SPA shell instead of a 404. Registered last and
// excludes `api/...` so an unmatched API path still 404s as JSON rather
// than silently returning HTML.
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
