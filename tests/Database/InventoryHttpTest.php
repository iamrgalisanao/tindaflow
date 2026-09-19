<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * openapi.yaml Inventory tag -- inventoryReceiptCreate / inventoryAdjustmentCreate (terminal-scoped,
 * idempotent, STOCK_ADJUST) and the three session-only reads. No new error code: a blank reason is the
 * existing STOCK_ADJUSTMENT_REASON_REQUIRED and an unknown product the existing PRODUCT_NOT_FOUND.
 */
class InventoryHttpTest extends PostgresSchemaTestCase
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

    /** Each actor gets a fresh session and, unless given a terminal credential, no terminal. */
    private function asUser(User $user, ?TestResponse $terminalEnrollment = null): static
    {
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];

        $acting = $this->forwardSessionCookie($this->login($user));
        if ($terminalEnrollment === null) {
            return $acting;
        }
        $cookie = collect($terminalEnrollment->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal');

        return $acting->withUnencryptedCookie('tindaflow_terminal', $cookie?->getValue() ?? '')->withCredentials();
    }

    /**
     * A store with a default location, an enrolled terminal, and an admin, a manager and a cashier in it.
     *
     * @return array{admin: User, manager: User, cashier: User, terminal: Terminal, enrollment: TestResponse, location: InventoryLocation}
     */
    private function store(): array
    {
        $terminal = Terminal::factory()->create();
        $storeId = $terminal->store_id;
        $admin = User::factory()->admin()->create(['store_id' => $storeId]);
        $manager = User::factory()->manager()->create(['store_id' => $storeId]);
        $cashier = User::factory()->create(['store_id' => $storeId]);
        $location = InventoryLocation::factory()->create(['store_id' => $storeId, 'is_default' => true]);

        $login = $this->forwardSessionCookie($this->login($admin));
        $token = $login->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);
        $enrollment = $this->forwardSessionCookie($this->login($admin))->postJson('/api/v1/terminal/enroll', ['token' => $token->json('token')]);

        return compact('admin', 'manager', 'cashier', 'terminal', 'enrollment', 'location');
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function balance(Product $product): ?string
    {
        return StockBalance::where('product_id', $product->id)->first()?->quantity_on_hand;
    }

    // -------------------------------------------------------------- receipts

    public function test_a_receipt_records_a_movement_raises_the_balance_and_is_audited(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);

        $response = $this->asUser($s['admin'], $s['enrollment'])->postJson('/api/v1/inventory/receipts', [
            'product_id' => $product->id, 'quantity' => '12.5', 'movement_type' => 'PURCHASE_RECEIPT', 'unit_cost' => '40.00', 'note' => 'Supplier delivery',
        ], $this->key());

        $response->assertStatus(201);
        $response->assertJson([
            'product_id' => $product->id,
            'location_id' => $s['location']->id,
            'terminal_id' => $s['terminal']->id,
            'movement_type' => 'PURCHASE_RECEIPT',
            'quantity' => '12.500',
            'unit_cost' => '40.00',
            'reason' => 'Supplier delivery',
            'reference_type' => 'audit_event',
            'created_by' => $s['admin']->id,
        ]);
        $this->assertSame('12.500', $this->balance($product));

        $audit = AuditEvent::findOrFail($response->json('reference_id'));
        $this->assertSame('STOCK_ADJUSTED', $audit->event_type);
        $this->assertSame($product->id, $audit->entity_id);
        $this->assertSame('PURCHASE_RECEIPT', $audit->after_metadata['movement_type']);
        $this->assertDatabaseHas('electronic_journal_entries', [
            'event_type' => 'STOCK_ADJUSTED', 'source_type' => 'stock_movement', 'source_id' => $response->json('id'), 'audit_event_id' => $audit->id,
        ]);
    }

    public function test_opening_stock_and_a_receipt_without_cost_or_note_are_accepted(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);

        $response = $this->asUser($s['manager'], $s['enrollment'])->postJson('/api/v1/inventory/receipts', [
            'product_id' => $product->id, 'quantity' => '30', 'movement_type' => 'OPENING_STOCK',
        ], $this->key());

        $response->assertStatus(201);
        $this->assertNull($response->json('unit_cost'));
        $this->assertSame('30.000', $this->balance($product));
    }

    // ----------------------------------------------------------- adjustments

    public function test_adjustments_move_stock_in_the_direction_of_their_type(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);
        $acting = fn () => $this->asUser($s['admin'], $s['enrollment']);

        $acting()->postJson('/api/v1/inventory/receipts', ['product_id' => $product->id, 'quantity' => '20', 'movement_type' => 'OPENING_STOCK'], $this->key())->assertStatus(201);
        foreach ([['STOCK_ADJUSTMENT_IN', '5', '25.000'], ['STOCK_ADJUSTMENT_OUT', '2', '23.000'], ['DAMAGE', '1.5', '21.500'], ['EXPIRED', '1', '20.500']] as [$type, $quantity, $expected]) {
            $response = $acting()->postJson('/api/v1/inventory/adjustments', [
                'product_id' => $product->id, 'quantity' => $quantity, 'movement_type' => $type, 'reason' => 'Stock count',
            ], $this->key());

            $response->assertStatus(201);
            $response->assertJson(['movement_type' => $type, 'reason' => 'Stock count']);
            $this->assertSame($expected, $this->balance($product), $type);
        }
    }

    public function test_a_blank_or_missing_reason_is_stock_adjustment_reason_required_and_changes_nothing(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);

        foreach ([['reason' => ''], ['reason' => '   '], []] as $reason) {
            $response = $this->asUser($s['admin'], $s['enrollment'])->postJson('/api/v1/inventory/adjustments', [
                'product_id' => $product->id, 'quantity' => '1', 'movement_type' => 'STOCK_ADJUSTMENT_OUT', ...$reason,
            ], $this->key());

            $response->assertStatus(422);
            $response->assertJson(['error' => ['code' => 'STOCK_ADJUSTMENT_REASON_REQUIRED']]);
        }
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
        $this->assertNull($this->balance($product));
    }

    public function test_an_outflow_beyond_the_recorded_stock_is_recorded_and_the_balance_goes_negative(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);

        $this->asUser($s['admin'], $s['enrollment'])->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $product->id, 'quantity' => '3', 'movement_type' => 'DAMAGE', 'reason' => 'Dropped case',
        ], $this->key())->assertStatus(201);

        $this->assertSame('-3.000', $this->balance($product));
    }

    // ----------------------------------------------------------- idempotency

    public function test_the_idempotency_key_is_required(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);

        $this->asUser($s['admin'], $s['enrollment'])->postJson('/api/v1/inventory/receipts', [
            'product_id' => $product->id, 'quantity' => '1', 'movement_type' => 'PURCHASE_RECEIPT',
        ])->assertStatus(400)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED']]);
    }

    public function test_a_retried_request_returns_the_same_movement_and_counts_once(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);
        $headers = $this->key();
        $body = ['product_id' => $product->id, 'quantity' => '4', 'movement_type' => 'PURCHASE_RECEIPT'];

        $first = $this->asUser($s['admin'], $s['enrollment'])->postJson('/api/v1/inventory/receipts', $body, $headers);
        $second = $this->asUser($s['admin'], $s['enrollment'])->postJson('/api/v1/inventory/receipts', $body, $headers);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame('4.000', $this->balance($product));
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->count());
        $this->assertSame(1, ElectronicJournalEntry::where('source_id', $first->json('id'))->count());
    }

    public function test_the_same_key_with_a_different_body_is_a_conflict(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);
        $headers = $this->key();

        $this->asUser($s['admin'], $s['enrollment'])->postJson('/api/v1/inventory/receipts', ['product_id' => $product->id, 'quantity' => '4', 'movement_type' => 'PURCHASE_RECEIPT'], $headers)->assertStatus(201);
        $this->asUser($s['admin'], $s['enrollment'])->postJson('/api/v1/inventory/receipts', ['product_id' => $product->id, 'quantity' => '9', 'movement_type' => 'PURCHASE_RECEIPT'], $headers)
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED']]);

        $this->assertSame('4.000', $this->balance($product));
    }

    // ------------------------------------------------------------ validation

    public function test_quantity_shape_and_movement_type_are_validated(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);

        foreach ([
            ['/api/v1/inventory/receipts', ['quantity' => '0', 'movement_type' => 'PURCHASE_RECEIPT'], 'quantity'],
            ['/api/v1/inventory/receipts', ['quantity' => '-2', 'movement_type' => 'PURCHASE_RECEIPT'], 'quantity'],
            ['/api/v1/inventory/receipts', ['quantity' => '1.2345', 'movement_type' => 'PURCHASE_RECEIPT'], 'quantity'],
            ['/api/v1/inventory/receipts', ['quantity' => 'lots', 'movement_type' => 'PURCHASE_RECEIPT'], 'quantity'],
            ['/api/v1/inventory/receipts', ['quantity' => '1', 'movement_type' => 'STOCK_ADJUSTMENT_IN'], 'movement_type'],
            ['/api/v1/inventory/receipts', ['quantity' => '1', 'movement_type' => 'PURCHASE_RECEIPT', 'unit_cost' => '40'], 'unit_cost'],
            ['/api/v1/inventory/adjustments', ['quantity' => '1', 'movement_type' => 'PURCHASE_RECEIPT', 'reason' => 'x'], 'movement_type'],
        ] as [$uri, $body, $field]) {
            $response = $this->asUser($s['admin'], $s['enrollment'])->postJson($uri, $body + ['product_id' => $product->id], $this->key());

            $response->assertStatus(422);
            $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
            $this->assertArrayHasKey($field, $response->json('error.details'), $uri.' '.json_encode($body));
        }
        $this->assertNull($this->balance($product));
    }

    public function test_an_unknown_or_foreign_product_is_not_found_and_a_non_uuid_is_a_field_error(): void
    {
        $s = $this->store();
        $foreign = Product::factory()->create();
        $body = ['quantity' => '1', 'movement_type' => 'PURCHASE_RECEIPT'];

        foreach ([$foreign->id, (string) Str::uuid()] as $id) {
            $this->asUser($s['admin'], $s['enrollment'])->postJson('/api/v1/inventory/receipts', $body + ['product_id' => $id], $this->key())
                ->assertStatus(404)
                ->assertJson(['error' => ['code' => 'PRODUCT_NOT_FOUND']]);
        }
        $this->assertNull($this->balance($foreign));

        $this->asUser($s['admin'], $s['enrollment'])->postJson('/api/v1/inventory/receipts', $body + ['product_id' => 'nope'], $this->key())->assertStatus(422);
    }

    // ---------------------------------------------------------------- access

    public function test_an_unauthenticated_request_cannot_record_stock(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/v1/inventory/receipts', ['product_id' => $product->id, 'quantity' => '1', 'movement_type' => 'PURCHASE_RECEIPT'], $this->key())->assertStatus(401);
        $this->getJson('/api/v1/inventory/stock')->assertStatus(401);
    }

    public function test_writes_need_stock_adjust_and_an_enrolled_terminal(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);
        $body = ['product_id' => $product->id, 'quantity' => '1', 'movement_type' => 'PURCHASE_RECEIPT'];

        $this->asUser($s['cashier'], $s['enrollment'])->postJson('/api/v1/inventory/receipts', $body, $this->key())
            ->assertStatus(403)
            ->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);

        // A holder of STOCK_ADJUST in a browser that is not an enrolled terminal.
        $this->asUser($s['admin'])->postJson('/api/v1/inventory/receipts', $body, $this->key())
            ->assertStatus(403)
            ->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);

        $this->assertNull($this->balance($product));
    }

    // ----------------------------------------------------------------- reads

    public function test_stock_is_store_scoped_filterable_and_readable_without_a_terminal(): void
    {
        $s = $this->store();
        $one = Product::factory()->create(['store_id' => $s['admin']->store_id, 'name' => 'Alpha']);
        $two = Product::factory()->create(['store_id' => $s['admin']->store_id, 'name' => 'Beta']);
        StockBalance::create(['product_id' => $one->id, 'location_id' => $s['location']->id, 'quantity_on_hand' => '5.000']);
        StockBalance::create(['product_id' => $two->id, 'location_id' => $s['location']->id, 'quantity_on_hand' => '7.500']);
        StockBalance::factory()->create(); // another store
        $untracked = Product::factory()->create(['store_id' => $s['admin']->store_id, 'track_inventory' => false]);
        StockBalance::create(['product_id' => $untracked->id, 'location_id' => $s['location']->id, 'quantity_on_hand' => '-4.000']);

        $all = $this->asUser($s['cashier'])->getJson('/api/v1/inventory/stock');
        $all->assertOk();
        $this->assertSame([$one->id, $two->id], collect($all->json('data'))->pluck('product_id')->all());
        $this->assertSame(['product_id', 'location_id', 'quantity_on_hand', 'updated_at'], array_keys($all->json('data.0')));
        $this->assertSame('5.000', $all->json('data.0.quantity_on_hand'));

        $one_only = $this->asUser($s['cashier'])->getJson("/api/v1/inventory/stock?product_id={$two->id}");
        $this->assertSame([$two->id], collect($one_only->json('data'))->pluck('product_id')->all());

        $this->asUser($s['cashier'])->getJson('/api/v1/inventory/stock?product_id=not-a-uuid')->assertOk()->assertJson(['data' => []]);
        $this->assertSame(100, $this->asUser($s['cashier'])->getJson('/api/v1/inventory/stock?per_page=999')->json('meta.per_page'));
    }

    public function test_low_stock_is_at_or_below_the_reorder_level_for_tracked_products(): void
    {
        $s = $this->store();
        $store = $s['admin']->store_id;
        $at = Product::factory()->create(['store_id' => $store, 'reorder_level' => 10]);
        $below = Product::factory()->create(['store_id' => $store, 'reorder_level' => 10]);
        $above = Product::factory()->create(['store_id' => $store, 'reorder_level' => 10]);
        $untracked = Product::factory()->create(['store_id' => $store, 'reorder_level' => 10, 'track_inventory' => false]);
        foreach ([[$at, '10.000'], [$below, '3.000'], [$above, '10.001'], [$untracked, '0.000']] as [$product, $quantity]) {
            StockBalance::create(['product_id' => $product->id, 'location_id' => $s['location']->id, 'quantity_on_hand' => $quantity]);
        }

        $response = $this->asUser($s['cashier'])->getJson('/api/v1/inventory/low-stock');

        $response->assertOk();
        $this->assertEqualsCanonicalizing([$at->id, $below->id], collect($response->json('data'))->pluck('product_id')->all());
    }

    public function test_movements_are_listed_newest_first_with_filters(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);
        $other = Product::factory()->create(['store_id' => $s['admin']->store_id]);
        $make = function (Product $p, string $type, string $at) use ($s) {
            $movement = (new StockMovement)->forceFill([
                'product_id' => $p->id, 'location_id' => $s['location']->id, 'movement_type' => $type, 'quantity' => '1',
                'reason' => $type === 'DAMAGE' ? 'x' : null, 'created_by' => $s['admin']->id, 'occurred_at' => $at,
            ]);
            $movement->save();

            return $movement;
        };
        $old = $make($product, 'PURCHASE_RECEIPT', '2026-09-01 10:00:00');
        $mid = $make($product, 'DAMAGE', '2026-09-10 10:00:00');
        $new = $make($product, 'SALE', '2026-09-18 10:00:00');
        $make($other, 'SALE', '2026-09-18 11:00:00');
        StockMovement::factory()->create(); // another store

        $ids = fn (string $query) => collect($this->asUser($s['cashier'])->getJson("/api/v1/inventory/movements?product_id={$product->id}{$query}")->json('data'))->pluck('id')->all();

        $this->assertSame([$new->id, $mid->id, $old->id], $ids(''));
        $this->assertSame([$mid->id], $ids('&movement_type=DAMAGE'));
        $this->assertSame([$mid->id], $ids('&from=2026-09-05&to=2026-09-10'));
        $this->assertSame([$new->id, $mid->id], $ids('&from=2026-09-05'));
        $this->assertSame([], $ids('&movement_type=MYSTERY'));

        $everything = $this->asUser($s['cashier'])->getJson('/api/v1/inventory/movements');
        $this->assertSame(4, $everything->json('meta.total'));
        $this->assertSame(
            ['id', 'product_id', 'location_id', 'terminal_id', 'movement_type', 'quantity', 'reference_type', 'reference_id', 'reason', 'unit_cost', 'created_by', 'occurred_at'],
            array_keys($everything->json('data.0')),
        );
    }

    public function test_the_balance_stays_equal_to_the_ledger_across_mixed_operations(): void
    {
        $s = $this->store();
        $product = Product::factory()->create(['store_id' => $s['admin']->store_id]);
        $acting = fn () => $this->asUser($s['admin'], $s['enrollment']);

        $acting()->postJson('/api/v1/inventory/receipts', ['product_id' => $product->id, 'quantity' => '10', 'movement_type' => 'OPENING_STOCK'], $this->key());
        $acting()->postJson('/api/v1/inventory/adjustments', ['product_id' => $product->id, 'quantity' => '2.25', 'movement_type' => 'EXPIRED', 'reason' => 'Past date'], $this->key());
        $acting()->postJson('/api/v1/inventory/receipts', ['product_id' => $product->id, 'quantity' => '5.5', 'movement_type' => 'PURCHASE_RECEIPT'], $this->key());

        $this->assertSame('13.250', $this->balance($product));
        $this->artisan('inventory:rebuild-balances')->expectsOutputToContain('already equals')->assertSuccessful();
    }
}
