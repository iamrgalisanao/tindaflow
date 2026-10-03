<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\FiscalInstallation;
use App\Models\Product;
use App\Models\Store;
use App\Models\TaxRegistration;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * `tindaflow:load-sample-data`: an opt-in sample catalog for a hosted beta server, where DemoDataSeeder (a cashier with
 * a known password, a till nobody enrolled) refuses to run.
 */
class LoadSampleDataCommandTest extends PostgresSchemaTestCase
{
    /** @return array{status: int, output: string} */
    private function load(array $options = []): array
    {
        $status = Artisan::call('tindaflow:load-sample-data', $options);

        return ['status' => $status, 'output' => Artisan::output()];
    }

    private function sampleRowCount(): int
    {
        return count(file(database_path('seeders/data/sample-products.csv'), FILE_SKIP_EMPTY_LINES)) - 1;
    }

    public function test_it_loads_every_sample_product_with_its_category_and_audits_it(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);

        $result = $this->load();

        $this->assertSame(0, $result['status'], $result['output']);
        $products = Product::where('store_id', $store->id)->get();
        $this->assertCount($this->sampleRowCount(), $products);
        $this->assertGreaterThanOrEqual(20, $products->count());
        $this->assertTrue($products->every(fn (Product $product) => str_starts_with($product->sku, 'SAMPLE-')));
        $this->assertTrue($products->every(fn (Product $product) => $product->category_id !== null && $product->barcode === null && $product->active));
        $this->assertSame($products->count(), AuditEvent::where('event_type', 'PRODUCT_CREATED')->count(), 'each product is audited like any import');

        $event = AuditEvent::where('event_type', 'SAMPLE_DATA_LOADED')->sole();
        $this->assertNull($event->actor_user_id);
        $this->assertSame($admin->email, $event->after_metadata['attributed_to']);
        $this->assertSame($products->count(), $event->after_metadata['products_created']);
    }

    public function test_it_creates_no_user_till_or_tax_identity(): void
    {
        $store = Store::factory()->create();
        User::factory()->admin()->create(['store_id' => $store->id]);

        $this->load();

        $this->assertSame(1, User::count());
        $this->assertSame(0, Terminal::count());
        $this->assertSame(0, TaxRegistration::count());
        $this->assertSame(0, FiscalInstallation::count());
    }

    public function test_running_it_again_restores_the_samples_and_leaves_everything_else_alone(): void
    {
        $store = Store::factory()->create();
        User::factory()->admin()->create(['store_id' => $store->id]);
        $this->load();
        $own = Product::factory()->create(['store_id' => $store->id, 'sku' => 'OWN-1', 'selling_price' => '99.00']);
        $categories = Category::count();
        Product::where('sku', 'SAMPLE-001')->update(['selling_price' => '1.00']);

        $result = $this->load();

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertSame($this->sampleRowCount() + 1, Product::count());
        $this->assertSame($categories, Category::count(), 'no category is created twice');
        $this->assertSame('15.00', Product::where('sku', 'SAMPLE-001')->value('selling_price'));
        $this->assertSame('99.00', $own->fresh()->selling_price);
    }

    public function test_it_reuses_a_category_that_already_exists_under_a_different_case(): void
    {
        $store = Store::factory()->create();
        User::factory()->admin()->create(['store_id' => $store->id]);
        $existing = Category::factory()->create(['store_id' => $store->id, 'name' => 'BEVERAGES']);

        $result = $this->load();

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertSame(1, Category::whereRaw('LOWER(name) = ?', ['beverages'])->count());
        $this->assertSame($existing->id, Product::where('sku', 'SAMPLE-001')->value('category_id'));
    }

    public function test_it_refuses_a_store_with_no_active_administrator(): void
    {
        $store = Store::factory()->create();
        User::factory()->admin()->create(['store_id' => $store->id, 'active' => false]);

        $result = $this->load();

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('no active administrator', $result['output']);
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Category::count());
    }

    public function test_in_production_it_changes_nothing_unless_confirmed_or_forced(): void
    {
        $store = Store::factory()->create();
        User::factory()->admin()->create(['store_id' => $store->id]);
        $this->app['env'] = 'production';

        $this->artisan('tindaflow:load-sample-data')->expectsConfirmation('Are you sure you want to run this command?', 'no')->assertExitCode(1);
        $this->assertSame(0, Product::count());

        $this->artisan('tindaflow:load-sample-data', ['--force' => true])->assertExitCode(0);
        $this->assertSame($this->sampleRowCount(), Product::count());
    }
}
