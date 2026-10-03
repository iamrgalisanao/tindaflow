<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesStore;
use App\Services\StoreSetup\FiscalConfigurationLock;
use Illuminate\Console\Command;

/**
 * ADR-014: opens and closes the window in which a store's administrators may change its fiscal configuration (tax
 * registration, fiscal installation, invoice series, terminal assignment). Run by whoever administers the server, at
 * installation or during a support session; it is locked the rest of the time.
 */
class FiscalSetup extends Command
{
    use ResolvesStore;

    protected $signature = 'tindaflow:fiscal-setup
        {action : unlock, lock or status}
        {--minutes=60 : How long an unlock lasts (1 to 480)}
        {--store= : The store, by name or id; needed only when the server has more than one store}';

    protected $description = "Unlock or lock a store's fiscal configuration for its administrators";

    public function handle(FiscalConfigurationLock $lock): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, ['unlock', 'lock', 'status'], true)) {
            $this->error("Unknown action '{$action}'. Use unlock, lock or status.");

            return self::FAILURE;
        }

        $store = $this->resolveStore();
        if ($store === null) {
            return self::FAILURE;
        }

        if ($action === 'unlock') {
            $minutes = (int) $this->option('minutes');
            if ($minutes < 1 || $minutes > FiscalConfigurationLock::MAX_MINUTES) {
                $this->error('--minutes must be between 1 and '.FiscalConfigurationLock::MAX_MINUTES.'. Nothing was unlocked.');

                return self::FAILURE;
            }

            $until = $lock->unlock($store->id, $minutes);
            $this->info("Fiscal setup for '{$store->name}' is unlocked until {$until->setTimezone(config('app.timezone'))->format('Y-m-d H:i')}.");
            $this->line('Its administrators can now change the tax registration, fiscal installation and invoice series. Lock it sooner with: php artisan tindaflow:fiscal-setup lock');

            return self::SUCCESS;
        }

        if ($action === 'lock') {
            $lock->lock($store->id);
            $this->info("Fiscal setup for '{$store->name}' is locked.");

            return self::SUCCESS;
        }

        $until = $lock->unlockedUntil($store->id);
        $this->line($until === null
            ? "Fiscal setup for '{$store->name}' is locked."
            : "Fiscal setup for '{$store->name}' is unlocked until {$until->setTimezone(config('app.timezone'))->format('Y-m-d H:i')}.");

        return self::SUCCESS;
    }
}
