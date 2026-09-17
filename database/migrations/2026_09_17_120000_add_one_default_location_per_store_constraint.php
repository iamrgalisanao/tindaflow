<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Stage 6C ruling (docs/06-backend/stage-6c-sale-finalization.md SS"Gap 2"):
// V1 checkout deducts stock from the store's single default inventory
// location, resolved by inventory_locations.is_default = true. That
// resolution is only safe if at most one default can exist per store --
// the original Stage 5 schema left is_default unconstrained. This
// migration adds that constraint, but first verifies (as an upgrade
// safety check, matching the pattern established for the InvoiceSeries
// counter/FiscalInstallation amendments) that no store already has more
// than one default location; it aborts rather than silently picking one
// if it finds ambiguous existing data. It does NOT require every store to
// have a default -- a store with zero defaults is a CheckoutService-time
// rejection (INVENTORY_LOCATION_NOT_CONFIGURED-shape error), not a
// migration-time concern, since the schema cannot express "at least one."
return new class extends Migration
{
    public function up(): void
    {
        $this->assertAtMostOneDefaultPerStore();

        DB::statement('CREATE UNIQUE INDEX inventory_locations_one_default_per_store ON inventory_locations (store_id) WHERE is_default = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inventory_locations_one_default_per_store');
    }

    private function assertAtMostOneDefaultPerStore(): void
    {
        $offendingStores = DB::table('inventory_locations')
            ->select('store_id')
            ->where('is_default', true)
            ->groupBy('store_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('store_id');

        if ($offendingStores->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add inventory_locations_one_default_per_store: '.
                'the following stores already have more than one default '.
                'inventory location, and the migration will not guess '.
                'which one should remain default: '.$offendingStores->implode(', ').
                '. Resolve the ambiguity in the data first.'
            );
        }
    }
};
