<?php

namespace Database\Seeders;

use App\Models\MenuGroup;
use App\Models\Store;
use Illuminate\Database\Seeder;

/**
 * ADR-016 section 1: the canteen's till navigation -- seven top groups, with sub-groups under Breakfast and Lunch.
 *
 * Demo/canteen data only: it is called by DemoDataSeeder, which never runs in production, and it refuses to run there
 * itself. A real canteen store builds (or edits) the same tree on the Menu groups screen. Idempotent: a group that already
 * exists at its level (same store, same parent, same name) is left exactly as it is, so a renamed, re-sorted or hidden
 * group is never reset by re-seeding.
 */
class MenuGroupSeeder extends Seeder
{
    /** @var array<string, list<string>> top group => its sub-groups, in till order */
    public const TREE = [
        'Breakfast' => ['Ala Carte', 'Silog Meal'],
        'Lunch' => ['Standard Meal', 'Combo Meal'],
        'Snack/Merienda' => [],
        'Baked Products' => [],
        'Varieties' => [],
        'Beverages' => [],
        'Coffeeshop' => [],
    ];

    public function run(?Store $store = null): void
    {
        if (app()->environment('production')) {
            $this->command?->error('MenuGroupSeeder refused to run in the production environment.');

            return;
        }

        $store ??= Store::firstOrCreate(['name' => env('TINDAFLOW_INITIAL_STORE_NAME', 'My Sari-Sari Store')]);

        $position = 0;
        foreach (self::TREE as $topName => $subNames) {
            $top = MenuGroup::firstOrCreate(
                ['store_id' => $store->id, 'parent_id' => null, 'name' => $topName],
                ['sort_order' => ++$position, 'active' => true],
            );

            foreach (array_values($subNames) as $index => $subName) {
                MenuGroup::firstOrCreate(
                    ['store_id' => $store->id, 'parent_id' => $top->id, 'name' => $subName],
                    ['sort_order' => $index + 1, 'active' => true],
                );
            }
        }

        $this->command?->info("Seeded the canteen menu groups into '{$store->name}'.");
    }
}
