<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stage 5 instruction §57: the only "genuinely safe" baseline this
 * schema seeds is the *shape* of the first ADMIN account for a fresh
 * store -- never a fixed, source-committed credential. The store name
 * and admin email are read from the environment (with a clearly
 * non-production fallback for local convenience); the password is
 * always freshly generated and printed once to console output, never
 * hardcoded and never persisted anywhere in plaintext. It is written
 * RAW: console output treats `<...>` as style tags and a backslash before
 * `<` as an escape, so a generated password containing either would be
 * shown altered and the administrator would be locked out.
 *
 * Idempotent: running this against a store that already has an ACTIVE ADMIN
 * user does nothing, so it is safe to include in `DatabaseSeeder` for
 * every environment, including production's first deploy. A store with no ACTIVE
 * administrator (only possible when every admin was deactivated outside the API,
 * which refuses to deactivate the last one) is recovered instead: the configured
 * account is created, or reactivated with a new password when it exists as an
 * ADMIN. An email that belongs to a non-admin user is never taken over.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $storeName = env('TINDAFLOW_INITIAL_STORE_NAME', 'My Sari-Sari Store');
        $adminEmail = env('TINDAFLOW_INITIAL_ADMIN_EMAIL', 'admin@example.com');

        $store = Store::firstOrCreate(['name' => $storeName]);

        if (User::where('store_id', $store->id)->where('role', 'ADMIN')->where('active', true)->exists()) {
            $this->command?->info("An active ADMIN user already exists for '{$store->name}' -- skipping.");

            return;
        }

        $password = env('TINDAFLOW_INITIAL_ADMIN_PASSWORD') ?: $this->generatePassword();

        // Recovery: the store has no ACTIVE administrator (every one was deactivated, which the API now refuses to do
        // to the last one, so this needs data changed outside it). The configured account may already exist as a
        // deactivated ADMIN, and (store_id, email) is unique, so bring that one back rather than colliding with it.
        $existing = User::where('store_id', $store->id)->whereRaw('LOWER(email) = ?', [mb_strtolower($adminEmail)])->first();

        if ($existing !== null && $existing->role !== 'ADMIN') {
            $this->command?->error(
                "'{$adminEmail}' already belongs to a {$existing->role} user of '{$store->name}', and no ADMIN is active. "
                .'Set TINDAFLOW_INITIAL_ADMIN_EMAIL to an unused address and run the seeder again; nothing was changed.'
            );

            return;
        }

        if ($existing !== null) {
            $existing->forceFill(['active' => true, 'password_hash' => Hash::make($password)])->save();

            $this->command?->warn(
                "No ADMIN was active for '{$store->name}': reactivated ADMIN user '{$adminEmail}' with a new password."
            );
        } else {
            User::create([
                'store_id' => $store->id,
                'name' => 'Store Administrator',
                'email' => $adminEmail,
                'password_hash' => Hash::make($password),
                'role' => 'ADMIN',
                'active' => true,
            ]);

            $this->command?->warn(
                "Created initial ADMIN user '{$adminEmail}' for store '{$store->name}'."
            );
        }

        if (! env('TINDAFLOW_INITIAL_ADMIN_PASSWORD')) {
            $this->command?->getOutput()->writeln(
                "Generated password (shown once, not stored anywhere): {$password}",
                OutputInterface::OUTPUT_RAW
            );
        }
    }

    protected function generatePassword(): string
    {
        return Str::password(20);
    }
}
