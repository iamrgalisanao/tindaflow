<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesStore;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\User;
use App\Services\Catalog\ProductImportService;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Loads a small sample catalog (a few categories and the products in database/seeders/data/sample-products.csv) into a
 * store, so beta testers on a hosted server do not start from an empty one. Run by whoever administers the server
 * (`docker compose exec app php artisan tindaflow:load-sample-data`); nothing runs it automatically.
 *
 * It is not DemoDataSeeder, which refuses production because it creates a cashier with a known password and a till
 * nobody enrolled. This creates only catalog rows: no user, no till, no tax registration or fiscal installation, no
 * stock and no barcodes (an invented barcode could collide with a real product's). The products go through
 * ProductImportService, so they are validated and audited exactly like a CSV imported from the catalog screen; every
 * SKU starts with SAMPLE-, so they are easy to find and deactivate. Running it again puts the sample products back
 * to the file's values and touches nothing else. In production it asks first (`--force` skips the question).
 */
class LoadSampleData extends Command
{
    use ConfirmableTrait;
    use ResolvesStore;

    private const CSV_PATH = 'seeders/data/sample-products.csv';

    protected $signature = 'tindaflow:load-sample-data
        {--store= : The store, by name or id; needed only when the server has more than one store}
        {--force : Do not ask for confirmation in production}';

    protected $description = 'Load sample categories and products into a store for beta testing (never loaded automatically)';

    public function handle(ProductImportService $importer): int
    {
        $store = $this->resolveStore();
        if ($store === null) {
            return self::FAILURE;
        }

        // The import records who changed each product; a console run has no signed-in user, so it is attributed to the
        // store's first administrator, and the SAMPLE_DATA_LOADED event below says it came from the console.
        $actor = User::where('store_id', $store->id)->where('role', 'ADMIN')->where('active', true)->orderBy('created_at')->first();
        if ($actor === null) {
            $this->error("'{$store->name}' has no active administrator. Create one first (php artisan db:seed --force). Nothing was created.");

            return self::FAILURE;
        }

        if (! $this->confirmToProceed("Sample products will be added to '{$store->name}'.")) {
            return self::FAILURE;
        }

        $csv = file_get_contents(database_path(self::CSV_PATH));

        [$categoriesCreated, $result] = DB::transaction(function () use ($store, $actor, $importer, $csv) {
            $categoriesCreated = $this->createMissingCategories($store->id, $csv);
            $result = $importer->import($actor, $csv);

            AuditEvent::create([
                'store_id' => $store->id,
                'event_type' => 'SAMPLE_DATA_LOADED',
                'actor_user_id' => null, // run from the server console, not by a signed-in user
                'entity_type' => 'store',
                'entity_id' => $store->id,
                'after_metadata' => [
                    'via' => 'console',
                    'attributed_to' => $actor->email,
                    'categories_created' => $categoriesCreated,
                    'products_created' => $result['created'],
                    'products_updated' => $result['updated'],
                    'products_failed' => $result['failed'],
                ],
            ]);

            return [$categoriesCreated, $result];
        });

        $this->info("Sample data for '{$store->name}': {$result['created']} products created, {$result['updated']} put back to the sample values, {$result['unchanged']} already there, {$categoriesCreated} categories created.");

        foreach ($result['errors'] as $error) {
            $this->error("Row {$error['row']}: {$error['message']}");
        }
        if ($result['failed'] > 0) {
            return self::FAILURE;
        }

        $this->line('The products have no stock and no barcodes: receive stock under Inventory, and add barcodes under Catalog.');

        return self::SUCCESS;
    }

    /** The import matches categories by name and never creates one, so the file's categories are created here first. */
    private function createMissingCategories(string $storeId, string $csv): int
    {
        $lines = array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), preg_split('/\R/', trim($csv)));
        $column = array_search('category', array_shift($lines), true);
        $existing = Category::where('store_id', $storeId)->pluck('name')->map(fn (string $name) => mb_strtolower(trim($name)))->all();
        $created = 0;

        foreach (array_unique(array_column($lines, $column)) as $name) {
            if ($name !== '' && ! in_array(mb_strtolower($name), $existing, true)) {
                Category::create(['store_id' => $storeId, 'name' => $name]);
                $created++;
            }
        }

        return $created;
    }
}
