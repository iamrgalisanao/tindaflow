<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

/**
 * Stage 24 decision 2: every change to a product is on the audit trail, in the same transaction as the change.
 * PRODUCT_CREATED, PRODUCT_UPDATED (changed fields only, with the sku), PRODUCT_IMPORTED (one summary per import,
 * each changed row also has its own event); categories and brands are not audited.
 */
class ProductAuditHttpTest extends PostgresSchemaTestCase
{
    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $cookie = collect($this->login($user)->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    /** @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return $overrides + ['sku' => 'RIC-001', 'name' => 'Rice 1kg', 'unit_of_measure' => 'kg', 'selling_price' => '55.00', 'tax_class' => 'VATABLE'];
    }

    private function events(User $admin, string $type): Collection
    {
        return AuditEvent::where('store_id', $admin->store_id)->where('event_type', $type)->orderBy('occurred_at')->orderBy('id')->get();
    }

    private function import(User $user, string $csv, bool $dryRun = false): TestResponse
    {
        return $this->asUser($user)->call('POST', '/api/v1/products/import'.($dryRun ? '?dry_run=true' : ''), [], [], [], ['CONTENT_TYPE' => 'text/csv', 'HTTP_ACCEPT' => 'application/json'], $csv);
    }

    public function test_creating_a_product_records_who_made_it_and_what_it_was(): void
    {
        $admin = User::factory()->admin()->create();

        $created = $this->asUser($admin)->postJson('/api/v1/products', $this->input(['barcode' => '4800016001234', 'cost' => '40.00']));

        $created->assertStatus(201);
        $event = $this->events($admin, 'PRODUCT_CREATED')->sole();
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame('product', $event->entity_type);
        $this->assertSame($created->json('id'), $event->entity_id);
        $this->assertNull($event->before_metadata);
        $this->assertSame('RIC-001', $event->after_metadata['sku']);
        $this->assertSame('55.00', $event->after_metadata['selling_price']);
        $this->assertSame('40.00', $event->after_metadata['cost']);
        $this->assertSame('VATABLE', $event->after_metadata['tax_class']);
        $this->assertTrue($event->after_metadata['active']);
        $this->assertNull($event->terminal_id, 'a back-office change has no terminal');
        $this->assertNotNull($event->request_id);
    }

    public function test_an_update_records_only_the_fields_that_changed_plus_the_sku(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'RIC-001', 'name' => 'Rice 1kg', 'selling_price' => '55.00', 'cost' => null, 'tax_class' => 'VATABLE', 'unit_of_measure' => 'kg', 'barcode' => null, 'reorder_level' => 5]);

        $this->asUser($admin)->patchJson("/api/v1/products/{$product->id}", $this->input(['selling_price' => '60.00', 'cost' => '42.50', 'tax_class' => 'VAT_EXEMPT', 'reorder_level' => 5]))->assertOk();

        $event = $this->events($admin, 'PRODUCT_UPDATED')->sole();
        $this->assertEquals(['selling_price' => '55.00', 'cost' => null, 'tax_class' => 'VATABLE'], $event->before_metadata);
        $this->assertEquals(['selling_price' => '60.00', 'cost' => '42.50', 'tax_class' => 'VAT_EXEMPT', 'sku' => 'RIC-001'], $event->after_metadata);
        $this->assertSame($product->id, $event->entity_id);
    }

    public function test_a_save_that_changes_nothing_writes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'RIC-001', 'name' => 'Rice 1kg', 'selling_price' => '55.00', 'tax_class' => 'VATABLE', 'unit_of_measure' => 'kg', 'barcode' => null, 'cost' => null, 'description' => null, 'reorder_level' => 0, 'track_inventory' => true]);

        $this->asUser($admin)->patchJson("/api/v1/products/{$product->id}", $this->input(['selling_price' => '55.00']))->assertOk();

        $this->assertCount(0, $this->events($admin, 'PRODUCT_UPDATED'));
    }

    public function test_deactivating_and_reactivating_are_recorded_and_repeating_them_is_not(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'OLD-1']);
        $client = $this->asUser($admin);

        $client->postJson("/api/v1/products/{$product->id}/deactivate")->assertOk();
        $client->postJson("/api/v1/products/{$product->id}/deactivate")->assertOk();
        $client->postJson("/api/v1/products/{$product->id}/activate")->assertOk();

        $events = $this->events($admin, 'PRODUCT_UPDATED');
        $this->assertCount(2, $events);
        $this->assertEquals([['active' => true], ['active' => false]], $events->map->before_metadata->all());
        $this->assertEquals([['active' => false, 'sku' => 'OLD-1'], ['active' => true, 'sku' => 'OLD-1']], $events->map->after_metadata->all());
    }

    public function test_a_rejected_request_leaves_no_audit_event(): void
    {
        $admin = User::factory()->admin()->create();
        $existing = Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'TAKEN']);

        $this->asUser($admin)->postJson('/api/v1/products', $this->input(['sku' => 'TAKEN']))->assertStatus(422);
        $this->asUser($admin)->patchJson("/api/v1/products/{$existing->id}", $this->input(['sku' => 'TAKEN', 'selling_price' => 'not money']))->assertStatus(422);

        $this->assertSame(0, AuditEvent::where('store_id', $admin->store_id)->count());
    }

    public function test_categories_and_brands_are_not_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->asUser($admin)->postJson('/api/v1/categories', ['name' => 'Grocery'])->assertStatus(201);
        $this->asUser($admin)->postJson('/api/v1/brands', ['name' => 'Sunrise'])->assertStatus(201);

        $this->assertSame(0, AuditEvent::where('store_id', $admin->store_id)->count());
        $this->assertSame(1, Category::where('store_id', $admin->store_id)->count());
        $this->assertSame(1, Brand::where('store_id', $admin->store_id)->count());
    }

    public function test_an_import_writes_one_summary_and_an_event_for_each_row_that_really_changed(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'OLD-1', 'name' => 'Old', 'selling_price' => '10.00', 'unit_of_measure' => 'pc', 'tax_class' => 'VATABLE', 'barcode' => null, 'cost' => null, 'description' => null, 'reorder_level' => 0, 'track_inventory' => true, 'active' => true]);
        Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'SAME-1', 'name' => 'Same', 'selling_price' => '5.00', 'unit_of_measure' => 'pc', 'tax_class' => 'VATABLE', 'barcode' => null, 'cost' => null, 'description' => null, 'reorder_level' => 0, 'track_inventory' => true, 'active' => true]);
        $csv = "sku,name,unit_of_measure,selling_price,tax_class\nNEW-1,Fresh,pc,1.00,VATABLE\nOLD-1,Old,pc,12.50,VATABLE\nSAME-1,Same,pc,5.00,VATABLE\nBAD-1,Oops,pc,abc,VATABLE\n";

        $result = $this->import($admin, $csv)->assertStatus(202)->json();

        $this->assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 1, 'failed' => 1], array_diff_key($result, ['errors' => 0]));
        $summary = $this->events($admin, 'PRODUCT_IMPORTED')->sole();
        $this->assertSame('product_import', $summary->entity_type);
        $this->assertEquals(['rows' => 4, 'created' => 1, 'updated' => 1, 'unchanged' => 1, 'failed' => 1, 'sha256' => hash('sha256', $csv)], $summary->after_metadata);

        $created = $this->events($admin, 'PRODUCT_CREATED')->sole();
        $updated = $this->events($admin, 'PRODUCT_UPDATED')->sole();
        $this->assertSame('NEW-1', $created->after_metadata['sku']);
        $this->assertEquals(['selling_price' => '10.00'], $updated->before_metadata);
        $this->assertEquals(['selling_price' => '12.50', 'sku' => 'OLD-1'], $updated->after_metadata);
        // Each row's event names the batch, and the batch is the summary's entity.
        foreach ([$created, $updated] as $event) {
            $this->assertSame("Product CSV import {$summary->entity_id}", $event->reason);
        }
        $this->assertCount(3, AuditEvent::where('store_id', $admin->store_id)->get(), 'no event for the unchanged row or the failed one');
    }

    public function test_a_dry_run_leaves_no_audit_trail(): void
    {
        $admin = User::factory()->admin()->create();

        $this->import($admin, "sku,name,unit_of_measure,selling_price,tax_class\nNEW-1,Fresh,pc,1.00,VATABLE\n", dryRun: true)->assertStatus(202);

        $this->assertSame(0, AuditEvent::where('store_id', $admin->store_id)->count());
        $this->assertSame(0, Product::where('store_id', $admin->store_id)->count());
    }

    public function test_a_file_that_cannot_be_read_records_nothing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->import($admin, "name\nRice\n")->assertStatus(422);

        $this->assertSame(0, AuditEvent::where('store_id', $admin->store_id)->count());
    }

    public function test_the_trail_is_readable_by_a_manager_but_not_a_cashier_and_stays_in_its_own_store(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create(['store_id' => $admin->store_id]);
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);
        $other = User::factory()->admin()->create();
        $this->asUser($admin)->postJson('/api/v1/products', $this->input())->assertStatus(201);
        $this->asUser($other)->postJson('/api/v1/products', $this->input(['sku' => 'THEIRS']))->assertStatus(201);

        $seen = $this->asUser($manager)->getJson('/api/v1/audit-events?event_type=PRODUCT_CREATED')->assertOk()->json('data');
        $this->assertCount(1, $seen);
        $this->assertSame('RIC-001', $seen[0]['after_metadata']['sku']);
        $this->assertSame($admin->id, $seen[0]['actor_user_id']);

        $this->asUser($cashier)->getJson('/api/v1/audit-events?event_type=PRODUCT_CREATED')->assertStatus(403);
    }
}
