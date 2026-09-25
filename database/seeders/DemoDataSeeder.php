<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Stage 5 instruction §57: a demo/test Store is safe ONLY outside
 * production, and must never seed a fake accreditation number, PTU
 * number, or production tax identity (this seeder creates no
 * `fiscal_installations`/`tax_registrations` row at all -- a demo
 * store is deliberately left in the "no active tax registration" state
 * that invariant #54 already requires a real setup step for).
 *
 * The demo rows belong to the SAME store AdminUserSeeder creates, not a
 * separate "Demo" one. A second store made the demo data unusable: its
 * cashier could not work a terminal enrolled by the admin (
 * ComposeAuthoritativeContext requires user.store_id == terminal.store_id),
 * and none of the demo products appeared on any screen the admin could
 * reach. V1 is single-store, so a second store is not a scenario the app
 * supports anyway -- it was only ever an artifact of seeding.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoDataSeeder refused to run in the production environment.');

            return;
        }

        // Resolved exactly as AdminUserSeeder resolves it, so both seeders always land on one store.
        $store = Store::firstOrCreate(['name' => env('TINDAFLOW_INITIAL_STORE_NAME', 'My Sari-Sari Store')]);

        // Keyed on the users table's own unique index (store_id + email) -- email alone is not unique,
        // so keying on it would silently match a user of some other store and skip creating this one.
        $cashier = User::firstOrCreate(
            ['store_id' => $store->id, 'email' => 'cashier@demo.local'],
            ['name' => 'Demo Cashier', 'password_hash' => bcrypt('password'), 'role' => 'CASHIER', 'active' => true]
        );

        Terminal::firstOrCreate(
            ['store_id' => $store->id, 'terminal_code' => 'DEMO-01'],
            ['status' => 'ACTIVE', 'activated_at' => now()]
        );

        collect(['Rice (1kg)', 'Bottled Water (500ml)', 'Instant Noodles', 'Canned Sardines', 'Cooking Oil (1L)'])
            ->each(fn (string $name, int $i) => Product::firstOrCreate(
                ['store_id' => $store->id, 'sku' => 'DEMO-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT)],
                [
                    'name' => $name,
                    'unit_of_measure' => 'pc',
                    'cost' => 10 + $i,
                    'selling_price' => 15 + $i,
                    'tax_class' => 'VATABLE',
                    'track_inventory' => true,
                    'reorder_level' => 5,
                    'active' => true,
                ]
            ));

        $this->command?->info("Seeded demo data into '{$store->name}': 1 terminal, 1 cashier, and 5 products.");
    }
}
