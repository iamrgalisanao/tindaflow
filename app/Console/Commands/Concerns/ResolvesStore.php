<?php

namespace App\Console\Commands\Concerns;

use App\Models\Store;

/**
 * Picks the store a server-console command acts on: the only store, or the one named by `--store` (a name or an id)
 * when the server has more than one. Says why and returns null when it cannot tell, having changed nothing.
 */
trait ResolvesStore
{
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
