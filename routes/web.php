<?php

use App\Http\Controllers\AuditEventController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\FiscalDayController;
use App\Http\Controllers\FiscalInstallationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryLocationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceSeriesController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\Reports\InventoryReportController;
use App\Http\Controllers\Reports\SalesReportController;
use App\Http\Controllers\Reports\ShiftReportController;
use App\Http\Controllers\Reports\VoidRefundReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StoreSettingsController;
use App\Http\Controllers\StoreSetupController;
use App\Http\Controllers\TaxRegistrationController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VoidController;
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

    // Sales history and the Void/Refund workflow (Stage 15). Reads need only the session, scoped to the
    // actor's store. saleVoid/saleRefund and the two approve operations are terminal-scoped like
    // checkout (they are attributed to the executing terminal's own shift and fiscal day) and gated by
    // the capability the contract names. The two reject operations are human decisions with no
    // processing context: session + capability only, no terminal (openapi.yaml voidReject/refundReject).
    Route::get('/sales', [SaleController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/sales/{saleId}', [SaleController::class, 'get'])->whereUuid('saleId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/sales/{saleId}/void', [SaleController::class, 'void'])->whereUuid('saleId')
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class, 'can:SALE_VOID']);
    Route::post('/sales/{saleId}/refunds', [SaleController::class, 'refund'])->whereUuid('saleId')
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class, 'can:SALE_REFUND']);

    Route::get('/voids', [VoidController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/voids/{voidId}', [VoidController::class, 'get'])->whereUuid('voidId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/voids/{voidId}/approve', [VoidController::class, 'approve'])->whereUuid('voidId')
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class, 'can:SALE_VOID_APPROVE']);
    Route::post('/voids/{voidId}/reject', [VoidController::class, 'reject'])->whereUuid('voidId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:SALE_VOID_APPROVE']);

    Route::get('/refunds', [RefundController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/refunds/{refundId}', [RefundController::class, 'get'])->whereUuid('refundId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/refunds/{refundId}/approve', [RefundController::class, 'approve'])->whereUuid('refundId')
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class, 'can:SALE_REFUND_APPROVE']);
    Route::post('/refunds/{refundId}/reject', [RefundController::class, 'reject'])->whereUuid('refundId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:SALE_REFUND_APPROVE']);

    // openapi.yaml StoreSettings tag: business identity for the actor's own store. Reading needs only a
    // session; changing it needs STORE_SETTINGS_MANAGE. (tax-registrations are registered separately, below.)
    Route::get('/store-settings', [StoreSettingsController::class, 'get'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::patch('/store-settings', [StoreSettingsController::class, 'update'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:STORE_SETTINGS_MANAGE']);

    // openapi.yaml Invoices tag. invoiceGet: session only, store-scoped, the plain original rendered from
    // the immutable snapshot. invoiceReprint: enrolled terminal + Idempotency-Key, no dedicated capability
    // (operation-inventory.md); it never allocates a number or touches any fiscal total.
    Route::get('/invoices/{invoiceId}', [InvoiceController::class, 'get'])->whereUuid('invoiceId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/invoices/{invoiceId}/reprints', [InvoiceController::class, 'reprint'])->whereUuid('invoiceId')
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);

    // openapi.yaml Audit and ElectronicJournal tags: read-only lists, session only (no terminal), gated by
    // AUDIT_VIEW / JOURNAL_VIEW. No create, update or delete exists for either log. auditEventGet and
    // journalEntryGet are not registered yet (their 404 has no error code; see AuditEventController).
    Route::get('/audit-events', [AuditEventController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:AUDIT_VIEW']);
    Route::get('/electronic-journal-entries', [JournalEntryController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:JOURNAL_VIEW']);

    // openapi.yaml Shifts tag. security: cookieAuth AND terminalCookieAuth
    // conjunctively, same as saleFinalize -- no x-capability declared, so
    // any authenticated user on an enrolled, store-coherent terminal may
    // open/query a shift. shiftOpen atomically resolves-or-opens the
    // terminal's FiscalDay too (api-design.md SS10, V1 operating policy).
    Route::post('/shifts/open', [ShiftController::class, 'open'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);
    Route::get('/shifts/current', [ShiftController::class, 'current'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);
    Route::post('/shifts/{shiftId}/close', [ShiftController::class, 'close'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);
    Route::post('/shifts/{shiftId}/cash-movements', [ShiftController::class, 'createCashMovement'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);
    Route::get('/shifts/{shiftId}/x-readings', [ShiftController::class, 'listXReadings'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);
    Route::post('/shifts/{shiftId}/x-readings', [ShiftController::class, 'createXReading'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);

    // openapi.yaml FiscalDay tag. fiscalDayClose additionally requires
    // FISCAL_DAY_CLOSE (x-capability) on top of the terminal-scoped
    // chain -- an admin/manager action, not any cashier's.
    Route::post('/fiscal-days/{fiscalDayId}/close', [FiscalDayController::class, 'close'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class, 'can:FISCAL_DAY_CLOSE']);
    Route::get('/fiscal-days/{fiscalDayId}/z-reading', [FiscalDayController::class, 'getZReading'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);

    // openapi.yaml Catalog tag. Reads need only the session (operation-inventory.md);
    // every mutation is CATALOG_MANAGE. No terminal credential. {productId} is
    // constrained to a UUID so the not-yet-built literal paths (/products/import,
    // /products/export, /products/by-barcode/...) can never be captured by it.
    Route::get('/products', [ProductController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/products', [ProductController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::get('/products/{productId}', [ProductController::class, 'get'])->whereUuid('productId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::patch('/products/{productId}', [ProductController::class, 'update'])->whereUuid('productId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::post('/products/{productId}/activate', [ProductController::class, 'activate'])->whereUuid('productId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::post('/products/{productId}/deactivate', [ProductController::class, 'deactivate'])->whereUuid('productId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);

    Route::get('/categories', [CategoryController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/categories', [CategoryController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::get('/brands', [BrandController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/brands', [BrandController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);

    // openapi.yaml Inventory tag. Reads are session-only. Receipts/adjustments attribute the
    // movement to a physical terminal and are idempotent (operation-inventory.md: terminal
    // enrolled = true), so they carry the terminal chain plus STOCK_ADJUST (ADMIN, MANAGER).
    Route::get('/inventory/stock', [InventoryController::class, 'stock'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/inventory/movements', [InventoryController::class, 'movements'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/inventory/receipts', [InventoryController::class, 'receive'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class, 'can:STOCK_ADJUST']);
    Route::post('/inventory/adjustments', [InventoryController::class, 'adjust'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class, 'can:STOCK_ADJUST']);

    // openapi.yaml Users tag -- all USER_MANAGE, session-only, no terminal credential.
    // userActivate is forward-committed (docs/06-backend/stage-13-users.md).
    Route::middleware(['auth', EnsureUserIsActive::class, 'can:USER_MANAGE'])->group(function () {
        Route::get('/users', [UserController::class, 'list']);
        Route::post('/users', [UserController::class, 'create']);
        Route::get('/users/{userId}', [UserController::class, 'get'])->whereUuid('userId');
        Route::patch('/users/{userId}', [UserController::class, 'update'])->whereUuid('userId');
        Route::post('/users/{userId}/deactivate', [UserController::class, 'deactivate'])->whereUuid('userId');
        Route::post('/users/{userId}/activate', [UserController::class, 'activate'])->whereUuid('userId');
    });

    // Store setup (docs/06-ui/stage-8-store-setup.md). Admin CRUD is
    // FISCAL_CONFIGURATION_MANAGE-gated, store-scoped, no terminal
    // credential needed -- same shape as Terminal management above.
    Route::get('/fiscal-installations', [FiscalInstallationController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);
    Route::post('/fiscal-installations', [FiscalInstallationController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);
    Route::post('/fiscal-installations/{fiscalInstallationId}/terminals', [FiscalInstallationController::class, 'assignTerminal'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);

    Route::get('/tax-registrations', [TaxRegistrationController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/tax-registrations', [TaxRegistrationController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);

    Route::get('/invoice-series', [InvoiceSeriesController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);
    Route::post('/invoice-series', [InvoiceSeriesController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);
    Route::post('/invoice-series/{invoiceSeriesId}/close', [InvoiceSeriesController::class, 'close'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);

    Route::get('/inventory-locations', [InventoryLocationController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);
    Route::post('/inventory-locations', [InventoryLocationController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);
    Route::patch('/inventory-locations/{inventoryLocationId}', [InventoryLocationController::class, 'update'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);

    // Terminal-scoped like shiftCurrentGet -- no x-capability, since a
    // cashier on an enrolled terminal needs this readiness check too, not
    // just an admin.
    Route::get('/store-setup/readiness', [StoreSetupController::class, 'readiness'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);

    // openapi.yaml Reports tag (15 operations) -- session-only, no
    // terminal credential, all REPORT_VIEW. No domain-specific failure
    // mode exists for any of these (operation-inventory.md: "reports
    // never fail on business state, only on auth").
    Route::middleware(['auth', EnsureUserIsActive::class, 'can:REPORT_VIEW'])->group(function () {
        Route::get('/reports/daily-sales-summary', [SalesReportController::class, 'dailySalesSummary']);
        Route::get('/reports/sales-by-date-range', [SalesReportController::class, 'salesByDateRange']);
        Route::get('/reports/sales-by-product', [SalesReportController::class, 'salesByProduct']);
        Route::get('/reports/sales-by-category', [SalesReportController::class, 'salesByCategory']);
        Route::get('/reports/sales-by-cashier', [SalesReportController::class, 'salesByCashier']);
        Route::get('/reports/sales-by-payment-method', [SalesReportController::class, 'salesByPaymentMethod']);
        Route::get('/reports/tax-breakdown', [SalesReportController::class, 'taxBreakdown']);
        Route::get('/reports/discounts', [SalesReportController::class, 'discounts']);
        Route::get('/reports/voids', [VoidRefundReportController::class, 'voids']);
        Route::get('/reports/refunds', [VoidRefundReportController::class, 'refunds']);
        Route::get('/reports/inventory-on-hand', [InventoryReportController::class, 'inventoryOnHand']);
        Route::get('/reports/low-stock', [InventoryReportController::class, 'lowStock']);
        Route::get('/reports/inventory-movement', [InventoryReportController::class, 'inventoryMovement']);
        Route::get('/reports/shifts', [ShiftReportController::class, 'shifts']);
        Route::get('/reports/cash-variance', [ShiftReportController::class, 'cashVariance']);
    });
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
