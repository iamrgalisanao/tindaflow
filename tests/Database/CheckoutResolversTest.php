<?php

namespace Tests\Database;

use App\Domain\Exceptions\FiscalInstallationResolutionException;
use App\Domain\Exceptions\InventoryLocationResolutionException;
use App\Domain\Exceptions\TaxRegistrationResolutionException;
use App\Models\FiscalInstallation;
use App\Models\InventoryLocation;
use App\Models\Store;
use App\Models\Terminal;
use App\Services\Checkout\FiscalInstallationResolver;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Checkout\TaxRegistrationResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Stage 6C resolution helpers (stage-6c-sale-finalization.md SS"Gap 1"/"Gap 2"),
// built and tested before CheckoutService itself per the owner's specified
// implementation sequence.
class CheckoutResolversTest extends PostgresSchemaTestCase
{
    private FiscalInstallationResolver $fiscalInstallationResolver;

    private InventoryLocationResolver $inventoryLocationResolver;

    private TaxRegistrationResolver $taxRegistrationResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fiscalInstallationResolver = new FiscalInstallationResolver;
        $this->inventoryLocationResolver = new InventoryLocationResolver;
        $this->taxRegistrationResolver = new TaxRegistrationResolver;
    }

    // --- FiscalInstallationResolver -----------------------------------

    public function test_resolves_the_single_effective_mapping(): void
    {
        $terminal = Terminal::factory()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $terminal->store_id]);

        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(),
            'store_id' => $terminal->store_id,
            'terminal_id' => $terminal->id,
            'fiscal_installation_id' => $installation->id,
            'effective_from' => '2026-01-01 00:00:00',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = $this->fiscalInstallationResolver->resolveForTerminal($terminal->id, '2026-06-01 12:00:00');

        $this->assertSame($installation->id, $resolved);
    }

    public function test_interval_is_inclusive_start(): void
    {
        $terminal = Terminal::factory()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $terminal->store_id]);

        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(),
            'store_id' => $terminal->store_id,
            'terminal_id' => $terminal->id,
            'fiscal_installation_id' => $installation->id,
            'effective_from' => '2026-06-01 00:00:00',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // sold_at exactly equal to effective_from must match (inclusive start).
        $resolved = $this->fiscalInstallationResolver->resolveForTerminal($terminal->id, '2026-06-01 00:00:00');

        $this->assertSame($installation->id, $resolved);
    }

    public function test_interval_is_exclusive_end(): void
    {
        $terminal = Terminal::factory()->create();
        $oldInstallation = FiscalInstallation::factory()->create(['store_id' => $terminal->store_id]);

        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(),
            'store_id' => $terminal->store_id,
            'terminal_id' => $terminal->id,
            'fiscal_installation_id' => $oldInstallation->id,
            'effective_from' => '2026-01-01 00:00:00',
            'effective_to' => '2026-06-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // sold_at exactly equal to effective_to must NOT match the old row
        // (exclusive end) -- with no successor row, this is "no mapping".
        $this->expectException(FiscalInstallationResolutionException::class);
        $this->fiscalInstallationResolver->resolveForTerminal($terminal->id, '2026-06-01 00:00:00');
    }

    public function test_no_mapping_throws_resolution_exception(): void
    {
        $terminal = Terminal::factory()->create();

        $this->expectException(FiscalInstallationResolutionException::class);
        $this->fiscalInstallationResolver->resolveForTerminal($terminal->id, '2026-06-01 00:00:00');
    }

    public function test_overlapping_historical_rows_are_treated_as_ambiguous_not_guessed(): void
    {
        $terminal = Terminal::factory()->create();
        $first = FiscalInstallation::factory()->create(['store_id' => $terminal->store_id]);
        $second = FiscalInstallation::factory()->create(['store_id' => $terminal->store_id]);

        // Deliberately bad data: two historical (effective_to IS NOT NULL,
        // so the partial unique index does not block this) rows whose
        // windows both cover the same instant. The resolver must refuse
        // to pick either one rather than silently choosing "the latest".
        foreach ([$first, $second] as $installation) {
            DB::table('terminal_fiscal_installations')->insert([
                'id' => (string) Str::uuid(),
                'store_id' => $terminal->store_id,
                'terminal_id' => $terminal->id,
                'fiscal_installation_id' => $installation->id,
                'effective_from' => '2026-01-01 00:00:00',
                'effective_to' => '2026-12-31 00:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->expectException(FiscalInstallationResolutionException::class);
        $this->fiscalInstallationResolver->resolveForTerminal($terminal->id, '2026-06-01 00:00:00');
    }

    // --- InventoryLocationResolver -------------------------------------

    public function test_resolves_the_single_default_location(): void
    {
        $store = Store::factory()->create();
        $default = InventoryLocation::factory()->create(['store_id' => $store->id, 'is_default' => true]);
        InventoryLocation::factory()->notDefault()->create(['store_id' => $store->id]);

        $resolved = $this->inventoryLocationResolver->resolveDefaultForStore($store->id);

        $this->assertSame($default->id, $resolved);
    }

    public function test_no_default_location_throws_resolution_exception(): void
    {
        $store = Store::factory()->create();
        InventoryLocation::factory()->notDefault()->create(['store_id' => $store->id]);

        $this->expectException(InventoryLocationResolutionException::class);
        $this->inventoryLocationResolver->resolveDefaultForStore($store->id);
    }

    public function test_database_rejects_a_second_default_location_for_the_same_store(): void
    {
        $store = Store::factory()->create();
        InventoryLocation::factory()->create(['store_id' => $store->id, 'is_default' => true]);

        $this->expectException(QueryException::class);
        InventoryLocation::factory()->create(['store_id' => $store->id, 'is_default' => true]);
    }

    public function test_two_stores_may_each_have_their_own_default_location(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        $defaultA = InventoryLocation::factory()->create(['store_id' => $storeA->id, 'is_default' => true]);
        $defaultB = InventoryLocation::factory()->create(['store_id' => $storeB->id, 'is_default' => true]);

        $this->assertSame($defaultA->id, $this->inventoryLocationResolver->resolveDefaultForStore($storeA->id));
        $this->assertSame($defaultB->id, $this->inventoryLocationResolver->resolveDefaultForStore($storeB->id));
    }

    // --- TaxRegistrationResolver -----------------------------------------

    public function test_resolves_the_single_effective_registration(): void
    {
        $store = Store::factory()->create();
        DB::table('tax_registrations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $store->id, 'registration_type' => 'VAT',
            'effective_from' => '2026-01-01', 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resolved = $this->taxRegistrationResolver->resolveForStore($store->id, '2026-06-01');

        $this->assertSame('VAT', $resolved);
    }

    public function test_registration_interval_is_inclusive_start(): void
    {
        $store = Store::factory()->create();
        DB::table('tax_registrations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $store->id, 'registration_type' => 'VAT',
            'effective_from' => '2026-06-01', 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resolved = $this->taxRegistrationResolver->resolveForStore($store->id, '2026-06-01');

        $this->assertSame('VAT', $resolved);
    }

    public function test_registration_interval_is_inclusive_end(): void
    {
        $store = Store::factory()->create();
        // Deliberately a single-day registration (effective_from ==
        // effective_to) -- the schema's own CHECK constraint permits
        // this, which only makes sense under inclusive-end semantics.
        DB::table('tax_registrations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $store->id, 'registration_type' => 'NON_VAT',
            'effective_from' => '2026-06-01', 'effective_to' => '2026-06-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resolved = $this->taxRegistrationResolver->resolveForStore($store->id, '2026-06-01');

        $this->assertSame('NON_VAT', $resolved);
    }

    public function test_registration_excluded_the_day_after_effective_to(): void
    {
        $store = Store::factory()->create();
        DB::table('tax_registrations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $store->id, 'registration_type' => 'VAT',
            'effective_from' => '2026-01-01', 'effective_to' => '2026-06-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 2026-06-01 itself resolves fine (inclusive end); the day after does not.
        $this->expectException(TaxRegistrationResolutionException::class);
        $this->taxRegistrationResolver->resolveForStore($store->id, '2026-06-02');
    }

    public function test_no_registration_throws_resolution_exception(): void
    {
        $store = Store::factory()->create();

        $this->expectException(TaxRegistrationResolutionException::class);
        $this->taxRegistrationResolver->resolveForStore($store->id, '2026-06-01');
    }

    public function test_overlapping_registrations_are_treated_as_ambiguous_not_guessed(): void
    {
        $store = Store::factory()->create();

        // Deliberately bad data: two historical (effective_to IS NOT
        // NULL, so the partial unique index does not block this)
        // registrations whose windows both cover the same date.
        foreach (['VAT', 'NON_VAT'] as $type) {
            DB::table('tax_registrations')->insert([
                'id' => (string) Str::uuid(), 'store_id' => $store->id, 'registration_type' => $type,
                'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->expectException(TaxRegistrationResolutionException::class);
        $this->taxRegistrationResolver->resolveForStore($store->id, '2026-06-01');
    }
}
