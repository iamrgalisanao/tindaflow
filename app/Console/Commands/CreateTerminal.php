<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Store;
use App\Models\Terminal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates a till (a terminal) for a store. The API has no terminalCreate operation, and the only other writer of the
 * `terminals` table is DemoDataSeeder, which refuses to run in production, so without this a real shop could never get its
 * first till: nothing sells until a browser is enrolled as a terminal, and enrolling needs a terminal that already exists.
 * Run by whoever administers the server (`docker compose exec app php artisan tindaflow:create-terminal TILL-1`); the
 * enrollment itself (a one-time token, entered on the till computer) is still done from the Terminals screen.
 */
class CreateTerminal extends Command
{
    protected $signature = 'tindaflow:create-terminal
        {code : A short name for the till, shown on the Terminals screen (for example TILL-1)}
        {--store= : The store, by name or id; needed only when the server has more than one store}';

    protected $description = 'Create a till (terminal) so a browser can be enrolled as it';

    public function handle(): int
    {
        $code = trim((string) $this->argument('code'));

        if ($code === '' || mb_strlen($code) > 40) {
            $this->error('The till code must be 1 to 40 characters, for example TILL-1.');

            return self::FAILURE;
        }

        $store = $this->resolveStore();
        if ($store === null) {
            return self::FAILURE;
        }

        if (Terminal::where('store_id', $store->id)->whereRaw('LOWER(terminal_code) = ?', [mb_strtolower($code)])->exists()) {
            $this->error("'{$store->name}' already has a till called '{$code}'. Nothing was created.");

            return self::FAILURE;
        }

        $terminal = DB::transaction(function () use ($store, $code) {
            // ACTIVE and never enrolled: activated_at and the credential are set when a browser enrolls (TerminalEnrollmentService).
            $terminal = Terminal::create(['store_id' => $store->id, 'terminal_code' => $code, 'status' => 'ACTIVE']);

            AuditEvent::create([
                'store_id' => $store->id,
                'event_type' => 'TERMINAL_CREATED',
                'actor_user_id' => null, // run from the server console, not by a signed-in user
                'terminal_id' => $terminal->id,
                'entity_type' => 'terminal',
                'entity_id' => $terminal->id,
                'after_metadata' => ['terminal_code' => $code, 'store' => $store->name, 'via' => 'console'],
            ]);

            return $terminal;
        });

        $this->info("Created till '{$terminal->terminal_code}' for '{$store->name}'.");
        $this->line('Next: sign in as an administrator, open Terminals, click "Enrollment token" on that till, and enter the token on the till computer.');

        return self::SUCCESS;
    }

    private function resolveStore(): ?Store
    {
        $wanted = trim((string) $this->option('store'));
        $stores = Store::orderBy('name')->get();

        if ($stores->isEmpty()) {
            $this->error('There is no store yet. Create the first administrator first (php artisan db:seed --force), which creates the store.');

            return null;
        }

        if ($wanted === '') {
            if ($stores->count() > 1) {
                $this->error('This server has more than one store ('.$stores->pluck('name')->implode(', ').'). Say which with --store="<name or id>". Nothing was created.');

                return null;
            }

            return $stores->first();
        }

        $matches = $stores->filter(fn (Store $store) => $store->id === $wanted || mb_strtolower($store->name) === mb_strtolower($wanted));
        if ($matches->count() !== 1) {
            $this->error("No single store matches '{$wanted}'. Nothing was created.");

            return null;
        }

        return $matches->first();
    }
}
