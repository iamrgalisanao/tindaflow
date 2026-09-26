<?php

namespace Tests\Database;

use App\Models\FiscalInstallation;
use App\Models\InventoryLocation;
use App\Models\InvoiceSeries;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A6 -- SaleController@finalize, the HTTP layer over Stage 6C's already-
 * VERIFIED CheckoutService (see stage-6c-sale-finalization.md). Proves
 * exactly the "Authentication boundary" the same document already
 * specifies: terminal_id/cashier_id are resolved exclusively from
 * PosRequestContext (A4), never the request body, and re-verifies §13
 * matrix items 13a/17 (deferred at A5, pending this controller).
 */
class SaleFinalizationHttpTest extends PostgresSchemaTestCase
{
    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function forwardSessionCookie(TestResponse $prior): static
    {
        $this->app['auth']->forgetGuards();

        $cookie = collect($prior->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    private function withTerminalCredential(TestResponse $enrollResponse): static
    {
        $cookie = collect($enrollResponse->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal');

        return $this->withUnencryptedCookie('tindaflow_terminal', $cookie?->getValue() ?? '')->withCredentials();
    }

    /**
     * Builds one fully checkout-ready shift (terminal + fiscal_day +
     * cashier, all coherent) plus every setup row CheckoutService's
     * resolvers require, mirroring CheckoutServiceTest::readyToCheckout()
     * exactly -- Stage 6C's own service layer is not re-verified here,
     * only that the HTTP layer reaches it with a trustworthy context.
     *
     * @return array{shift: Shift, product: Product, terminalCredential: TestResponse}
     */
    private function readyToCheckoutViaHttp(): array
    {
        $shift = Shift::factory()->create();
        $storeId = $shift->fiscalDay->store_id;
        $terminalId = $shift->terminal_id;

        $fiscalInstallation = FiscalInstallation::factory()->create(['store_id' => $storeId]);
        InvoiceSeries::factory()->create(['store_id' => $storeId, 'fiscal_installation_id' => $fiscalInstallation->id]);

        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'terminal_id' => $terminalId,
            'fiscal_installation_id' => $fiscalInstallation->id,
            'effective_from' => now()->subYear(),
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        InventoryLocation::factory()->create(['store_id' => $storeId, 'is_default' => true]);

        DB::table('tax_registrations')->insert([
            'id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'registration_type' => 'VAT',
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::factory()->create(['store_id' => $storeId, 'selling_price' => '100.00', 'cost' => '60.00']);

        // Terminal enrollment is a back-office (TERMINAL_MANAGE) action --
        // the shift's own CASHIER cannot do this, matching real deployment
        // (an admin enrolls the terminal; a cashier logs into it later).
        $admin = User::factory()->admin()->create(['store_id' => $storeId]);
        $adminLogin = $this->login($admin);
        $tokenResponse = $this->forwardSessionCookie($adminLogin)
            ->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminalId]);
        $terminalCredential = $this->forwardSessionCookie($adminLogin)
            ->postJson('/api/v1/terminal/enroll', ['token' => $tokenResponse->json('token')]);

        return ['shift' => $shift, 'product' => $product, 'terminalCredential' => $terminalCredential];
    }

    public function test_successful_checkout_returns_the_finalized_sale(): void
    {
        ['shift' => $shift, 'product' => $product, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $cashierLogin = $this->login($shift->cashier);
        $idempotencyKey = (string) Str::uuid();

        $response = $this->forwardSessionCookie($cashierLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => '2']],
                'payments' => [['method' => 'CASH', 'amount' => '200.00']],
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'COMPLETED',
            'terminal_id' => $shift->terminal_id,
            'cashier_id' => $shift->cashier_id,
            'shift_id' => $shift->id,
            'grand_total' => '200.00',
        ]);
        $this->assertNotEmpty($response->json('transaction_number'));
        $this->assertNotNull($response->json('invoice_number'));
        $this->assertCount(1, $response->json('items'));
        $this->assertSame('200.00', $response->json('amount_tendered'));
        $this->assertSame('0.00', $response->json('change'));
    }

    /** §13 item 14/15 re-proof against the real controller: request-body identity fields are structurally impossible to spoof (not even accepted by SaleFinalizeRequest's rules). */
    public function test_request_body_cannot_spoof_terminal_or_cashier_identity(): void
    {
        ['shift' => $shift, 'product' => $product, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $cashierLogin = $this->login($shift->cashier);
        $otherTerminal = (string) Str::uuid();
        $otherCashier = (string) Str::uuid();

        $response = $this->forwardSessionCookie($cashierLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'terminal_id' => $otherTerminal,
                'cashier_id' => $otherCashier,
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'payments' => [['method' => 'CASH', 'amount' => '100.00']],
            ]);

        $response->assertStatus(201);
        $response->assertJson(['terminal_id' => $shift->terminal_id, 'cashier_id' => $shift->cashier_id]);
    }

    public function test_missing_idempotency_key_header_is_rejected(): void
    {
        ['shift' => $shift, 'product' => $product, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $cashierLogin = $this->login($shift->cashier);

        $response = $this->forwardSessionCookie($cashierLogin)->withTerminalCredential($terminalCredential)
            ->postJson('/api/v1/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'payments' => [['method' => 'CASH', 'amount' => '100.00']],
            ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED']]);
    }

    /** §13 item 17 re-proof against the real saleFinalize (deferred at A5). */
    public function test_checkout_without_a_terminal_credential_is_rejected(): void
    {
        $shift = Shift::factory()->create();
        $cashierLogin = $this->login($shift->cashier);

        $response = $this->forwardSessionCookie($cashierLogin)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'items' => [['product_id' => (string) Str::uuid(), 'quantity' => '1']],
                'payments' => [['method' => 'CASH', 'amount' => '100.00']],
            ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
    }

    public function test_unauthenticated_checkout_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/sales', []);

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }

    /** §13 item 13a re-proof against the real controller (deferred at A5): a shift open for a different cashier on this terminal is not usable. */
    public function test_shift_belonging_to_a_different_authenticated_cashier_is_rejected(): void
    {
        ['shift' => $shift, 'product' => $product, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $otherCashier = User::factory()->create(['store_id' => $shift->fiscalDay->store_id]);
        $otherLogin = $this->login($otherCashier);

        $response = $this->forwardSessionCookie($otherLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'payments' => [['method' => 'CASH', 'amount' => '100.00']],
            ]);

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'SHIFT_REQUIRED']]);
    }

    /** §13 item 13 re-proof: a Store-A cashier holding a Store-B terminal credential cannot check out at all -- A4's coherence check fires before CheckoutService is ever reached. */
    /** domain-model.md SS2.1: a product from another store is indistinguishable from a nonexistent one at checkout. */
    public function test_checkout_cannot_sell_a_product_belonging_to_another_store(): void
    {
        ['shift' => $shift, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $foreignProduct = Product::factory()->create(['selling_price' => '100.00', 'cost' => '60.00']);
        $cashierLogin = $this->login($shift->cashier);

        $response = $this->forwardSessionCookie($cashierLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'items' => [['product_id' => $foreignProduct->id, 'quantity' => '1']],
                'payments' => [['method' => 'CASH', 'amount' => '100.00']],
            ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'PRODUCT_NOT_FOUND']]);
    }

    public function test_checkout_rejects_a_cross_store_terminal_credential(): void
    {
        ['shift' => $shift, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $otherStoreCashier = User::factory()->create(['store_id' => Store::factory()]);
        $otherLogin = $this->login($otherStoreCashier);
        $this->assertNotSame($shift->fiscalDay->store_id, $otherStoreCashier->store_id);

        $response = $this->forwardSessionCookie($otherLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', []);

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
    }

    /** A CASHIER (the shift factory's default role) has no DISCOUNT_OVERRIDE; the HTTP layer renders the same 403 AUTHORIZATION_DENIED envelope CashMovementService's CASH_OUT check already uses. */
    public function test_a_cashier_checkout_with_a_discount_is_rejected(): void
    {
        ['shift' => $shift, 'product' => $product, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $this->assertSame('CASHIER', $shift->cashier->role);
        $cashierLogin = $this->login($shift->cashier);

        $response = $this->forwardSessionCookie($cashierLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'order_level_discount_amount' => '10.00',
                'payments' => [['method' => 'CASH', 'amount' => '90.00']],
            ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_a_manager_checkout_with_a_discount_succeeds(): void
    {
        ['shift' => $shift, 'product' => $product, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $shift->cashier->update(['role' => 'MANAGER']);
        $managerLogin = $this->login($shift->cashier);

        $response = $this->forwardSessionCookie($managerLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'order_level_discount_amount' => '10.00',
                'payments' => [['method' => 'CASH', 'amount' => '90.00']],
            ]);

        $response->assertStatus(201);
        $response->assertJson(['grand_total' => '90.00']);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'DISCOUNT_APPLIED')->count());
    }

    public function test_a_cashier_checkout_with_a_statutory_discount_succeeds_without_discount_override(): void
    {
        ['shift' => $shift, 'product' => $product, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $this->assertSame('CASHIER', $shift->cashier->role);
        $cashierLogin = $this->login($shift->cashier);

        $response = $this->forwardSessionCookie($cashierLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'statutory_discount' => ['type' => 'SENIOR_CITIZEN', 'id_number' => 'OSCA-00123', 'name' => 'Juana Dela Cruz'],
                'payments' => [['method' => 'CASH', 'amount' => '71.43']],
            ]);

        $response->assertStatus(201);
        $response->assertJson(['grand_total' => '71.43']);
    }

    public function test_a_statutory_discount_requires_a_beneficiary_name_and_id_number(): void
    {
        ['shift' => $shift, 'product' => $product, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $cashierLogin = $this->login($shift->cashier);

        $response = $this->forwardSessionCookie($cashierLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'statutory_discount' => ['type' => 'SENIOR_CITIZEN'],
                'payments' => [['method' => 'CASH', 'amount' => '71.43']],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $details = $response->json('error.details');
        $this->assertArrayHasKey('statutory_discount.id_number', $details);
        $this->assertArrayHasKey('statutory_discount.name', $details);
    }

    public function test_a_statutory_discount_type_must_be_senior_citizen_or_pwd(): void
    {
        ['shift' => $shift, 'product' => $product, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $cashierLogin = $this->login($shift->cashier);

        $response = $this->forwardSessionCookie($cashierLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'statutory_discount' => ['type' => 'SOLO_PARENT', 'id_number' => '123', 'name' => 'Someone'],
                'payments' => [['method' => 'CASH', 'amount' => '71.43']],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertArrayHasKey('statutory_discount.type', $response->json('error.details'));
    }

    public function test_a_statutory_discount_rule_must_be_a_known_rule(): void
    {
        ['shift' => $shift, 'product' => $product, 'terminalCredential' => $terminalCredential] = $this->readyToCheckoutViaHttp();
        $cashierLogin = $this->login($shift->cashier);

        $response = $this->forwardSessionCookie($cashierLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'statutory_discount' => ['type' => 'PWD', 'rule' => 'TEN_PERCENT', 'weekly_discount_used' => '12.5', 'id_number' => '1', 'name' => 'Someone'],
                'payments' => [['method' => 'CASH', 'amount' => '100.00']],
            ]);

        $response->assertStatus(422);
        $details = $response->json('error.details');
        $this->assertArrayHasKey('statutory_discount.rule', $details);
        $this->assertArrayHasKey('statutory_discount.weekly_discount_used', $details);
    }
}
