<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Dish;
use App\Models\Meal;
use App\Models\MenuGroup;
use App\Models\MenuPrice;
use App\Models\Store;
use App\Models\User;
use App\Services\Menu\MenuPriceResolver;
use Illuminate\Support\Facades\Artisan;

/**
 * `tindaflow:load-canteen-menu`: the asked-first way to load the canteen's starting menu on a server, where the seeders
 * refuse production.
 */
class LoadCanteenMenuCommandTest extends PostgresSchemaTestCase
{
    /** @return array{status: int, output: string} */
    private function load(array $options = []): array
    {
        $status = Artisan::call('tindaflow:load-canteen-menu', $options);

        return ['status' => $status, 'output' => Artisan::output()];
    }

    public function test_it_loads_the_groups_dishes_meals_and_opening_prices_and_audits_the_load(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);

        $result = $this->load();

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertSame(7, MenuGroup::where('store_id', $store->id)->whereNull('parent_id')->count());
        $this->assertSame(4, MenuGroup::where('store_id', $store->id)->whereNotNull('parent_id')->count());
        $this->assertSame(21, Dish::where('store_id', $store->id)->count());

        $standard = Meal::where('store_id', $store->id)->where('name', 'Standard Meal')->firstOrFail();
        $combo = Meal::where('store_id', $store->id)->where('name', 'Combo Meal')->firstOrFail();
        $prices = fn (Meal $meal) => MenuPrice::where('meal_id', $meal->id)->orderBy('amount')->pluck('amount')->map(fn ($amount) => (string) $amount)->all();
        $this->assertSame(['60.00', '60.00', '68.00'], $prices($standard));
        $this->assertSame(['80.00', '85.00'], $prices($combo));
        $this->assertNotNull(app(MenuPriceResolver::class)->mealPrice($combo->id, MenuPrice::where('meal_id', $combo->id)->value('dish_category_id'), now()));

        $event = AuditEvent::where('event_type', 'CANTEEN_MENU_LOADED')->sole();
        $this->assertNull($event->actor_user_id);
        $this->assertSame($admin->email, $event->after_metadata['attributed_to']);
    }

    public function test_it_works_on_a_production_server_with_force_and_changes_nothing_when_run_again(): void
    {
        $store = Store::factory()->create();
        User::factory()->admin()->create(['store_id' => $store->id]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame(0, $this->load(['--force' => true])['status']);
        $counts = [MenuGroup::count(), Dish::count(), Meal::count(), MenuPrice::count()];

        $this->assertSame(0, $this->load(['--force' => true])['status']);
        $this->assertSame($counts, [MenuGroup::count(), Dish::count(), Meal::count(), MenuPrice::count()], 'a second run adds nothing');
    }

    public function test_it_creates_nothing_when_the_store_has_no_active_administrator(): void
    {
        $store = Store::factory()->create();
        User::factory()->create(['store_id' => $store->id, 'role' => 'CASHIER']);

        $result = $this->load();

        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('no active administrator', $result['output']);
        $this->assertSame(0, MenuGroup::count());
        $this->assertSame(0, Meal::count());
    }
}
