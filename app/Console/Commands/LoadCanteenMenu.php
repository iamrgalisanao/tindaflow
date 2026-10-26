<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesStore;
use App\Models\AuditEvent;
use App\Models\Dish;
use App\Models\User;
use App\Services\Menu\DishService;
use App\Services\StoreSetup\StockRulesService;
use Database\Seeders\MealMenuSeeder;
use Database\Seeders\MenuGroupSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Loads the canteen's starting menu (ADR-016) into a store: the 7 menu groups and their sub-groups, the dish categories
 * and dishes, Standard Meal and Combo Meal with their option groups, the rice add-ons and the opening prices. Run by
 * whoever administers the server (`docker compose exec app php artisan tindaflow:load-canteen-menu`); nothing runs it
 * automatically. The seeders it uses refuse production on their own; this command is the deliberate, asked-first way to
 * run them there, like `tindaflow:load-sample-data`.
 *
 * It creates only menu rows, attributed to the store's first active administrator, and writes a CANTEEN_MENU_LOADED audit
 * event saying it came from the console. Running it again leaves anything that already exists exactly as it is (an admin's
 * rename, hide, reorder or price change is never undone), but matching is by name: a seeded dish that was renamed on the
 * screens would be created again under its original name. Load once, then manage the menu on the Menu admin screens.
 * The Combo Meal prices are an assumption until the client confirms them.
 *
 * Slice B of ADR-017 adds two idempotent companion steps: the dishes it creates count DAILY stock (portions 1.0, set on the options by the seeder), and on
 * the FIRST load the store's stock day starts at 05:00 (`store_settings.stock_day_cutover_hour` = 5). The ala carte items are NOT created here: their prices
 * are the manager's to set (Admin > Meal menu > Dishes > Create ala carte items, 2.0 portions each by default).
 */
class LoadCanteenMenu extends Command
{
    use ConfirmableTrait;
    use ResolvesStore;

    protected $signature = 'tindaflow:load-canteen-menu
        {--store= : The store, by name or id; needed only when the server has more than one store}
        {--force : Do not ask for confirmation in production}';

    protected $description = 'Load the canteen menu groups, dishes, Standard and Combo meals and prices into a store (never loaded automatically)';

    public function handle(MenuGroupSeeder $groups, MealMenuSeeder $meals): int
    {
        $store = $this->resolveStore();
        if ($store === null) {
            return self::FAILURE;
        }

        $admin = User::where('store_id', $store->id)->where('role', 'ADMIN')->where('active', true)->orderBy('created_at')->first();
        if ($admin === null) {
            $this->error("'{$store->name}' has no active administrator. Create one first (php artisan db:seed --force). Nothing was created.");

            return self::FAILURE;
        }

        if (! $this->confirmToProceed("The canteen menu (groups, dishes, Standard and Combo meals, prices) will be added to '{$store->name}'.")) {
            return self::FAILURE;
        }

        $groups->setCommand($this);
        $meals->setCommand($this);

        DB::transaction(function () use ($store, $groups, $meals, $admin) {
            $firstLoad = ! AuditEvent::where('store_id', $store->id)->where('event_type', 'CANTEEN_MENU_LOADED')->exists();
            $dishesBefore = Dish::where('store_id', $store->id)->pluck('id')->all();

            $groups->seed($store);
            $meals->seed($store);

            // ADR-017 slice B, client decisions 1 and 3 (2026-10-27): the canteen cooks its dishes daily, and its stock day runs 05:00 to 05:00. Applied
            // only to what THIS run created and only on the first load, so a manager's later change (a dish switched off, the hour set back to 0) is never
            // undone by loading the menu again. Portions are written by the seeder itself (Standard viand 1, Combo main 1, Combo side 1).
            foreach (Dish::where('store_id', $store->id)->whereNotIn('id', $dishesBefore)->get() as $dish) {
                app(DishService::class)->update($admin, $dish->id, ['stock_mode' => Dish::MODE_DAILY]);
            }
            if ($firstLoad) {
                app(StockRulesService::class)->setCutoverHourOnFirstLoad($store->id, 5);
            }

            AuditEvent::create([
                'store_id' => $store->id,
                'event_type' => 'CANTEEN_MENU_LOADED',
                'actor_user_id' => null, // run from the server console, not by a signed-in user
                'entity_type' => 'store',
                'entity_id' => $store->id,
                'after_metadata' => ['via' => 'console', 'attributed_to' => $admin->email],
            ]);
        });

        $this->line('Check Admin > Meal menu. The Combo Meal prices (85 beef, 80 chicken/pork/fish) are an assumption until the client confirms them.');

        return self::SUCCESS;
    }
}
