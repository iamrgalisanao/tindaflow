<?php

namespace Tests\Database;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-017 section 2: the migration that makes products.reorder_level nullable converts the old default 0 ("never set") to NULL (the store's
 * low-stock default) and keeps every deliberate non-zero value. PostgreSQL DDL is transactional, so the migration is stepped down and up inside
 * the test's own transaction.
 */
class ReorderLevelMigrationTest extends PostgresSchemaTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_10_26_200001_make_reorder_level_nullable_on_products.php');
    }

    public function test_zero_becomes_null_and_a_deliberate_level_is_kept_and_the_column_takes_null_afterwards(): void
    {
        $store = Store::factory()->create();
        $migration = $this->migration();

        // Back to the old shape: NOT NULL DEFAULT 0, where "never set" is stored as 0.
        $migration->down();
        $never = Product::factory()->create(['store_id' => $store->id, 'reorder_level' => 0]);
        $deliberate = Product::factory()->create(['store_id' => $store->id, 'reorder_level' => 12]);
        $this->assertSame(0, (int) DB::table('products')->where('id', $never->id)->value('reorder_level'));
        $this->assertSame('0', (string) DB::selectOne("select column_default from information_schema.columns where table_name = 'products' and column_name = 'reorder_level'")->column_default);

        $migration->up();

        $this->assertNull(DB::table('products')->where('id', $never->id)->value('reorder_level'));
        $this->assertSame(12, (int) DB::table('products')->where('id', $deliberate->id)->value('reorder_level'));
        $column = DB::selectOne("select is_nullable, column_default from information_schema.columns where table_name = 'products' and column_name = 'reorder_level'");
        $this->assertSame('YES', $column->is_nullable);
        $this->assertNull($column->column_default, 'no default: a new product is NULL unless a level is given');

        // An explicit 0 afterwards is a real value, not converted.
        DB::table('products')->where('id', $deliberate->id)->update(['reorder_level' => 0]);
        $this->assertSame(0, (int) DB::table('products')->where('id', $deliberate->id)->value('reorder_level'));
    }

    public function test_a_negative_level_is_still_refused_by_the_database(): void
    {
        $product = Product::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $product->id)->update(['reorder_level' => -1]);
    }
}
