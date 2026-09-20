<?php

namespace Tests\Database;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stage 26: a barcode may identify only one product per store, but a product's own barcode and an alternate live in
 * different tables, so no single database index can enforce that. The store's advisory barcode lock does, and this proves
 * it with two independent OS processes released together (like the other concurrency tests, it does not extend
 * PostgresSchemaTestCase, so its fixtures are committed and visible to the workers).
 */
class ProductBarcodeConcurrencyTest extends TestCase
{
    private const DATABASE = 'tindaflow_concurrency_test';

    private static bool $migrated = false;

    private string $scratchDir;

    protected function setUp(): void
    {
        parent::setUp();

        config(PostgresTestConnection::settings(self::DATABASE));
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);
            self::$migrated = true;
        }

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_barcode_race_'.Str::random(8);
        mkdir($this->scratchDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->scratchDir);

        parent::tearDown();
    }

    /** @return array{user: User, first: Product, second: Product} */
    private function store(): array
    {
        $storeId = Terminal::factory()->create()->store_id;

        return [
            'user' => User::factory()->admin()->create(['store_id' => $storeId]),
            'first' => Product::factory()->create(['store_id' => $storeId, 'barcode' => null]),
            'second' => Product::factory()->create(['store_id' => $storeId, 'barcode' => null]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $script = base_path('tests/Database/support/barcode_race_worker.php');
        $go = $this->scratchDir.'/go';
        $processes = [];

        foreach ($jobs as $index => $job) {
            file_put_contents($this->scratchDir."/job_{$index}.json", json_encode($job));
            $processes[$index] = Process::start([
                PHP_BINARY, $script, $this->scratchDir."/job_{$index}.json", $this->scratchDir."/ready_{$index}",
                $go, $this->scratchDir."/out_{$index}.json", self::DATABASE,
            ]);
        }

        $deadline = microtime(true) + 20;
        while (count(glob($this->scratchDir.'/ready_*') ?: []) < count($jobs)) {
            if (microtime(true) > $deadline) {
                $this->fail('worker processes did not become ready within the timeout');
            }
            usleep(1000);
        }
        file_put_contents($go, '1');

        $outcomes = [];
        foreach ($processes as $index => $process) {
            $result = $process->wait();
            $this->assertTrue($result->successful(), "worker {$index} failed: {$result->errorOutput()}");
            $outcomes[] = json_decode(file_get_contents($this->scratchDir."/out_{$index}.json"), true);
        }

        return $outcomes;
    }

    /** How many products currently hold the barcode, as their main barcode or as an alternate. */
    private function holders(string $storeId, string $barcode): int
    {
        return Product::where('store_id', $storeId)->where('barcode', $barcode)->count()
            + ProductBarcode::where('store_id', $storeId)->where('barcode', $barcode)->count();
    }

    public function test_adding_an_alternate_while_another_product_takes_the_same_code_as_its_main_barcode_has_one_winner(): void
    {
        foreach (range(1, 3) as $round) {
            $s = $this->store();
            $barcode = "RACE-{$round}-".Str::random(6);
            $storeId = $s['user']->store_id;

            $outcomes = $this->race([
                ['type' => 'add_alternate', 'user_id' => $s['user']->id, 'product_id' => $s['first']->id, 'barcode' => $barcode],
                ['type' => 'set_main', 'user_id' => $s['user']->id, 'product_id' => $s['second']->id, 'barcode' => $barcode],
            ]);

            $this->assertEqualsCanonicalizing(['success', 'exception'], array_column($outcomes, 'outcome'), "round {$round}: ".json_encode($outcomes));
            $this->assertSame('ValidationException', collect($outcomes)->firstWhere('outcome', 'exception')['class']);
            $this->assertSame(1, $this->holders($storeId, $barcode), "round {$round}: the code must identify exactly one product");
        }
    }

    public function test_two_products_racing_to_add_the_same_alternate_have_one_winner(): void
    {
        $s = $this->store();
        $barcode = 'RACE-ALT-'.Str::random(6);

        $outcomes = $this->race([
            ['type' => 'add_alternate', 'user_id' => $s['user']->id, 'product_id' => $s['first']->id, 'barcode' => $barcode],
            ['type' => 'add_alternate', 'user_id' => $s['user']->id, 'product_id' => $s['second']->id, 'barcode' => $barcode],
        ]);

        $this->assertEqualsCanonicalizing(['success', 'exception'], array_column($outcomes, 'outcome'), json_encode($outcomes));
        $this->assertSame(1, $this->holders($s['user']->store_id, $barcode));
    }
}
