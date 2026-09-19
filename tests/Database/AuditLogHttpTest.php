<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use Tests\Database\Concerns\BuildsSalesScenario;

/** openapi.yaml auditEventList: read-only, session + AUDIT_VIEW, scoped to the actor's store. */
class AuditLogHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /** @return list<string> */
    private function ids(array $w, string $query = '', string $as = 'manager'): array
    {
        return collect($this->asUser($w[$as])->getJson('/api/v1/audit-events'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    private function event(string $storeId, string $type, string $when, array $extra = []): AuditEvent
    {
        $event = (new AuditEvent)->forceFill(['store_id' => $storeId, 'event_type' => $type, 'occurred_at' => $when] + $extra);
        $event->save();

        return $event;
    }

    public function test_the_log_is_newest_first_store_scoped_and_carries_the_whole_event(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong item'], $this->key())->assertStatus(201);
        $foreign = $this->world();
        $this->ring($foreign);

        $response = $this->asUser($w['manager'])->getJson('/api/v1/audit-events');

        $response->assertOk();
        $types = collect($response->json('data'))->pluck('event_type')->all();
        $this->assertSame(['SALE_VOIDED', 'SALE_VOID_REQUESTED', 'SALE_FINALIZED'], array_values(array_filter($types, fn ($t) => in_array($t, ['SALE_VOIDED', 'SALE_VOID_REQUESTED', 'SALE_FINALIZED'], true))));
        $this->assertSame(AuditEvent::where('store_id', $w['storeId'])->count(), $response->json('meta.total'));
        $this->assertGreaterThan($response->json('meta.total'), AuditEvent::count());
        $this->assertSame(
            ['id', 'event_type', 'actor_user_id', 'terminal_id', 'entity_type', 'entity_id', 'before_metadata', 'after_metadata', 'reason', 'request_id', 'occurred_at'],
            array_keys($response->json('data.0')),
        );

        $voided = collect($response->json('data'))->firstWhere('event_type', 'SALE_VOIDED');
        $this->assertSame($w['manager']->id, $voided['actor_user_id']);
        $this->assertSame($w['t2']->id, $voided['terminal_id']);
        $this->assertSame('void', $voided['entity_type']);
        $this->assertSame('Wrong item', $voided['reason']);
        $this->assertSame('COMPLETED', $voided['before_metadata']['sale_status']);
        $this->assertSame('VOIDED', $voided['after_metadata']['sale_status']);

        $this->assertSame(0, collect($response->json('data'))->where('actor_user_id', $foreign['cashier']->id)->count());
    }

    public function test_filters(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'x'], $this->key())->assertStatus(201);

        $finalized = AuditEvent::where('event_type', 'SALE_FINALIZED')->firstOrFail();
        $this->assertSame([$finalized->id], $this->ids($w, '?event_type=SALE_FINALIZED'));
        $this->assertSame([$finalized->id], $this->ids($w, "?entity_id={$finalized->entity_id}&entity_type=sale"));
        $this->assertSame([$finalized->id], $this->ids($w, "?actor_user_id={$w['cashier']->id}"));
        $this->assertCount(2, $this->ids($w, "?actor_user_id={$w['manager']->id}"));
        $this->assertCount(2, $this->ids($w, '?entity_type=void'));

        $old = $this->event($w['storeId'], 'STOCK_ADJUSTED', '2026-01-10 09:00:00');
        $this->assertSame([$old->id], $this->ids($w, '?from=2026-01-10&to=2026-01-10'));
        $this->assertSame([$old->id], $this->ids($w, '?to=2026-02-01'));
        $this->assertNotContains($old->id, $this->ids($w, '?from=2026-02-01'));

        // A value that can match nothing matches nothing -- never widens the listing.
        foreach (['?event_type=NOPE', '?entity_type=nope', '?actor_user_id=not-a-uuid', '?entity_id=x'] as $query) {
            $this->assertSame([], $this->ids($w, $query), $query);
        }
    }

    public function test_paging(): void
    {
        $w = $this->world();
        foreach (range(1, 3) as $i) {
            $this->event($w['storeId'], 'STOCK_ADJUSTED', "2026-01-0{$i} 09:00:00");
        }

        $page = $this->asUser($w['manager'])->getJson('/api/v1/audit-events?per_page=2&page=2');

        $this->assertSame(3, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.last_page'));
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(100, $this->asUser($w['manager'])->getJson('/api/v1/audit-events?per_page=999')->json('meta.per_page'));
    }

    public function test_metadata_is_an_object_or_null_never_a_list(): void
    {
        $w = $this->world();
        $this->event($w['storeId'], 'SETTINGS_CHANGED', '2026-01-01 09:00:00');
        $this->event($w['storeId'], 'SETTINGS_CHANGED', '2026-01-02 09:00:00', ['after_metadata' => []]);

        $response = $this->asUser($w['manager'])->getJson('/api/v1/audit-events?event_type=SETTINGS_CHANGED');

        $this->assertNull($response->json('data.1.before_metadata'));
        $this->assertNull($response->json('data.1.after_metadata'));
        $this->assertStringContainsString('"after_metadata":{}', $response->getContent());
    }

    public function test_only_holders_of_audit_view_can_read_and_nothing_can_write(): void
    {
        $w = $this->world();

        $this->asUser($w['admin'])->getJson('/api/v1/audit-events')->assertOk();
        $this->asUser($w['manager'])->getJson('/api/v1/audit-events')->assertOk();
        $this->asUser($w['cashier'])->getJson('/api/v1/audit-events')->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);

        foreach (['postJson', 'patchJson', 'putJson', 'deleteJson'] as $method) {
            $this->asUser($w['admin'])->{$method}('/api/v1/audit-events', [])->assertStatus(405);
        }
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/audit-events')->assertStatus(401);
    }
}
