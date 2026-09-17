<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Stage 5 instruction §57: only AdminUserSeeder is safe to run in
     * every environment, including production's first deploy (it is
     * idempotent and generates its own credentials -- see its own
     * docblock). DemoDataSeeder refuses to run in production itself as a
     * second layer of defense, but is only invoked here at all outside
     * production, so a production deploy never even attempts it.
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        if (! app()->environment('production')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
