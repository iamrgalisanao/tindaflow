<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Stage 29: receiving stock by the pack. The server converts packs to single units and derives the unit cost; the
 * ledger only ever sees units, and the pack facts (which pack, how many, its size at that moment, what a pack cost) are
 * kept as a snapshot so a later change of pack size never rewrites a past receipt. The plain receipt is unchanged.
 */
class StockReceiptPackagingHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private const RECEIPTS = '/api/v1/inventory/receipts';

    /** @return string the id of a new pack of the product */
    private function pack(array $w, Product $product, string $name, string $units, array $extra = []): string
    {
        return $this->asUser($w['manager'])->postJson("/api/v1/products/{$product->id}/barcodes", ['name' => $name, 'units_per_base' => $units] + $extra)->assertStatus(201)->json('id');
    }

    /** @param  array<string, mixed>  $body */
    private function receive(array $w, array $body, ?array $key = null, $as = null): TestResponse
    {
        return $this->asUser($as ?? $w['manager'], $w['enroll1'])->postJson(self::RECEIPTS, $body + ['movement_type' => 'PURCHASE_RECEIPT'], $key ?? $this->key());
    }

    private function balance(Product $product): ?string
    {
        return StockBalance::where('product_id', $product->id)->value('quantity_on_hand');
    }

    public function test_receiving_cases_adds_units_and_derives_the_unit_cost(): void
    {
        $w = $this->world();
        $case = $this->pack($w, $w['product'], 'Case', '240');

        $response = $this->receive($w, ['product_id' => $w['product']->id, 'packaging_id' => $case, 'packs' => '5', 'pack_cost' => '1153.92'])->assertStatus(201);

        // 1153.92 / 240 = 4.808, stored as 4.81; the pack cost itself is kept in the audit snapshot
        $response->assertJson([
            'product_id' => $w['product']->id, 'movement_type' => 'PURCHASE_RECEIPT', 'quantity' => '1200.000', 'unit_cost' => '4.81',
            'reason' => '5.000 x Case (240.000 units each)', 'terminal_id' => $w['t1']->id, 'created_by' => $w['manager']->id,
        ]);
        $this->assertSame('1200.000', $this->balance($w['product']));
        $this->artisan('inventory:rebuild-balances', ['--dry-run' => true])->expectsOutputToContain('already equals')->assertSuccessful();
    }

    public function test_the_pack_facts_are_kept_as_a_snapshot_in_the_audit_and_the_journal(): void
    {
        $w = $this->world();
        $case = $this->pack($w, $w['product'], 'Case', '240', ['barcode' => 'CASE-1']);

        $movement = $this->receive($w, ['product_id' => $w['product']->id, 'packaging_id' => $case, 'packs' => '5', 'pack_cost' => '1153.92', 'note' => 'Delivery 0042'])->json();

        $audit = AuditEvent::findOrFail($movement['reference_id']);
        $this->assertEquals([
            'movement_type' => 'PURCHASE_RECEIPT', 'quantity' => '1200.000', 'location_id' => $w['location']->id, 'unit_cost' => '4.81', 'note' => 'Delivery 0042',
            'packaging' => ['packaging_id' => $case, 'name' => 'Case', 'barcode' => 'CASE-1', 'units_per_base' => '240.000', 'packs' => '5.000', 'pack_cost' => '1153.92'],
        ], $audit->after_metadata);
        $this->assertSame('Delivery 0042', $movement['reason'], 'the person\'s own note wins over the generated summary');
        $journal = ElectronicJournalEntry::where('source_id', $movement['id'])->firstOrFail();
        $this->assertSame('240.000', $journal->payload_json['packaging']['units_per_base']);
    }

    public function test_a_later_change_of_pack_size_never_rewrites_a_past_receipt(): void
    {
        $w = $this->world();
        $case = $this->pack($w, $w['product'], 'Case', '24');
        $movement = $this->receive($w, ['product_id' => $w['product']->id, 'packaging_id' => $case, 'packs' => '2', 'pack_cost' => '960.00'])->json();

        // the supplier changes to 12-packs: the old pack is removed and re-added with the new size
        $this->asUser($w['manager'])->deleteJson("/api/v1/products/{$w['product']->id}/barcodes/{$case}")->assertStatus(204);
        $newCase = $this->pack($w, $w['product'], 'Case', '12');
        $this->receive($w, ['product_id' => $w['product']->id, 'packaging_id' => $newCase, 'packs' => '2', 'pack_cost' => '600.00'])->assertStatus(201);

        $this->assertSame('48.000', StockMovement::findOrFail($movement['id'])->quantity, 'the first receipt is still 48 units');
        $this->assertSame('24.000', AuditEvent::findOrFail($movement['reference_id'])->after_metadata['packaging']['units_per_base']);
        $this->assertSame('72.000', $this->balance($w['product']), '48 + 24');
    }

    public function test_without_a_pack_cost_the_unit_cost_is_left_empty(): void
    {
        $w = $this->world();
        $case = $this->pack($w, $w['product'], 'Case', '24');

        $response = $this->receive($w, ['product_id' => $w['product']->id, 'packaging_id' => $case, 'packs' => '1'])->assertStatus(201);

        $this->assertNull($response->json('unit_cost'));
        $this->assertSame('24.000', $response->json('quantity'));
    }

    public function test_the_unit_cost_is_rounded_half_up_and_the_pack_cost_is_kept(): void
    {
        $w = $this->world();
        $pair = $this->pack($w, $w['product'], 'Pair', '2');

        $movement = $this->receive($w, ['product_id' => $w['product']->id, 'packaging_id' => $pair, 'packs' => '10', 'pack_cost' => '10.01'])->json();

        $this->assertSame('5.01', $movement['unit_cost'], '5.005 rounds up');
        $this->assertSame('10.01', AuditEvent::findOrFail($movement['reference_id'])->after_metadata['packaging']['pack_cost'], 'the pack cost itself is the truth');
    }

    public function test_a_fraction_of_a_pack_and_a_barcode_only_alias_both_work(): void
    {
        $w = $this->world();
        $case = $this->pack($w, $w['product'], 'Case', '24');
        $aliasId = $this->asUser($w['manager'])->postJson("/api/v1/products/{$w['product2']->id}/barcodes", ['barcode' => 'ALIAS-1'])->assertStatus(201)->json('id');

        $this->assertSame('12.000', $this->receive($w, ['product_id' => $w['product']->id, 'packaging_id' => $case, 'packs' => '0.5'])->assertStatus(201)->json('quantity'));
        $this->assertSame('3.000', $this->receive($w, ['product_id' => $w['product2']->id, 'packaging_id' => $aliasId, 'packs' => '3'])->assertStatus(201)->json('quantity'), 'one unit per alias');
    }

    public function test_a_count_that_is_not_a_whole_number_of_thousandths_or_is_too_big_is_refused(): void
    {
        $w = $this->world();
        $half = $this->pack($w, $w['product'], 'Half', '0.5');
        $case = $this->pack($w, $w['product2'], 'Case', '24');

        $this->receive($w, ['product_id' => $w['product']->id, 'packaging_id' => $half, 'packs' => '0.001'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['packs']]]);
        $this->receive($w, ['product_id' => $w['product2']->id, 'packaging_id' => $case, 'packs' => '9999999'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['packs']]]);

        $this->assertSame(0, StockMovement::count());
    }

    public function test_the_two_ways_of_saying_how_much_arrived_cannot_be_mixed(): void
    {
        $w = $this->world();
        $case = $this->pack($w, $w['product'], 'Case', '24');
        $base = ['product_id' => $w['product']->id];

        foreach ([
            $base + ['packaging_id' => $case, 'packs' => '1', 'quantity' => '24'],
            $base + ['packaging_id' => $case, 'packs' => '1', 'unit_cost' => '4.00'],
            $base + ['packaging_id' => $case],
            $base + ['packs' => '1'],
            $base + ['pack_cost' => '100.00', 'quantity' => '5'],
            $base + ['packaging_id' => $case, 'packs' => '0'],
            $base + ['packaging_id' => $case, 'packs' => '-2'],
            $base + ['packaging_id' => $case, 'packs' => '1', 'pack_cost' => '100'],
            $base,
        ] as $body) {
            $this->receive($w, $body)->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        }
        $this->assertSame(0, StockMovement::count());
    }

    public function test_only_a_receivable_pack_of_this_product_in_this_store_can_be_used(): void
    {
        $w = $this->world();
        $case = $this->pack($w, $w['product'], 'Case', '24');
        $display = $this->pack($w, $w['product'], 'Display', '6', ['can_receive' => false]);
        $otherProduct = $this->pack($w, $w['product2'], 'Case', '12');
        $foreign = Product::factory()->create();
        $foreignPack = ProductBarcode::create(['product_id' => $foreign->id, 'store_id' => $foreign->store_id, 'name' => 'Case', 'units_per_base' => '10', 'is_primary' => false]);
        $product = ['product_id' => $w['product']->id, 'packs' => '1'];

        $this->receive($w, $product + ['packaging_id' => $display])->assertStatus(422)->assertJsonPath('error.details.packaging_id.0', 'This pack is not set up for receiving stock.');
        foreach ([$otherProduct, $foreignPack->id, (string) Str::uuid()] as $notMine) {
            $this->receive($w, $product + ['packaging_id' => $notMine])->assertStatus(422)->assertJsonPath('error.details.packaging_id.0', 'This is not a pack or barcode of this product.');
        }
        $this->receive($w, $product + ['packaging_id' => $case])->assertStatus(201);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_a_retried_pack_receipt_counts_once_and_the_key_cannot_be_reused_for_another(): void
    {
        $w = $this->world();
        $case = $this->pack($w, $w['product'], 'Case', '24');
        $body = ['product_id' => $w['product']->id, 'packaging_id' => $case, 'packs' => '5', 'pack_cost' => '1153.92'];
        $key = $this->key();  // 5 x 24 = 120 units

        $first = $this->receive($w, $body, $key)->assertStatus(201);
        $retry = $this->receive($w, $body, $key)->assertStatus(201);

        $this->assertSame($first->json('id'), $retry->json('id'));
        $this->assertSame('120.000', $this->balance($w['product']));
        $this->receive($w, ['packs' => '6'] + $body, $key)->assertStatus(409)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED']]);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_a_pack_receipt_needs_the_same_capability_and_terminal_as_any_receipt(): void
    {
        $w = $this->world();
        $case = $this->pack($w, $w['product'], 'Case', '24');
        $body = ['product_id' => $w['product']->id, 'packaging_id' => $case, 'packs' => '1', 'movement_type' => 'PURCHASE_RECEIPT'];

        $this->receive($w, $body, null, $w['cashier'])->assertStatus(403);
        $this->asUser($w['manager'])->postJson(self::RECEIPTS, $body, $this->key())->assertStatus(403)->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
        $this->asUser($w['manager'], $w['enroll1'])->postJson(self::RECEIPTS, $body)->assertStatus(400)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED']]);
        $this->receive($w, $body, null, $w['admin'])->assertStatus(201);
    }

    public function test_the_plain_receipt_is_exactly_as_it_was(): void
    {
        $w = $this->world();

        $response = $this->receive($w, ['product_id' => $w['product']->id, 'quantity' => '12.5', 'unit_cost' => '40.00', 'note' => 'Supplier delivery'])->assertStatus(201);

        $response->assertJson(['quantity' => '12.500', 'unit_cost' => '40.00', 'reason' => 'Supplier delivery']);
        $this->assertArrayNotHasKey('packaging', AuditEvent::findOrFail($response->json('reference_id'))->after_metadata);
    }
}
