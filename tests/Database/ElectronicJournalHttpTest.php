<?php

namespace Tests\Database;

use App\Models\ElectronicJournalEntry;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsSalesScenario;

/** openapi.yaml journalEntryList: read-only, session + JOURNAL_VIEW, JSON or CSV (csv-export-contract.md). */
class ElectronicJournalHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /** @return list<string> */
    private function ids(array $w, string $query = ''): array
    {
        return collect($this->asUser($w['manager'])->getJson('/api/v1/electronic-journal-entries'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    private function entry(string $storeId, string $type, string $when, ?string $terminalId = null): ElectronicJournalEntry
    {
        $entry = (new ElectronicJournalEntry)->forceFill([
            'store_id' => $storeId, 'terminal_id' => $terminalId, 'event_type' => $type,
            'source_type' => 'stock_movement', 'source_id' => (string) Str::uuid(), 'payload_json' => [], 'occurred_at' => $when,
        ]);
        $entry->save();

        return $entry;
    }

    /** @return array<string, mixed> a sale plus a void, so INVOICE and VOID entries both exist */
    private function withSaleAndVoid(array $w): array
    {
        $sale = $this->ring($w);
        $void = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'x'], $this->key())->json();

        return ['sale' => $sale, 'void' => $void];
    }

    public function test_the_journal_lists_the_projected_events_newest_first_and_scoped(): void
    {
        $w = $this->world();
        ['sale' => $sale, 'void' => $void] = $this->withSaleAndVoid($w);
        $this->ring($this->world());

        $response = $this->asUser($w['manager'])->getJson('/api/v1/electronic-journal-entries');

        $response->assertOk();
        $this->assertSame(['VOID', 'INVOICE'], collect($response->json('data'))->pluck('event_type')->all());
        $this->assertSame(
            ['id', 'store_id', 'terminal_id', 'event_type', 'source_type', 'source_id', 'audit_event_id', 'payload_json', 'occurred_at'],
            array_keys($response->json('data.0')),
        );
        $entry = $response->json('data.0');
        $this->assertSame($w['storeId'], $entry['store_id']);
        $this->assertSame($void['id'], $entry['source_id']);
        $this->assertSame('void', $entry['source_type']);
        $this->assertSame($sale['invoice_number'], $entry['payload_json']['invoice_number']);
        $this->assertNotNull($entry['audit_event_id']);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_filters(): void
    {
        $w = $this->world();
        $this->withSaleAndVoid($w);
        $old = $this->entry($w['storeId'], 'STOCK_ADJUSTED', '2026-01-10 09:00:00', $w['t2']->id);

        $this->assertSame([$old->id], $this->ids($w, '?event_type=STOCK_ADJUSTED'));
        $this->assertCount(1, $this->ids($w, '?event_type=INVOICE'));
        $this->assertSame([$old->id], $this->ids($w, "?terminal_id={$w['t2']->id}&from=2026-01-10&to=2026-01-10"));
        $this->assertContains($old->id, $this->ids($w, "?terminal_id={$w['t2']->id}"));
        $this->assertNotContains($old->id, $this->ids($w, '?from=2026-02-01'));

        foreach (['?event_type=NOPE', '?event_type=invoice', '?terminal_id=not-a-uuid'] as $query) {
            $this->assertSame([], $this->ids($w, $query), $query);
        }
    }

    public function test_the_csv_export_keeps_the_pinned_columns_and_covers_the_whole_filtered_set(): void
    {
        $w = $this->world();
        $this->withSaleAndVoid($w);
        foreach (range(1, 5) as $i) {
            $this->entry($w['storeId'], 'STOCK_ADJUSTED', "2026-01-0{$i} 09:00:00");
        }

        $response = $this->asUser($w['manager'])->withHeader('Accept', 'text/csv')->get('/api/v1/electronic-journal-entries?per_page=2');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
        $this->assertSame('occurred_at,event_type,source_type,source_id,terminal_id,audit_event_id', $lines[0]);
        // The paging parameters do not apply to the export: header + 7 entries.
        $this->assertCount(8, $lines);

        $rows = array_map('str_getcsv', array_slice($lines, 1));
        $this->assertSame(['VOID', 'INVOICE'], array_slice(array_column($rows, 1), 0, 2));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $rows[0][0]);
        // A null terminal or audit event is an empty field, never the word null.
        $stock = collect($rows)->firstWhere(1, 'STOCK_ADJUSTED');
        $this->assertSame('', $stock[4]);
        $this->assertSame('', $stock[5]);
        $this->assertStringNotContainsString('null', strtolower($response->streamedContent()));
    }

    public function test_the_csv_honours_the_filters_and_a_filter_with_no_match_is_just_the_header(): void
    {
        $w = $this->world();
        $this->withSaleAndVoid($w);

        $only = $this->asUser($w['manager'])->withHeader('Accept', 'text/csv')->get('/api/v1/electronic-journal-entries?event_type=VOID');
        $lines = array_values(array_filter(explode("\n", $only->streamedContent())));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString(',VOID,void,', $lines[1]);

        $none = $this->asUser($w['manager'])->withHeader('Accept', 'text/csv')->get('/api/v1/electronic-journal-entries?event_type=NOPE');
        $this->assertSame("occurred_at,event_type,source_type,source_id,terminal_id,audit_event_id\n", $none->streamedContent());
    }

    public function test_only_holders_of_journal_view_can_read_and_nothing_can_write(): void
    {
        $w = $this->world();

        $this->asUser($w['admin'])->getJson('/api/v1/electronic-journal-entries')->assertOk();
        $this->asUser($w['manager'])->getJson('/api/v1/electronic-journal-entries')->assertOk();
        $this->asUser($w['cashier'])->getJson('/api/v1/electronic-journal-entries')->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->asUser($w['cashier'])->withHeader('Accept', 'text/csv')->get('/api/v1/electronic-journal-entries')->assertStatus(403);

        foreach (['postJson', 'patchJson', 'putJson', 'deleteJson'] as $method) {
            $this->asUser($w['admin'])->{$method}('/api/v1/electronic-journal-entries', [])->assertStatus(405);
        }
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/electronic-journal-entries')->assertStatus(401);
    }
}
