<?php

use App\Http\Controllers\AuditEventController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CreditOverrideController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FiscalDayController;
use App\Http\Controllers\FiscalInstallationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryLocationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceSeriesController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\MenuGroupController;
use App\Http\Controllers\MenuPriceController;
use App\Http\Controllers\ProductBarcodeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\Reports\InventoryReportController;
use App\Http\Controllers\Reports\SalesReportController;
use App\Http\Controllers\Reports\ShiftReportController;
use App\Http\Controllers\Reports\VoidRefundReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleDocumentSettingsController;
use App\Http\Controllers\SaleSlipController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StoreSettingsController;
use App\Http\Controllers\StoreSetupController;
use App\Http\Controllers\TaxRegistrationController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\TerminalPairingController;
use App\Http\Controllers\TillMenuController;
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
Route::prefix('api/v1')->middleware('throttle:api')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware(['auth', EnsureUserIsActive::class]);

    // openapi.yaml Terminal tag (ADR-011, A3). terminalCreateEnrollmentToken/
    // terminalEnroll/terminalList/terminalGet/terminalRevoke require only
    // the human session + TERMINAL_MANAGE (back-office, no terminal
    // credential needed yet -- that's what these operations establish/
    // manage).
    Route::post('/terminals', [TerminalController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:TERMINAL_MANAGE']);
    Route::post('/terminal-enrollment-tokens', [TerminalController::class, 'createEnrollmentToken'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:TERMINAL_MANAGE']);
    // ADR-015: remote till approval. The browser that wants to be a till (any signed-in user, any role) asks and then claims; an
    // administrator anywhere lists, approves with the code read from that browser's screen, or declines.
    Route::post('/terminal/pairing-requests', [TerminalPairingController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'throttle:pairing']);
    Route::post('/terminal/pairing-requests/{requestId}/claim', [TerminalPairingController::class, 'claim'])->whereUuid('requestId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/terminal/pairing-requests', [TerminalPairingController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:TERMINAL_MANAGE']);
    Route::post('/terminal/pairing-requests/{requestId}/approve', [TerminalPairingController::class, 'approve'])->whereUuid('requestId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:TERMINAL_MANAGE']);
    Route::post('/terminal/pairing-requests/{requestId}/reject', [TerminalPairingController::class, 'reject'])->whereUuid('requestId')
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

    // openapi.yaml SaleSlips tag (ADR-016 slice 2.2a), the slip-mode twin of Invoices above. saleSlipGet: session only, store-scoped,
    // the plain original rendered from the immutable snapshot. saleSlipReprint: enrolled terminal + Idempotency-Key, no dedicated
    // capability; it never allocates a number and writes a SLIP_REPRINTED audit event.
    Route::get('/sale-slips/{slipId}', [SaleSlipController::class, 'get'])->whereUuid('slipId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/sale-slips/{slipId}/reprints', [SaleSlipController::class, 'reprint'])->whereUuid('slipId')
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);

    // openapi.yaml SaleDocumentSettings tag: how the store documents a sale (invoice or slip) and the slip footer line. The mode switch
    // is the ADR-014 vendor unlock (FISCAL_CONFIGURATION_MANAGE); the footer is ordinary store configuration.
    Route::get('/sale-document-settings', [SaleDocumentSettingsController::class, 'get'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::put('/sale-document-settings/mode', [SaleDocumentSettingsController::class, 'setMode'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);
    Route::put('/sale-document-settings/slip-footer', [SaleDocumentSettingsController::class, 'setSlipFooter'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:STORE_SETTINGS_MANAGE']);

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
    // History reads (shiftList/shiftGet/shiftXReadingList) are session-only and store-scoped, like saleList.
    Route::get('/shifts', [ShiftController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/shifts/{shiftId}', [ShiftController::class, 'get'])->whereUuid('shiftId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/shifts/{shiftId}/x-readings', [ShiftController::class, 'listXReadings'])->whereUuid('shiftId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/shifts/{shiftId}/x-readings', [ShiftController::class, 'createXReading'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);

    // openapi.yaml FiscalDay tag. fiscalDayClose additionally requires
    // FISCAL_DAY_CLOSE (x-capability) on top of the terminal-scoped
    // chain -- an admin/manager action, not any cashier's.
    Route::post('/fiscal-days/{fiscalDayId}/close', [FiscalDayController::class, 'close'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class, 'can:FISCAL_DAY_CLOSE']);
    // History reads (fiscalDayList/fiscalDayGet/fiscalDayZReadingGet) are session-only and store-scoped.
    Route::get('/fiscal-days', [FiscalDayController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/fiscal-days/{fiscalDayId}', [FiscalDayController::class, 'get'])->whereUuid('fiscalDayId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::get('/fiscal-days/{fiscalDayId}/z-reading', [FiscalDayController::class, 'getZReading'])->whereUuid('fiscalDayId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    // Z-reading paper (one operation; the SERVER decides which printout is the original): the first print of a reading is the plain
    // original, every later one a marked, recorded copy. Store-scoped and REPORT_VIEW (the end-of-day totals are the owner's and
    // managers', not a cashier's), and rate-limited because every print writes an audit event.
    Route::post('/fiscal-days/{fiscalDayId}/z-reading/prints', [FiscalDayController::class, 'zReadingPrint'])->whereUuid('fiscalDayId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:REPORT_VIEW', 'throttle:reprints']);

    // openapi.yaml Catalog tag. Reads need only the session (operation-inventory.md);
    // every mutation is CATALOG_MANAGE. No terminal credential. {productId} is
    // constrained to a UUID so the literal paths (/products/import, /products/export,
    // /products/by-barcode/...) can never be captured by it.
    Route::get('/products', [ProductController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/products', [ProductController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    // productImport (CATALOG_MANAGE) and productExport (any authenticated user, like productList): the CSV
    // layout is App\Services\Catalog\ProductCsv.
    Route::post('/products/import', [ProductController::class, 'import'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::get('/products/export', [ProductController::class, 'export'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    // productLookupByBarcode is a cashier-scanning operation (operation-inventory.md: session + enrolled
    // terminal, no capability), unlike the catalog reads around it which need only the session.
    Route::get('/products/by-barcode/{barcode}', [ProductController::class, 'lookupByBarcode'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);
    Route::get('/products/{productId}', [ProductController::class, 'get'])->whereUuid('productId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::patch('/products/{productId}', [ProductController::class, 'update'])->whereUuid('productId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::post('/products/{productId}/activate', [ProductController::class, 'activate'])->whereUuid('productId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::post('/products/{productId}/deactivate', [ProductController::class, 'deactivate'])->whereUuid('productId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    // Alternate barcodes (stage 26, forward-committed; docs/06-backend/stage-26-alternate-barcodes.md): reads need
    // only the session like the other catalog reads, adding and removing are CATALOG_MANAGE.
    Route::get('/products/{productId}/barcodes', [ProductBarcodeController::class, 'list'])->whereUuid('productId')
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/products/{productId}/barcodes', [ProductBarcodeController::class, 'create'])->whereUuid('productId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::delete('/products/{productId}/barcodes/{barcodeId}', [ProductBarcodeController::class, 'delete'])->whereUuid(['productId', 'barcodeId'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);

    Route::get('/categories', [CategoryController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/categories', [CategoryController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::get('/brands', [BrandController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/brands', [BrandController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);

    // Menu groups (ADR-016, slice 1.1): the till's navigation tree, two levels. Reads need only the session like the other
    // catalog reads; every change is CATALOG_MANAGE. There is no delete: a group is hidden with deactivate (MENU-005).
    Route::get('/menu-groups', [MenuGroupController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/menu-groups', [MenuGroupController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::post('/menu-groups/reorder', [MenuGroupController::class, 'reorder'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::patch('/menu-groups/{menuGroupId}', [MenuGroupController::class, 'update'])->whereUuid('menuGroupId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::post('/menu-groups/{menuGroupId}/activate', [MenuGroupController::class, 'activate'])->whereUuid('menuGroupId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);
    Route::post('/menu-groups/{menuGroupId}/deactivate', [MenuGroupController::class, 'deactivate'])->whereUuid('menuGroupId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE']);

    // The till's read-only meal menu (ADR-016, slice 1.3): any signed-in user of the store, no capability.
    Route::get('/menu', [TillMenuController::class, 'show'])
        ->middleware(['auth', EnsureUserIsActive::class]);

    // Supervisor override of the credit limit (ADR-016 section 6, slice 3.3). The approver list is for any signed-in till user (names only).
    // creditOverrideGrant is terminal-scoped like checkout, verifies a NAMED approver's code and issues a single-use grant; it is limited
    // per terminal and per user (config tindaflow.credit_override.throttle_*) on top of the per-approver lockout. An approver sets their
    // own code (CREDIT_LIMIT_OVERRIDE, password re-entered, limited per user); an administrator lists who holds one and clears one
    // (USER_MANAGE, password re-entered). The literal /users/override-codes path is registered before /users/{userId} below.
    Route::get('/credit-override-approvers', [CreditOverrideController::class, 'approvers'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/credit-overrides', [CreditOverrideController::class, 'grant'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class, 'throttle:credit-override']);
    Route::get('/credit-override-code', [CreditOverrideController::class, 'codeGet'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CREDIT_LIMIT_OVERRIDE']);
    Route::put('/credit-override-code', [CreditOverrideController::class, 'codeSet'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:CREDIT_LIMIT_OVERRIDE', 'throttle:override-code']);
    Route::get('/credit-override-codes', [CreditOverrideController::class, 'codeList'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:USER_MANAGE']);
    Route::post('/users/{userId}/override-code/reset', [CreditOverrideController::class, 'codeReset'])->whereUuid('userId')
        ->middleware(['auth', EnsureUserIsActive::class, 'can:USER_MANAGE', 'throttle:override-code']);

    // Employees and credit limits (ADR-016 section 5, slice 3.1). employeeLookup is the one till operation: any signed-in user, one
    // employee by exact code, no listing. Everything else, reads included, is EMPLOYEE_MANAGE (ADMIN and MANAGER; a cashier gets 403).
    // Amendment 2026-10-26: changing a credit limit and the CSV import are EMPLOYEE_POLICY_MANAGE (ADMIN only); a manager may read the limits.
    // No delete: an employee is deactivated and the code is never reissued (EMP-001). The literal paths come before {employeeId}.
    // Slice 3.2: the lookup now also carries the credit status, and employeeCreditStatusGet refreshes it. Codes are sequential, so both
    // share one named limiter (config tindaflow.employee_lookup_throttle, per signed-in user) that stops a session from walking the code space.
    Route::get('/employees/lookup', [EmployeeController::class, 'lookup'])
        ->middleware(['auth', EnsureUserIsActive::class, 'throttle:employee-lookup']);
    Route::get('/employees/{employeeId}/credit-status', [EmployeeController::class, 'creditStatus'])->whereUuid('employeeId')
        ->middleware(['auth', EnsureUserIsActive::class, 'throttle:employee-lookup']);
    Route::middleware(['auth', EnsureUserIsActive::class, 'can:EMPLOYEE_MANAGE'])->group(function () {
        Route::get('/employees', [EmployeeController::class, 'list']);
        Route::post('/employees', [EmployeeController::class, 'create']);
        Route::get('/employees/id-cards', [EmployeeController::class, 'idCards']);
        Route::get('/employees/{employeeId}', [EmployeeController::class, 'get'])->whereUuid('employeeId');
        Route::patch('/employees/{employeeId}', [EmployeeController::class, 'update'])->whereUuid('employeeId');
        Route::post('/employees/{employeeId}/activate', [EmployeeController::class, 'activate'])->whereUuid('employeeId');
        Route::post('/employees/{employeeId}/deactivate', [EmployeeController::class, 'deactivate'])->whereUuid('employeeId');

        Route::get('/credit-policies', [EmployeeController::class, 'creditPolicies']);
    });
    Route::middleware(['auth', EnsureUserIsActive::class, 'can:EMPLOYEE_POLICY_MANAGE'])->group(function () {
        Route::post('/employees/import', [EmployeeController::class, 'import']);
        Route::put('/credit-policies/{employeeType}', [EmployeeController::class, 'creditPolicySetLimit']);
    });

    // Meal menu configuration (ADR-016, slice 1.2): dish categories, dishes, meals, option groups, options and prices. Every
    // operation, reads included, is CATALOG_MANAGE (the till gets its own read-only payload in slice 1.3). There is no delete:
    // hiding is deactivate (MENU-005); a price edit appends a row (MENU-004).
    Route::middleware(['auth', EnsureUserIsActive::class, 'can:CATALOG_MANAGE'])->group(function () {
        Route::get('/dish-categories', [MenuController::class, 'dishCategoryList']);
        Route::post('/dish-categories', [MenuController::class, 'dishCategoryCreate']);
        Route::post('/dish-categories/reorder', [MenuController::class, 'dishCategoryReorder']);
        Route::patch('/dish-categories/{dishCategoryId}', [MenuController::class, 'dishCategoryUpdate'])->whereUuid('dishCategoryId');
        Route::post('/dish-categories/{dishCategoryId}/activate', [MenuController::class, 'dishCategoryActivate'])->whereUuid('dishCategoryId');
        Route::post('/dish-categories/{dishCategoryId}/deactivate', [MenuController::class, 'dishCategoryDeactivate'])->whereUuid('dishCategoryId');

        Route::get('/dishes', [MenuController::class, 'dishList']);
        Route::post('/dishes', [MenuController::class, 'dishCreate']);
        Route::post('/dishes/reorder', [MenuController::class, 'dishReorder']);
        Route::patch('/dishes/{dishId}', [MenuController::class, 'dishUpdate'])->whereUuid('dishId');
        Route::post('/dishes/{dishId}/activate', [MenuController::class, 'dishActivate'])->whereUuid('dishId');
        Route::post('/dishes/{dishId}/deactivate', [MenuController::class, 'dishDeactivate'])->whereUuid('dishId');

        Route::get('/meals', [MenuController::class, 'mealList']);
        Route::post('/meals', [MenuController::class, 'mealCreate']);
        Route::post('/meals/reorder', [MenuController::class, 'mealReorder']);
        Route::get('/meals/{mealId}', [MenuController::class, 'mealGet'])->whereUuid('mealId');
        Route::patch('/meals/{mealId}', [MenuController::class, 'mealUpdate'])->whereUuid('mealId');
        Route::post('/meals/{mealId}/activate', [MenuController::class, 'mealActivate'])->whereUuid('mealId');
        Route::post('/meals/{mealId}/deactivate', [MenuController::class, 'mealDeactivate'])->whereUuid('mealId');
        Route::post('/meals/{mealId}/option-groups', [MenuController::class, 'optionGroupCreate'])->whereUuid('mealId');
        Route::post('/meals/{mealId}/option-groups/reorder', [MenuController::class, 'optionGroupReorder'])->whereUuid('mealId');

        Route::patch('/meal-option-groups/{groupId}', [MenuController::class, 'optionGroupUpdate'])->whereUuid('groupId');
        Route::post('/meal-option-groups/{groupId}/activate', [MenuController::class, 'optionGroupActivate'])->whereUuid('groupId');
        Route::post('/meal-option-groups/{groupId}/deactivate', [MenuController::class, 'optionGroupDeactivate'])->whereUuid('groupId');
        Route::post('/meal-option-groups/{groupId}/options', [MenuController::class, 'optionCreate'])->whereUuid('groupId');
        Route::post('/meal-option-groups/{groupId}/options/reorder', [MenuController::class, 'optionReorder'])->whereUuid('groupId');

        Route::patch('/meal-options/{optionId}', [MenuController::class, 'optionUpdate'])->whereUuid('optionId');
        Route::post('/meal-options/{optionId}/activate', [MenuController::class, 'optionActivate'])->whereUuid('optionId');
        Route::post('/meal-options/{optionId}/deactivate', [MenuController::class, 'optionDeactivate'])->whereUuid('optionId');

        Route::get('/meals/{mealId}/prices', [MenuPriceController::class, 'grid'])->whereUuid('mealId');
        Route::post('/meals/{mealId}/prices', [MenuPriceController::class, 'set'])->whereUuid('mealId');
        Route::get('/meals/{mealId}/prices/history', [MenuPriceController::class, 'history'])->whereUuid('mealId');
    });

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

    // Stock counts and transfers between a store's own locations (stage 25, forward-committed; see
    // docs/06-backend/stage-25-stock-counts-and-transfers.md). Everything needs STOCK_ADJUST. Drafting a count
    // is session-only; the two operations that write the ledger (posting a count, creating a transfer) carry
    // the terminal chain and an Idempotency-Key like every other stock write.
    Route::middleware(['auth', EnsureUserIsActive::class, 'can:STOCK_ADJUST'])->group(function () {
        Route::get('/inventory/counts', [StockCountController::class, 'list']);
        Route::post('/inventory/counts', [StockCountController::class, 'create']);
        Route::get('/inventory/counts/{stockCountId}', [StockCountController::class, 'get'])->whereUuid('stockCountId');
        Route::put('/inventory/counts/{stockCountId}/lines', [StockCountController::class, 'recordLines'])->whereUuid('stockCountId');
        Route::delete('/inventory/counts/{stockCountId}/lines/{productId}', [StockCountController::class, 'removeLine'])->whereUuid(['stockCountId', 'productId']);
        Route::post('/inventory/counts/{stockCountId}/cancel', [StockCountController::class, 'cancel'])->whereUuid('stockCountId');
        Route::get('/inventory/transfers', [StockTransferController::class, 'list']);
        Route::get('/inventory/transfers/{stockTransferId}', [StockTransferController::class, 'get'])->whereUuid('stockTransferId');
    });
    Route::post('/inventory/counts/{stockCountId}/post', [StockCountController::class, 'post'])->whereUuid('stockCountId')
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class, 'can:STOCK_ADJUST']);
    Route::post('/inventory/transfers', [StockTransferController::class, 'create'])
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
    // ADR-014: the fiscal configuration is read-only for a store's administrators until the server operator unlocks it
    // (FISCAL_CONFIGURATION_MANAGE). Reading it is the administrators' (STORE_SETTINGS_MANAGE), not any signed-in user's: the
    // installation list carries the accreditation and permit numbers and the machine serial, which a cashier has no need of.
    Route::get('/fiscal-installations', [FiscalInstallationController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:STORE_SETTINGS_MANAGE']);
    Route::post('/fiscal-installations', [FiscalInstallationController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);
    Route::post('/fiscal-installations/{fiscalInstallationId}/terminals', [FiscalInstallationController::class, 'assignTerminal'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);

    Route::get('/tax-registrations', [TaxRegistrationController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/tax-registrations', [TaxRegistrationController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);

    Route::get('/invoice-series', [InvoiceSeriesController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:STORE_SETTINGS_MANAGE']);
    Route::post('/invoice-series', [InvoiceSeriesController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);
    Route::post('/invoice-series/{invoiceSeriesId}/close', [InvoiceSeriesController::class, 'close'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:FISCAL_CONFIGURATION_MANAGE']);

    // Listing is session-only (like the tax-registration list): a location is just a name, and the stock,
    // count and transfer screens of a MANAGER (STOCK_ADJUST without STORE_SETTINGS_MANAGE) need it to show
    // where stock is. Creating and editing need STORE_SETTINGS_MANAGE (ADR-014: stock locations are the shop's own,
    // not part of the locked fiscal configuration).
    Route::get('/inventory-locations', [InventoryLocationController::class, 'list'])
        ->middleware(['auth', EnsureUserIsActive::class]);
    Route::post('/inventory-locations', [InventoryLocationController::class, 'create'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:STORE_SETTINGS_MANAGE']);
    Route::patch('/inventory-locations/{inventoryLocationId}', [InventoryLocationController::class, 'update'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:STORE_SETTINGS_MANAGE']);

    // Terminal-scoped like shiftCurrentGet -- no x-capability, since a
    // cashier on an enrolled terminal needs this readiness check too, not
    // just an admin.
    // The store-wide view of the same checks (storeSetupOverviewGet), for the Store Setup overview: session only, since
    // an admin may have no terminal enrolled on the browser they are using.
    Route::get('/store-setup/overview', [StoreSetupController::class, 'overview'])
        ->middleware(['auth', EnsureUserIsActive::class, 'can:STORE_SETTINGS_MANAGE']);
    Route::get('/store-setup/readiness', [StoreSetupController::class, 'readiness'])
        ->middleware(['auth', EnsureUserIsActive::class, ResolveTerminalContext::class, ComposeAuthoritativeContext::class]);

    // openapi.yaml Reports tag (15 operations, +2 forward-committed in Stage 32: reportSalesByHour,
    // reportGrossProfit -- see docs/06-backend/stage-32-hourly-and-gross-profit-reports.md; +2 more
    // in Stage 34: reportGrossProfitByProduct, reportProductVelocity -- see
    // docs/06-backend/stage-34-product-velocity-and-profit-by-product.md; +1 more in Stage 35:
    // reportGrossProfitByCategory -- see docs/06-backend/stage-35-gross-profit-by-category.md) --
    // session-only, no terminal credential, all REPORT_VIEW. No domain-specific failure mode exists
    // for any of these (operation-inventory.md: "reports never fail on business state, only on auth").
    Route::middleware(['auth', EnsureUserIsActive::class, 'can:REPORT_VIEW'])->group(function () {
        Route::get('/reports/daily-sales-summary', [SalesReportController::class, 'dailySalesSummary']);
        Route::get('/reports/sales-by-date-range', [SalesReportController::class, 'salesByDateRange']);
        Route::get('/reports/sales-by-product', [SalesReportController::class, 'salesByProduct']);
        Route::get('/reports/sales-by-category', [SalesReportController::class, 'salesByCategory']);
        Route::get('/reports/sales-by-cashier', [SalesReportController::class, 'salesByCashier']);
        Route::get('/reports/sales-by-payment-method', [SalesReportController::class, 'salesByPaymentMethod']);
        Route::get('/reports/tax-breakdown', [SalesReportController::class, 'taxBreakdown']);
        Route::get('/reports/discounts', [SalesReportController::class, 'discounts']);
        Route::get('/reports/sales-by-hour', [SalesReportController::class, 'salesByHour']);
        Route::get('/reports/gross-profit', [SalesReportController::class, 'grossProfit']);
        Route::get('/reports/gross-profit-by-product', [SalesReportController::class, 'grossProfitByProduct']);
        Route::get('/reports/product-velocity', [SalesReportController::class, 'productVelocity']);
        Route::get('/reports/gross-profit-by-category', [SalesReportController::class, 'grossProfitByCategory']);
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
