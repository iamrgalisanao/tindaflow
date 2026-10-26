<?php

namespace Tests\Database;

use App\Domain\CutOff;
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
        // The factory's random codes (T-00..T-99) could already be T-01; park the other till on a code that cannot collide first.
        $w['t2']->update(['terminal_code' => 'PARK-2']);
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

    public function test_the_employee_admin_works_as_the_restricted_role_and_the_code_cannot_be_changed_or_the_row_deleted(): void
    {
        $w = $this->worldUnderTheHardenedRole();

        // The first creation inserts the counter row, the second updates it (column-level UPDATE of last_number).
        $first = $this->asUser($w['admin'])->postJson('/api/v1/employees', ['employee_type' => 'DIRECT', 'first_name' => 'Ana', 'last_name' => 'Reyes'])->assertStatus(201);
        $second = $this->asUser($w['admin'])->postJson('/api/v1/employees', ['employee_type' => 'DIRECT', 'first_name' => 'Ben', 'last_name' => 'Cruz'])->assertStatus(201);
        $this->assertSame(['TSC00001', 'TSC00002'], [$first->json('employee_code'), $second->json('employee_code')]);

        $this->asUser($w['admin'])->patchJson("/api/v1/employees/{$first->json('id')}", ['first_name' => 'Anna'])->assertOk()->assertJson(['first_name' => 'Anna']);
        $this->asUser($w['admin'])->postJson("/api/v1/employees/{$first->json('id')}/deactivate")->assertOk()->assertJson(['active' => false]);
        $this->asUser($w['admin'])->postJson("/api/v1/employees/{$first->json('id')}/activate")->assertOk()->assertJson(['active' => true]);
        $this->asUser($w['admin'])->putJson('/api/v1/credit-policies/SUBCONTRACTOR', ['limit_per_cutoff' => '900.00'])->assertOk();
        $this->asUser($w['admin'])->call('POST', '/api/v1/employees/import', [], [], [], ['CONTENT_TYPE' => 'text/csv', 'HTTP_ACCEPT' => 'application/json'], "first_name,last_name,employee_type\nCarla,Diaz,SUB\n")
            ->assertStatus(202)->assertJson(['created' => 1]);
        $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/employees/lookup?code=tsc00001')->assertOk()->assertJson(['employee_code' => 'TSC00001']);

        $this->assertTrue($this->refuses(fn () => DB::table('employees')->update(['employee_code' => 'TSC99999'])), 'the code must not be updatable');
        $this->assertTrue($this->refuses(fn () => DB::table('employees')->update(['employee_type' => 'SUBCONTRACTOR'])), 'the type must not be updatable');
        $this->assertTrue($this->refuses(fn () => DB::table('employees')->update(['store_id' => $w['storeId']])), 'the store must not be updatable');
        $this->assertTrue($this->refuses(fn () => DB::table('employees')->delete()), 'an employee must not be deletable');
        $this->assertTrue($this->refuses(fn () => DB::table('employee_code_sequences')->update(['employee_type' => 'SUBCONTRACTOR'])), 'a counter keeps its type');
        $this->assertTrue($this->refuses(fn () => DB::table('employee_code_sequences')->delete()), 'a counter must not be deletable');
        $this->assertTrue($this->refuses(fn () => DB::table('credit_policies')->update(['employee_type' => 'DIRECT'])), 'a policy keeps its type');
        $this->assertTrue($this->refuses(fn () => DB::table('credit_policies')->delete()), 'a policy must not be deletable');
        $this->assertTrue($this->refuses(fn () => DB::table('billing_parties')->delete()), 'a billing party must not be deletable');
    }

    public function test_billing_runs_are_saved_by_the_restricted_role_and_are_append_only(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $created = $this->asUser($w['admin'])->postJson('/api/v1/employees', ['employee_type' => 'DIRECT', 'first_name' => 'Ana', 'last_name' => 'Reyes'])->assertStatus(201);
        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '2']],
            'payments' => [['method' => 'CREDIT', 'amount' => '200.00']],
            'employee_id' => $created->json('id'),
        ], $this->key())->assertStatus(201);
        $month = now()->setTimezone('Asia/Manila')->format('Y-m');
        $half = now()->setTimezone('Asia/Manila')->day <= 15 ? 1 : 2;

        // The manager reads the statement, saves two runs (the counter row is locked and bumped), and exports one, all as the restricted role.
        $this->asUser($w['manager'])->getJson('/api/v1/billing/statement?cutoff_month='.$month.'&cutoff_half='.$half)->assertOk()->assertJsonPath('data.totals.net_amount', '200.00');
        $first = $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', ['cutoff_month' => $month, 'cutoff_half' => $half])->assertStatus(201);
        $second = $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', ['cutoff_month' => $month, 'cutoff_half' => $half])->assertStatus(201);
        $this->assertSame(['BR-000001', 'BR-000002'], [$first->json('data.run_number'), $second->json('data.run_number')]);
        $this->asUser($w['manager'])->get('/api/v1/billing-runs/'.$first->json('data.id'), ['Accept' => 'text/csv'])->assertOk();

        $this->assertTrue($this->refuses(fn () => DB::table('billing_runs')->update(['net_total' => '1.00'])), 'a saved run must not be updatable');
        $this->assertTrue($this->refuses(fn () => DB::table('billing_runs')->update(['statement' => '{}'])), 'the frozen statement must not be updatable');
        $this->assertTrue($this->refuses(fn () => DB::table('billing_runs')->delete()), 'a saved run must not be deletable');
        $this->assertTrue($this->refuses(fn () => DB::table('billing_run_counters')->update(['store_id' => $w['storeId']])), 'a counter keeps its store');
        $this->assertTrue($this->refuses(fn () => DB::table('billing_run_counters')->delete()), 'a counter must not be deletable');
        $this->assertSame(2, (int) DB::table('billing_run_counters')->where('store_id', $w['storeId'])->value('last_number'));
    }

    public function test_cheques_are_recorded_and_returned_by_the_restricted_role_and_only_the_return_columns_can_change(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $created = $this->asUser($w['admin'])->postJson('/api/v1/employees', ['employee_type' => 'DIRECT', 'first_name' => 'Ana', 'last_name' => 'Reyes'])->assertStatus(201);
        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '2']],
            'payments' => [['method' => 'CREDIT', 'amount' => '200.00']],
            'employee_id' => $created->json('id'),
        ], $this->key())->assertStatus(201);
        $period = CutOff::containing(now(), 'Asia/Manila')->startDate();
        $today = now()->setTimezone('Asia/Manila')->toDateString();

        // Recording writes the cheque and its allocation, and the party row lock (SELECT ... FOR UPDATE) needs UPDATE on billing_parties.
        $cheque = $this->asUser($w['manager'])->postJson('/api/v1/billing-cheques', [
            'cheque_number' => '0001', 'bank' => 'BDO', 'cheque_date' => $today, 'amount' => '150.00', 'received_on' => $today,
            'allocations' => [['period_start' => $period, 'amount' => '150.00']],
        ])->assertStatus(201);
        $this->asUser($w['manager'])->getJson('/api/v1/billing/balance')->assertOk()->assertJsonPath('data.totals.owed_amount', '50.00');

        foreach (['amount' => '1.00', 'cheque_number' => '9999', 'bank' => 'BPI', 'cheque_date' => '2020-01-01', 'received_on' => '2020-01-01', 'notes' => 'x', 'store_id' => $w['storeId'], 'received_by' => $w['manager']->id] as $column => $value) {
            $this->assertTrue($this->refuses(fn () => DB::table('billing_cheques')->update([$column => $value])), "{$column} of a cheque must not be updatable");
        }
        $this->assertTrue($this->refuses(fn () => DB::table('billing_cheques')->delete()), 'a cheque must not be deletable');
        $this->assertTrue($this->refuses(fn () => DB::table('billing_cheque_allocations')->update(['amount' => '1.00'])), 'an allocation must not be updatable');
        $this->assertTrue($this->refuses(fn () => DB::table('billing_cheque_allocations')->update(['period_start' => '2020-01-01'])), 'the period of an allocation is fixed');
        $this->assertTrue($this->refuses(fn () => DB::table('billing_cheque_allocations')->delete()), 'an allocation must not be deletable');

        // Returning takes the row lock and writes exactly the return columns; the allocations are kept.
        $this->asUser($w['manager'])->postJson('/api/v1/billing-cheques/'.$cheque->json('data.id').'/return', ['returned_on' => $today, 'reason' => 'Insufficient funds'])->assertOk()->assertJsonPath('data.status', 'RETURNED');
        $this->asUser($w['manager'])->getJson('/api/v1/billing/balance')->assertOk()->assertJsonPath('data.totals.owed_amount', '200.00');
        $this->assertSame(1, DB::table('billing_cheque_allocations')->count());
        $this->assertSame(['billing_cheque', 'billing_cheque'], DB::table('audit_events')->whereIn('event_type', ['BILLING_CHEQUE_RECORDED', 'BILLING_CHEQUE_RETURNED'])->pluck('entity_type')->all());
    }

    public function test_credit_sales_void_and_refund_work_as_the_restricted_role_and_the_ledger_is_append_only(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $created = $this->asUser($w['admin'])->postJson('/api/v1/employees', ['employee_type' => 'DIRECT', 'first_name' => 'Ana', 'last_name' => 'Reyes'])->assertStatus(201);
        $employeeId = $created->json('id');

        // Checkout locks the employee row FOR UPDATE (it needs a column-level UPDATE privilege on employees) and inserts the CHARGE;
        // the void and the refund lock it too and insert their REVERSAL.
        $first = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '2']],
            'payments' => [['method' => 'CREDIT', 'amount' => '200.00']],
            'employee_id' => $employeeId,
        ], $this->key())->assertStatus(201)->json();
        $second = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '1']],
            'payments' => [['method' => 'CREDIT', 'amount' => '100.00']],
            'employee_id' => $employeeId,
        ], $this->key())->assertStatus(201)->json();
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$first['id']}/void", ['reason' => 'Wrong order'], $this->key())->assertStatus(201);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$second['id']}/refunds", [
            'items' => [['sale_item_id' => $second['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CREDIT', 'amount' => '100.00']],
            'reason' => 'Returned',
        ], $this->key())->assertStatus(201);
        $this->asUser($w['cashier'], $w['enroll1'])->getJson("/api/v1/employees/{$employeeId}/credit-status")->assertOk()->assertJsonPath('credit.used', '0.00');

        $this->assertSame(['CHARGE', 'CHARGE', 'REVERSAL', 'REVERSAL'], DB::table('employee_credit_entries')->orderBy('recorded_at')->orderBy('entry_type')->pluck('entry_type')->all());
        $this->assertTrue($this->refuses(fn () => DB::table('employee_credit_entries')->update(['amount' => '1.00'])), 'a ledger entry must not be updatable');
        $this->assertTrue($this->refuses(fn () => DB::table('employee_credit_entries')->update(['period_start' => '2020-01-01'])), 'the period of an entry is fixed');
        $this->assertTrue($this->refuses(fn () => DB::table('employee_credit_entries')->delete()), 'a ledger entry must not be deletable');
        $this->assertTrue($this->refuses(fn () => DB::table('sales')->where('id', $first['id'])->update(['employee_id' => null])), 'the employee of a sale is written once');
    }

    public function test_the_supervisor_override_works_as_the_restricted_role_and_the_grant_and_the_ledger_cannot_be_altered(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $employeeId = $this->asUser($w['admin'])->postJson('/api/v1/employees', ['employee_type' => 'DIRECT', 'first_name' => 'Ana', 'last_name' => 'Reyes'])->assertStatus(201)->json('id');

        // INSERT then column-level UPDATE of user_override_codes (set, change, wrong-code counter, lock, admin reset), all as the restricted role.
        $this->asUser($w['manager'])->putJson('/api/v1/credit-override-code', ['password' => 'password', 'code' => '482916'])->assertOk();
        $this->asUser($w['manager'])->putJson('/api/v1/credit-override-code', ['password' => 'password', 'code' => '739152'])->assertOk();
        $cashier = $this->asUser($w['cashier'], $w['enroll1']);
        $cashier->postJson('/api/v1/credit-overrides', ['approver_id' => $w['manager']->id, 'code' => '000001', 'employee_id' => $employeeId, 'amount' => '1600.00'])->assertStatus(422);
        $this->assertSame(1, (int) DB::table('user_override_codes')->where('user_id', $w['manager']->id)->value('failed_attempts'));
        $token = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/credit-overrides', ['approver_id' => $w['manager']->id, 'code' => '739152', 'employee_id' => $employeeId, 'amount' => '1600.00'])
            ->assertStatus(201)->json('token');

        // Checkout locks the grant row FOR UPDATE and stamps consumed_at (needs the column-level UPDATE), then inserts the CHARGE with the link.
        $big = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '1600.00', 'cost' => '1.00']);
        $sale = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $big->id, 'quantity' => '1']],
            'payments' => [['method' => 'CREDIT', 'amount' => '1600.00']],
            'employee_id' => $employeeId, 'credit_override_token' => $token,
        ], $this->key())->assertStatus(201)->json();
        $this->assertNotNull(DB::table('credit_override_grants')->value('consumed_at'));
        $this->assertNotNull(DB::table('employee_credit_entries')->where('sale_id', $sale['id'])->value('override_grant_id'));
        $this->asUser($w['admin'])->postJson("/api/v1/users/{$w['manager']->id}/override-code/reset", ['password' => 'password'])->assertOk();

        // A grant can be consumed and nothing more: its binding, ceiling, token hash and expiry never change, and it is never deleted.
        foreach ([['terminal_id', $w['t2']->id], ['employee_id', $employeeId], ['cashier_id', $w['manager']->id], ['approver_id', $w['cashier']->id], ['max_amount', '9999.00'], ['token_hash', str_repeat('a', 64)], ['expires_at', now()->addYear()]] as [$column, $value]) {
            $this->assertTrue($this->refuses(fn () => DB::table('credit_override_grants')->update([$column => $value])), "{$column} of a grant must not be updatable");
        }
        $this->assertTrue($this->refuses(fn () => DB::table('credit_override_grants')->delete()), 'a grant must not be deletable');
        // A code row moves to nobody else and is never deleted.
        $this->assertTrue($this->refuses(fn () => DB::table('user_override_codes')->update(['user_id' => $w['cashier']->id])), 'a code row stays with its user');
        $this->assertTrue($this->refuses(fn () => DB::table('user_override_codes')->update(['store_id' => $w['storeId']])), 'a code row stays in its store');
        $this->assertTrue($this->refuses(fn () => DB::table('user_override_codes')->delete()), 'a code row must not be deletable');
        // The ledger link of a charge is written once, at INSERT.
        $this->assertTrue($this->refuses(fn () => DB::table('employee_credit_entries')->update(['override_grant_id' => null])), 'the override link of a charge must not be updatable');
    }

    public function test_blocking_and_the_stock_override_work_as_the_restricted_role_and_a_grant_can_only_be_consumed(): void
    {
        $w = $this->worldUnderTheHardenedRole();
        $coke = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '20.00', 'cost' => '1.00', 'reorder_level' => null]);

        // The ADMIN-only rules screen updates store_settings (creating the row on first use) as the restricted role.
        $this->asUser($w['admin'])->putJson('/api/v1/stock-rules', ['block_sale_without_stock' => true, 'stock_override_enabled' => true, 'low_stock_default_threshold' => 3])->assertOk();

        // The refused sale creates (and rolls back) a zero stock row to lock it: INSERT ... ON CONFLICT DO NOTHING on stock_balances must be allowed.
        $sale = fn (array $extra = []) => $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $coke->id, 'quantity' => '2']], 'payments' => [['method' => 'CASH', 'amount' => '40.00']],
        ] + $extra, $this->key());
        $sale()->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        // INSERT of a grant (and the approver's code), then the consumption: SELECT ... FOR UPDATE and the column-level UPDATE of consumed_at, sale_id.
        $this->asUser($w['manager'])->putJson('/api/v1/credit-override-code', ['password' => 'password', 'code' => '482916'])->assertOk();
        $token = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/stock-overrides', [
            'approver_id' => $w['manager']->id, 'code' => '482916', 'items' => [['product_id' => $coke->id, 'quantity' => '2']],
        ])->assertStatus(201)->json('token');
        $made = $sale(['stock_override_token' => $token])->assertStatus(201)->json();
        $this->assertSame($made['id'], DB::table('stock_override_grants')->value('sale_id'));
        $this->assertNotNull(DB::table('stock_override_grants')->value('consumed_at'));

        // A grant can be consumed and nothing more: its binding, shortfall, token hash and expiry never change, and it is never deleted.
        foreach ([['terminal_id', $w['t2']->id], ['cashier_id', $w['manager']->id], ['approver_id', $w['cashier']->id], ['demand_hash', str_repeat('b', 64)], ['shortfalls', '[]'], ['token_hash', str_repeat('a', 64)], ['expires_at', now()->addYear()], ['store_id', $w['storeId']]] as [$column, $value]) {
            $this->assertTrue($this->refuses(fn () => DB::table('stock_override_grants')->update([$column => $value])), "{$column} of a stock grant must not be updatable");
        }
        $this->assertTrue($this->refuses(fn () => DB::table('stock_override_grants')->delete()), 'a stock grant must not be deletable');
    }

    public function test_the_tables_the_app_is_allowed_to_delete_from_are_exactly_the_ones_that_hold_no_money_or_audit_data(): void
    {
        $this->worldUnderTheHardenedRole();

        $deletable = DB::table('information_schema.role_table_grants')
            ->where('grantee', self::APP_ROLE)->where('privilege_type', 'DELETE')->where('table_schema', 'public')
            ->orderBy('table_name')->pluck('table_name')->all();

        $this->assertSame(['cache', 'cache_locks', 'product_barcodes', 'sessions', 'stock_count_lines'], $deletable);
        foreach (['sales', 'sale_items', 'payments', 'invoices', 'audit_events', 'electronic_journal_entries', 'stock_movements', 'x_readings', 'z_readings', 'refunds', 'voids', 'menu_groups', 'menu_prices', 'dish_categories', 'dishes', 'meals', 'meal_option_groups', 'meal_options', 'sale_item_selections', 'sale_slips', 'slip_counters', 'employees', 'employee_code_sequences', 'credit_policies', 'billing_parties', 'employee_credit_entries', 'billing_runs', 'billing_run_counters', 'billing_cheques', 'billing_cheque_allocations', 'stock_override_grants'] as $table) {
            $this->assertNotContains($table, $deletable, "{$table} must stay undeletable");
        }
    }
}
