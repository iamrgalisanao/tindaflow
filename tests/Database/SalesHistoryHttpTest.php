<?php

namespace Tests\Database;

use App\Models\Sale;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsSalesScenario;

/** openapi.yaml saleList / saleGet: session-only reads, scoped to the actor's store. */
class SalesHistoryHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /** @return list<string> */
    private function ids(array $w, string $query = ''): array
    {
        return collect($this->asUser($w['cashier'])->getJson('/api/v1/sales'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_the_list_is_newest_first_store_scoped_and_needs_no_terminal(): void
    {
        $w = $this->world();
        $first = $this->ring($w);
        $second = $this->ring($w);
        $this->ring($this->world()); // another store

        $response = $this->asUser($w['cashier'])->getJson('/api/v1/sales');

        $response->assertOk();
        $this->assertSame([$second['id'], $first['id']], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(
            ['id', 'uuid', 'transaction_number', 'invoice_number', 'store_id', 'terminal_id', 'fiscal_day_id', 'shift_id', 'cashier_id', 'sold_at', 'grand_total', 'status'],
            array_keys($response->json('data.0')),
        );
        $this->assertSame($second['invoice_number'], $response->json('data.0.invoice_number'));
    }

    public function test_filters(): void
    {
        $w = $this->world();
        $cash = $this->ring($w, [[$w['product'], '1']]);
        $card = $this->ring($w, [[$w['product2'], '3']], 'CARD');
        $voided = $this->ring($w, [[$w['product'], '1']]);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$voided['id']}/void", ['reason' => 'x'], $this->key())->assertStatus(201);

        $this->assertSame([$voided['id']], $this->ids($w, '?status=VOIDED'));
        $this->assertEqualsCanonicalizing([$cash['id'], $card['id']], $this->ids($w, '?status=COMPLETED'));
        $this->assertSame([$card['id']], $this->ids($w, '?payment_method=CARD'));
        $this->assertEqualsCanonicalizing([$cash['id'], $voided['id']], $this->ids($w, "?product_id={$w['product']->id}"));
        $this->assertSame([$cash['id']], $this->ids($w, '?transaction_number='.$cash['transaction_number']));
        $this->assertSame([$card['id']], $this->ids($w, '?invoice_number='.$card['invoice_number']));
        $this->assertCount(3, $this->ids($w, "?cashier_id={$w['cashier']->id}"));
        $this->assertCount(3, $this->ids($w, "?terminal_id={$w['t1']->id}"));
        $this->assertSame([], $this->ids($w, "?terminal_id={$w['t2']->id}"));

        $today = now()->toDateString();
        $this->assertCount(3, $this->ids($w, "?from={$today}&to={$today}"));
        $this->assertSame([], $this->ids($w, '?to='.now()->subDays(2)->toDateString()));
        $this->assertSame([], $this->ids($w, '?from='.now()->addDay()->toDateString()));

        // A value that can match nothing matches nothing -- never widens the listing.
        foreach (['?status=NOPE', '?payment_method=BARTER', '?product_id=not-a-uuid', '?cashier_id=x', '?terminal_id=x', '?transaction_number=nope', '?invoice_number=000000'] as $query) {
            $this->assertSame([], $this->ids($w, $query), $query);
        }
    }

    public function test_sorting_and_page_size(): void
    {
        $w = $this->world();
        $small = $this->ring($w, [[$w['product2'], '1']]);
        $big = $this->ring($w, [[$w['product'], '5']]);
        $mid = $this->ring($w, [[$w['product'], '2']]);

        $this->assertSame([$big['id'], $mid['id'], $small['id']], $this->ids($w, '?sort=-grand_total'));
        $this->assertSame([$small['id'], $mid['id'], $big['id']], $this->ids($w, '?sort=grand_total'));
        $this->assertSame([$small['id'], $big['id'], $mid['id']], $this->ids($w, '?sort=sold_at'));
        $this->assertSame([$mid['id'], $big['id'], $small['id']], $this->ids($w, '?sort=-sold_at'));
        $this->assertSame([$mid['id'], $big['id'], $small['id']], $this->ids($w, '?sort=nonsense'));

        $page = $this->asUser($w['cashier'])->getJson('/api/v1/sales?per_page=2&page=2');
        $this->assertSame([$small['id']], collect($page->json('data'))->pluck('id')->all());
        $this->assertSame(100, $this->asUser($w['cashier'])->getJson('/api/v1/sales?per_page=999')->json('meta.per_page'));
    }

    public function test_the_detail_carries_everything_needed_to_review_and_correct_a_sale(): void
    {
        $w = $this->world();
        $sale = $this->ring($w, [[$w['product'], '2'], [$w['product2'], '1']]);

        $detail = $this->asUser($w['cashier'])->getJson("/api/v1/sales/{$sale['id']}");

        $detail->assertOk();
        $detail->assertJson(['id' => $sale['id'], 'status' => 'COMPLETED', 'grand_total' => '250.00', 'void' => null, 'refunds' => []]);
        $this->assertCount(2, $detail->json('items'));
        $this->assertSame('250.00', $detail->json('payments.0.amount'));
        $this->assertSame($sale['invoice_number'], $detail->json('invoice.invoice_number'));
        $this->assertSame('CASH', $detail->json('payments.0.method'));
    }

    public function test_a_missing_or_foreign_sale_is_not_found(): void
    {
        $w = $this->world();
        $foreign = $this->ring($this->world());

        foreach ([(string) Str::uuid(), $foreign['id']] as $id) {
            $this->asUser($w['cashier'])->getJson("/api/v1/sales/{$id}")->assertStatus(404)->assertJson(['error' => ['code' => 'SALE_NOT_FOUND']]);
        }
        $this->assertSame(1, Sale::where('id', $foreign['id'])->count());
    }

    public function test_reads_need_a_session(): void
    {
        $id = (string) Str::uuid();

        $this->getJson('/api/v1/sales')->assertStatus(401);
        $this->getJson("/api/v1/sales/{$id}")->assertStatus(401);
    }
}
