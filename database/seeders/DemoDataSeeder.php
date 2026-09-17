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
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoDataSeeder refused to run in the production environment.');

            return;
        }

        $store = Store::firstOrCreate(['name' => 'Demo Sari-Sari Store']);

        $cashier = User::firstOrCreate(
            ['email' => 'cashier@demo.local'],
            ['store_id' => $store->id, 'name' => 'Demo Cashier', 'password_hash' => bcrypt('password'), 'role' => 'CASHIER', 'active' => true]
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

        $this->command?->info("Seeded demo store '{$store->name}' with 1 terminal, 1 cashier, and 5 products.");
    }
}
