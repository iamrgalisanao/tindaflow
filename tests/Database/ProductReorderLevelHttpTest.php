<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * ADR-017 section 2: products.reorder_level is the product's own low-stock threshold, or NULL for "use the store default". The form sends null
 * (or leaves the field out) for the default and a number, even 0, for an override; the CSV export writes a blank for the default.
 */
class ProductReorderLevelHttpTest extends PostgresSchemaTestCase
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
        return array_merge(['sku' => 'COK-001', 'name' => 'Coke', 'unit_of_measure' => 'can', 'selling_price' => '30.00', 'tax_class' => 'VATABLE'], $overrides);
    }

    public function test_a_product_created_without_a_level_has_null_and_one_created_with_a_level_keeps_it(): void
    {
        $admin = User::factory()->admin()->create();

        $this->asUser($admin)->postJson('/api/v1/products', $this->input())->assertStatus(201)->assertJsonPath('reorder_level', null);
        $this->asUser($admin)->postJson('/api/v1/products', $this->input(['sku' => 'COK-002', 'reorder_level' => 12]))->assertStatus(201)->assertJsonPath('reorder_level', 12);
        $this->asUser($admin)->postJson('/api/v1/products', $this->input(['sku' => 'COK-003', 'reorder_level' => null]))->assertStatus(201)->assertJsonPath('reorder_level', null);
        $this->asUser($admin)->postJson('/api/v1/products', $this->input(['sku' => 'COK-004', 'reorder_level' => 0]))->assertStatus(201)->assertJsonPath('reorder_level', 0);
    }

    public function test_an_update_can_set_a_level_clear_it_back_to_the_default_or_leave_it_alone(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'COK-001', 'name' => 'Coke', 'unit_of_measure' => 'can', 'selling_price' => '30.00', 'tax_class' => 'VATABLE', 'reorder_level' => null]);
        $body = $this->input();

        $this->asUser($admin)->patchJson("/api/v1/products/{$product->id}", $body + ['reorder_level' => 8])->assertOk()->assertJsonPath('reorder_level', 8);
        $this->asUser($admin)->patchJson("/api/v1/products/{$product->id}", $body)->assertOk()->assertJsonPath('reorder_level', 8);
        $this->asUser($admin)->patchJson("/api/v1/products/{$product->id}", $body + ['reorder_level' => null])->assertOk()->assertJsonPath('reorder_level', null);
        $this->asUser($admin)->patchJson("/api/v1/products/{$product->id}", $body + ['reorder_level' => 0])->assertOk()->assertJsonPath('reorder_level', 0);

        $events = AuditEvent::where('event_type', 'PRODUCT_UPDATED')->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertCount(3, $events, 'the unchanged save in the middle wrote nothing');
        $this->assertSame([null, 8], [$events[0]->before_metadata['reorder_level'], $events[0]->after_metadata['reorder_level']]);
        $this->assertSame([8, null], [$events[1]->before_metadata['reorder_level'], $events[1]->after_metadata['reorder_level']]);
    }

    public function test_a_negative_or_fractional_level_is_still_invalid(): void
    {
        $admin = User::factory()->admin()->create();

        $this->asUser($admin)->postJson('/api/v1/products', $this->input(['reorder_level' => -1]))->assertStatus(422);
        $this->asUser($admin)->postJson('/api/v1/products', $this->input(['reorder_level' => 2.5]))->assertStatus(422);
    }

    public function test_the_csv_export_writes_a_blank_for_the_store_default_and_importing_it_back_changes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'A-1', 'reorder_level' => null]);
        Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'B-2', 'reorder_level' => 0]);

        $csv = $this->asUser($admin)->get('/api/v1/products/export')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $column = array_search('reorder_level', $rows[0], true);
        $this->assertSame('', $rows[1][$column], 'NULL is a blank cell');
        $this->assertSame('0', $rows[2][$column], 'an explicit 0 is written as 0');

        $result = $this->asUser($admin)->call('POST', '/api/v1/products/import', [], [], [], ['CONTENT_TYPE' => 'text/csv'], $csv)->assertStatus(202)->json();
        $this->assertSame(0, $result['failed']);
        $this->assertSame(2, $result['unchanged']);
        $this->assertNull(Product::where('sku', 'A-1')->value('reorder_level'));
        $this->assertSame(0, Product::where('sku', 'B-2')->value('reorder_level'));
    }

    public function test_a_new_product_imported_without_a_level_gets_the_store_default(): void
    {
        $admin = User::factory()->admin()->create();

        $this->asUser($admin)->call('POST', '/api/v1/products/import', [], [], [], ['CONTENT_TYPE' => 'text/csv'], "sku,name,unit_of_measure,selling_price,tax_class\nN-1,New item,pc,10.00,VATABLE\n")->assertStatus(202);

        $this->assertNull(Product::where('sku', 'N-1')->sole()->reorder_level);
    }
}
