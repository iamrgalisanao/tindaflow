<?php

namespace Tests\Database;

use App\Models\MealOptionGroup;
use App\Models\MenuGroup;
use App\Models\Product;
use App\Services\Menu\DishCategoryService;
use App\Services\Menu\DishService;
use App\Services\Menu\MealOptionService;
use App\Services\Menu\MealService;
use App\Services\Menu\MenuPriceService;
use App\Services\StoreSetup\FiscalConfigurationLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * invariants.md #48/#45/#2: the append-only rules are enforced by database/scripts/harden_append_only_privileges.sql,
 * applied by `tindaflow:harden-database`. This proves it end to end: with the command run and the connection switched to
 * the role it provisions, the real checkout, void and refund flows still work, and the tables the invariants call
 * append-only refuse an UPDATE or DELETE.
 *
 * Everything (the role, its grants, the script, SET LOCAL ROLE) happens inside the per-test transaction that
 * PostgresSchemaTestCase rolls back, so nothing leaks into the cluster or into other tests.
 * tests/Database/HardenDatabaseCommandTest.php covers the command's own behaviour.
 */
class AppendOnlyPrivilegesTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private const APP_ROLE = 'tindaflow_app';

    /** @return array<string, mixed> */
    private function worldUnderTheHardenedRole(): array
    {
        $canCreateRoles = DB::selectOne('select rolcreaterole or rolsuper as allowed from pg_roles where rolname = current_user')->allowed;
        if (! $canCreateRoles) {
            $this->markTestSkipped('The test database user cannot create roles, so the hardening script cannot be applied here.');
        }

        $w = $this->world();

        // The provisioning the deployment runs as the table owner: create the role, give it the ordinary privileges,
        // apply database/scripts/harden_append_only_privileges.sql.
        $this->artisan('tindaflow:harden-database', ['--password' => 'test-only-password'])->assertSuccessful();

        DB::unprepared('SET LOCAL ROLE '.self::APP_ROLE);

        return $w;
    }

    private function refuses(callable $statement): bool
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            return str_contains($e->getMessage(), 'permission denied');
        }

        return false;
    }

    public function test_a_void_still_works_when_the_application_role_has_only_what_the_script_leaves_it(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $sale = $this->ring($w);

        $this->asUser($w['manager'], $w['enroll2'])
            ->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong item'], $this->key())
            ->assertStatus(201)
            ->assertJson(['status' => 'VOIDED', 'sale' => ['status' => 'VOIDED']]);
    }

    public function test_a_refund_still_works_when_the_application_role_has_only_what_the_script_leaves_it(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $sale = $this->ring($w);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '2', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '200.00']],
            'reason' => 'Customer returned it',
        ], $this->key())
            ->assertStatus(201)
            ->assertJson(['status' => 'COMPLETED', 'refund_total' => '200.00']);

        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'status' => 'REFUNDED']);
    }

    public function test_a_requested_void_can_be_approved_and_another_rejected(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $first = $this->ring($w);
        $second = $this->ring($w);

        $approved = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$first['id']}/void", ['reason' => 'Wrong item'], $this->key());
        $approved->assertStatus(201)->assertJson(['status' => 'REQUESTED']);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$approved->json('id')}/approve", [], $this->key())
            ->assertStatus(200)->assertJson(['status' => 'VOIDED']);

        $rejected = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$second['id']}/void", ['reason' => 'Wrong item'], $this->key());
        $rejected->assertStatus(201);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/voids/{$rejected->json('id')}/reject", ['reason' => 'Not needed'], $this->key())
            ->assertStatus(200)->assertJson(['status' => 'REJECTED']);
    }

    public function test_a_requested_refund_can_be_approved_and_another_rejected(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $first = $this->ring($w);
        $second = $this->ring($w);
        $body = fn (array $sale) => [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '100.00']],
            'reason' => 'Customer returned it',
        ];

        $approved = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$first['id']}/refunds", $body($first), $this->key());
        $approved->assertStatus(201)->assertJson(['status' => 'REQUESTED']);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$approved->json('id')}/approve", [], $this->key())
            ->assertStatus(200)->assertJson(['status' => 'COMPLETED']);

        $rejected = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$second['id']}/refunds", $body($second), $this->key());
        $rejected->assertStatus(201);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$rejected->json('id')}/reject", ['reason' => 'Not needed'], $this->key())
            ->assertStatus(200)->assertJson(['status' => 'REJECTED']);
    }

    public function test_the_append_only_tables_refuse_an_update_and_a_delete(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $sale = $this->ring($w);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong item'], $this->key())->assertStatus(201);

        foreach (['audit_events', 'electronic_journal_entries', 'stock_movements', 'sale_items', 'payments', 'invoices'] as $table) {
            $this->assertTrue($this->refuses(fn () => DB::table($table)->update(['id' => DB::raw('id')])), "UPDATE on {$table} should be refused");
            $this->assertTrue($this->refuses(fn () => DB::table($table)->delete()), "DELETE on {$table} should be refused");
        }
    }

    public function test_a_sale_may_change_its_status_and_nothing_else(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $sale = $this->ring($w);

        $this->assertTrue($this->refuses(fn () => DB::table('sales')->where('id', $sale['id'])->update(['grand_total' => '1.00'])), 'the total of a finalized sale must not be editable');
        $this->assertTrue($this->refuses(fn () => DB::table('sales')->where('id', $sale['id'])->delete()), 'a sale must not be deletable');

        DB::table('sales')->where('id', $sale['id'])->update(['status' => 'VOIDED']);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'status' => 'VOIDED']);
    }

    // ---------------------------------------------------------------- what the running app itself deletes

    /**
     * The stack runs SESSION_DRIVER=database and CACHE_STORE=database (docker/.env.example), and the tests normally use the
     * array drivers, which is how the hardening once revoked a DELETE the running app depends on and no test noticed. These
     * switch to the real drivers, so the framework's own housekeeping (rate-limit rows expiring, logout, the session sweep)
     * runs as the restricted role too.
     */
    private function withTheStacksDrivers(): void
    {
        config(['session.driver' => 'database', 'cache.default' => 'database', 'session.lottery' => [100, 100]]);
    }

    public function test_the_api_keeps_working_after_the_rate_limit_window_has_expired(): void
    {
        $this->withTheStacksDrivers();
        $w = $this->worldUnderTheHardenedRole();

        $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/auth/me')->assertOk();
        $this->travel(3)->minutes(); // the throttle rows written above are now expired and get deleted on the next read
        $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_logout_and_the_session_sweep_work_as_the_restricted_role(): void
    {
        $this->withTheStacksDrivers(); // lottery [100, 100]: every request sweeps expired sessions
        $w = $this->worldUnderTheHardenedRole();

        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/auth/logout')->assertStatus(204);
    }

    public function test_removing_an_alternate_barcode_and_a_count_line_work_as_the_restricted_role(): void
    {
        $this->withTheStacksDrivers();
        $w = $this->worldUnderTheHardenedRole();
        $product = Product::factory()->create(['store_id' => $w['storeId']]);

        $barcode = $this->asUser($w['manager'])->postJson("/api/v1/products/{$product->id}/barcodes", ['barcode' => '4800000000024']);
        $barcode->assertStatus(201);
        $this->asUser($w['manager'])->deleteJson("/api/v1/products/{$product->id}/barcodes/{$barcode->json('id')}")->assertStatus(204);

        $count = $this->asUser($w['manager'])->postJson('/api/v1/inventory/counts', [])->assertStatus(201);
        $this->asUser($w['manager'])->putJson("/api/v1/inventory/counts/{$count->json('id')}/lines", ['lines' => [['product_id' => $product->id, 'counted_quantity' => '3']]])->assertOk();
        $this->asUser($w['manager'])->deleteJson("/api/v1/inventory/counts/{$count->json('id')}/lines/{$product->id}")->assertOk();
    }

    public function test_the_menu_group_admin_works_as_the_restricted_role_and_a_group_cannot_be_deleted(): void
    {
        $w = $this->worldUnderTheHardenedRole();

        $top = $this->asUser($w['manager'])->postJson('/api/v1/menu-groups', ['name' => 'Lunch'])->assertStatus(201);
        $sub = $this->asUser($w['manager'])->postJson('/api/v1/menu-groups', ['name' => 'Standard Meal', 'parent_id' => $top->json('id')])->assertStatus(201);
        $this->asUser($w['manager'])->patchJson("/api/v1/menu-groups/{$sub->json('id')}", ['name' => 'Standard'])->assertOk();
        $this->asUser($w['manager'])->postJson("/api/v1/menu-groups/{$sub->json('id')}/deactivate")->assertOk()->assertJson(['active' => false]);
        $this->asUser($w['manager'])->postJson('/api/v1/menu-groups/reorder', ['parent_id' => null, 'ids' => [$top->json('id')]])->assertOk();

        $product = Product::factory()->create(['store_id' => $w['storeId']]);
        $this->asUser($w['manager'])->patchJson("/api/v1/products/{$product->id}", [
            'sku' => $product->sku, 'name' => $product->name, 'unit_of_measure' => 'pc', 'selling_price' => '10.00', 'tax_class' => 'VATABLE',
            'menu_group_id' => $top->json('id'),
        ])->assertOk()->assertJson(['menu_group_id' => $top->json('id')]);

        $this->assertTrue($this->refuses(fn () => MenuGroup::query()->delete()), 'a menu group must not be deletable');
    }

    public function test_the_meal_menu_admin_works_as_the_restricted_role_a_price_row_cannot_be_updated_and_nothing_can_be_deleted(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $as = fn () => $this->asUser($w['manager']);

        $beef = $as()->postJson('/api/v1/dish-categories', ['name' => 'Beef'])->assertStatus(201)->json('id');
        $as()->patchJson("/api/v1/dish-categories/{$beef}", ['name' => 'Beef dishes'])->assertOk();
        $dish = $as()->postJson('/api/v1/dishes', ['name' => 'Beef nilaga', 'dish_category_id' => $beef])->assertStatus(201)->json('id');
        $as()->patchJson("/api/v1/dishes/{$dish}", ['name' => 'Beef nilaga special'])->assertOk();
        $as()->postJson('/api/v1/dishes/reorder', ['dish_category_id' => $beef, 'ids' => [$dish]])->assertOk();
        $meal = $as()->postJson('/api/v1/meals', ['name' => 'Standard Meal'])->assertStatus(201)->json();
        $as()->patchJson("/api/v1/meals/{$meal['id']}", ['name' => 'Regular Meal'])->assertOk();
        $group = $as()->postJson("/api/v1/meals/{$meal['id']}/option-groups", ['name' => 'Extra rice', 'kind' => 'ADDON'])->assertStatus(201)->json('id');
        $as()->patchJson("/api/v1/meal-option-groups/{$group}", ['popup_after_choice' => true])->assertOk();
        $option = $as()->postJson("/api/v1/meal-option-groups/{$meal['option_groups'][0]['id']}/options", ['dish_id' => $dish])->assertStatus(201)->json('id');
        $rice = $as()->postJson("/api/v1/meal-option-groups/{$group}/options", ['label' => 'Whole rice'])->assertStatus(201)->json('id');
        $as()->postJson("/api/v1/meal-options/{$option}/deactivate")->assertOk();

        $as()->postJson("/api/v1/meals/{$meal['id']}/prices", ['dish_category_id' => $beef, 'amount' => '68.00'])->assertStatus(201);
        $this->travel(1)->minutes();
        $as()->postJson("/api/v1/meals/{$meal['id']}/prices", ['dish_category_id' => $beef, 'amount' => '70.00'])->assertStatus(201);
        $as()->postJson("/api/v1/meals/{$meal['id']}/prices", ['meal_option_id' => $rice, 'amount' => '15.00'])->assertStatus(201);
        $as()->getJson("/api/v1/meals/{$meal['id']}/prices/history")->assertOk()->assertJson(['meta' => ['total' => 3]]);

        $this->assertTrue($this->refuses(fn () => DB::table('menu_prices')->update(['amount' => '1.00'])), 'a menu price row must not be updatable');
        foreach (['menu_prices', 'dish_categories', 'dishes', 'meals', 'meal_option_groups', 'meal_options'] as $table) {
            $this->assertTrue($this->refuses(fn () => DB::table($table)->delete()), "{$table} must not be deletable");
        }
    }

    public function test_a_meal_sale_works_under_the_restricted_role_and_its_snapshot_cannot_be_changed(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $admin = $w['admin'];
        $dish = app(DishService::class)->create($admin, [
            'name' => 'Beef nilaga',
            'dish_category_id' => ($beef = app(DishCategoryService::class)->create($admin, ['name' => 'Beef']))->id,
        ]);
        $meal = app(MealService::class)->create($admin, ['name' => 'Standard Meal', 'pricing_group_name' => 'Viand']);
        $group = MealOptionGroup::where('meal_id', $meal->id)->where('prices_the_meal', true)->sole();
        $option = app(MealOptionService::class)->create($admin, $group->id, ['dish_id' => $dish->id]);
        app(MenuPriceService::class)->set($admin, $meal->id, ['dish_category_id' => $beef->id, 'amount' => '68.00']);

        $sale = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['meal' => ['meal_id' => $meal->id, 'choices' => [['group_id' => $group->id, 'option_id' => $option->id]]], 'quantity' => '1']],
            'payments' => [['method' => 'CASH', 'amount' => '68.00']],
        ], $this->key())->assertStatus(201)->json();

        $this->assertSame('Beef nilaga', DB::table('sale_item_selections')->where('sale_item_id', $sale['items'][0]['id'])->value('dish_name_snapshot'));
        $this->assertTrue($this->refuses(fn () => DB::table('sale_item_selections')->update(['dish_name_snapshot' => 'Changed'])), 'a sale selection must not be updatable');
        $this->assertTrue($this->refuses(fn () => DB::table('sale_item_selections')->delete()), 'a sale selection must not be deletable');
        foreach (['meal_name_snapshot', 'menu_group_path_snapshot', 'parent_line_number'] as $column) {
            $this->assertTrue($this->refuses(fn () => DB::table('sale_items')->update([$column => null])), "sale_items.{$column} must not be updatable");
        }
        $this->assertTrue($this->refuses(fn () => DB::table('sale_items')->update(['meal_id' => null])), 'sale_items.meal_id must not be updatable');

        // A void and a refund of the meal line still work with only the privileges the script leaves.
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1', 'disposition' => 'DISPOSED']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '68.00']],
            'reason' => 'Dish was cold',
        ], $this->key())->assertStatus(201)->assertJson(['status' => 'COMPLETED']);
    }

    public function test_a_slip_sale_its_reprint_a_new_shift_and_the_mode_switch_work_as_the_restricted_role_and_a_slip_cannot_be_changed(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $w['t1']->update(['terminal_code' => 'T-01']);
        DB::table('store_settings')->insert([
            'store_id' => $w['storeId'], 'business_name' => 'Canteen One', 'registered_name' => '', 'tin' => '', 'sale_document_mode' => 'SLIP',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Two slip sales: the first inserts the terminal's counter row, the second updates it (column-level UPDATE).
        $first = $this->ring($w);
        $second = $this->ring($w);
        $this->assertSame(['T-01-000001', 'T-01-000002'], [$first['document_reference'], $second['document_reference']]);
        $this->assertSame(2, (int) DB::table('slip_counters')->where('terminal_id', $w['t1']->id)->value('last_number'));

        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sale-slips/{$first['slip']['id']}/reprints", [], $this->key())
            ->assertStatus(201)->assertJson(['is_reprint' => true]);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$second['id']}/void", ['reason' => 'Wrong item'], $this->key())
            ->assertStatus(201)->assertJson(['sale' => ['document_reference' => 'T-01-000002']]);

        $this->assertTrue($this->refuses(fn () => DB::table('sale_slips')->update(['slip_number' => 'X-1'])), 'a slip must not be updatable');
        $this->assertTrue($this->refuses(fn () => DB::table('sale_slips')->update(['slip_snapshot_json' => '{}'])), 'a slip snapshot must not be updatable');
        $this->assertTrue($this->refuses(fn () => DB::table('sale_slips')->delete()), 'a slip must not be deletable');
        $this->assertTrue($this->refuses(fn () => DB::table('slip_counters')->update(['terminal_id' => $w['t2']->id])), 'a counter may not move to another terminal');
        $this->assertTrue($this->refuses(fn () => DB::table('slip_counters')->delete()), 'a counter must not be deletable');
        $this->assertTrue($this->refuses(fn () => DB::table('sales')->where('id', $first['id'])->update(['document_mode' => 'INVOICE'])), 'a sale keeps the document it was made with');

        // The mode switch and the footer (both write store_settings; the switch takes the store row lock a shift open shares).
        $this->asUser($w['admin'])->putJson('/api/v1/sale-document-settings/slip-footer', ['slip_footer' => 'Salamat po!'])->assertOk();
        app(FiscalConfigurationLock::class)->unlock($w['storeId'], 60);
        $this->asUser($w['admin'])->putJson('/api/v1/sale-document-settings/mode', ['sale_document_mode' => 'INVOICE'])->assertStatus(409);
        foreach ([[$w['cashier'], 'enroll1', $w['cashierShift']], [$w['manager'], 'enroll2', $w['managerShift']]] as [$user, $enroll, $shift]) {
            $this->asUser($user, $w[$enroll])->postJson("/api/v1/shifts/{$shift->id}/close", ['declared_cash' => (string) $shift->opening_cash], $this->key())->assertOk();
            $this->asUser($w['manager'], $w[$enroll])->postJson("/api/v1/fiscal-days/{$shift->fiscal_day_id}/close", [], $this->key())->assertOk();
        }
        $this->asUser($w['admin'])->putJson('/api/v1/sale-document-settings/mode', ['sale_document_mode' => 'INVOICE'])->assertOk()->assertJson(['sale_document_mode' => 'INVOICE']);
        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00'], $this->key())->assertStatus(201);
        $this->assertSame('INVOICE', $this->ring($w)['document_mode']);
    }

    public function test_the_tables_the_app_is_allowed_to_delete_from_are_exactly_the_ones_that_hold_no_money_or_audit_data(): void
    {
        $this->worldUnderTheHardenedRole();

        $deletable = DB::table('information_schema.role_table_grants')
            ->where('grantee', self::APP_ROLE)->where('privilege_type', 'DELETE')->where('table_schema', 'public')
            ->orderBy('table_name')->pluck('table_name')->all();

        $this->assertSame(['cache', 'cache_locks', 'product_barcodes', 'sessions', 'stock_count_lines'], $deletable);
        foreach (['sales', 'sale_items', 'payments', 'invoices', 'audit_events', 'electronic_journal_entries', 'stock_movements', 'x_readings', 'z_readings', 'refunds', 'voids', 'menu_groups', 'menu_prices', 'dish_categories', 'dishes', 'meals', 'meal_option_groups', 'meal_options', 'sale_item_selections', 'sale_slips', 'slip_counters'] as $table) {
            $this->assertNotContains($table, $deletable, "{$table} must stay undeletable");
        }
    }
}
