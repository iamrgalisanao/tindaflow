<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesStore;
use App\Models\AuditEvent;
use App\Models\Store;
use App\Models\Terminal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates a till (a terminal) for a store from the server console. The Terminals screen's "Add terminal" button
 * (terminalCreate) does the same from the browser; this remains for scripted or headless setup
 * (`docker compose exec app php artisan tindaflow:create-terminal TILL-1`). The enrollment itself (a one-time token,
 * entered on the till computer) is done from the Terminals screen.
 */
class CreateTerminal extends Command
{
    use ResolvesStore;

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
}
