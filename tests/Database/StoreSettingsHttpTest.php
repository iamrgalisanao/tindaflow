<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Store;
use App\Models\StoreSettings;
use Illuminate\Support\Facades\DB;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * openapi.yaml storeSettingsGet / storeSettingsUpdate (Module B). One settings row per store, created on the
 * first save, read by any session and changed only by STORE_SETTINGS_MANAGE. A change reaches invoices issued
 * afterwards and never the ones already issued (ADR-006).
 */
class StoreSettingsHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /** @return array<string, string> */
    private function full(array $overrides = []): array
    {
        return $overrides + [
            'business_name' => 'Aling Nena Store',
            'registered_name' => 'Nena Reyes Trading',
            'tin' => '123-456-789-00000',
            'business_address' => '12 Rizal St., Quezon City',
        ];
    }

    private function save(array $w, array $body, string $as = 'admin')
    {
        return $this->asUser($w[$as])->patchJson('/api/v1/store-settings', $body);
    }

    // ------------------------------------------------------------------- get

    public function test_a_store_without_settings_shows_a_blank_baseline_and_needs_no_terminal(): void
    {
        $w = $this->world();
        Store::findOrFail($w['storeId'])->update(['name' => 'My Sari-Sari']);

        $response = $this->asUser($w['cashier'])->getJson('/api/v1/store-settings');

        $response->assertOk();
        $this->assertSame(
            ['business_name', 'registered_name', 'business_address', 'tin', 'branch_code', 'invoice_header', 'invoice_footer', 'telephone', 'email', 'current_tax_registration'],
            array_keys($response->json()),
        );
        $this->assertSame('My Sari-Sari', $response->json('business_name'));
        $this->assertSame('', $response->json('registered_name'));
        $this->assertSame('', $response->json('tin'));
        $this->assertNull($response->json('invoice_footer'));
        $this->assertSame('VAT', $response->json('current_tax_registration.registration_type'));
        $this->assertNull($response->json('current_tax_registration.effective_to'));
        $this->assertSame(0, StoreSettings::count(), 'reading must not create the row');
    }

    public function test_a_store_with_no_tax_registration_reports_null_for_it(): void
    {
        $w = $this->world();
        DB::table('tax_registrations')->where('store_id', $w['storeId'])->delete();

        $this->asUser($w['admin'])->getJson('/api/v1/store-settings')->assertOk()->assertJson(['current_tax_registration' => null]);
    }

    public function test_get_shows_the_saved_settings_of_the_actors_own_store_only(): void
    {
        $w = $this->world();
        $other = $this->world();
        $this->save($w, $this->full(['branch_code' => '0001', 'invoice_footer' => 'Thank you!']))->assertOk();
        $this->save($other, $this->full(['business_name' => 'Somebody Else']))->assertOk();

        $mine = $this->asUser($w['manager'])->getJson('/api/v1/store-settings');

        $mine->assertOk()->assertJson(['business_name' => 'Aling Nena Store', 'tin' => '123-456-789-00000', 'branch_code' => '0001', 'invoice_footer' => 'Thank you!']);
        $this->assertSame(2, StoreSettings::count());
    }

    // ---------------------------------------------------------------- update

    public function test_the_first_save_creates_the_row_and_is_audited(): void
    {
        $w = $this->world();

        $response = $this->save($w, $this->full(['telephone' => '02-8123-4567', 'email' => 'nena@example.com']));

        $response->assertOk();
        $response->assertJson([
            'business_name' => 'Aling Nena Store',
            'registered_name' => 'Nena Reyes Trading',
            'tin' => '123-456-789-00000',
            'business_address' => '12 Rizal St., Quezon City',
            'telephone' => '02-8123-4567',
            'email' => 'nena@example.com',
            'branch_code' => null,
        ]);
        $this->assertDatabaseHas('store_settings', ['store_id' => $w['storeId'], 'tin' => '123-456-789-00000']);

        $event = AuditEvent::where('event_type', 'SETTINGS_CHANGED')->firstOrFail();
        $this->assertSame($w['admin']->id, $event->actor_user_id);
        $this->assertSame('store_settings', $event->entity_type);
        $this->assertSame($w['storeId'], $event->entity_id);
        $this->assertSame('Nena Reyes Trading', $event->after_metadata['registered_name']);
        $this->assertNull($event->before_metadata['registered_name']);
    }

    public function test_the_first_save_needs_every_identity_field(): void
    {
        $w = $this->world();

        $response = $this->save($w, ['business_address' => 'Somewhere']);

        $response->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        foreach (['business_name', 'registered_name', 'tin'] as $field) {
            $this->assertArrayHasKey($field, $response->json('error.details'));
        }
        $this->assertSame(0, StoreSettings::count());
        $this->assertSame(0, AuditEvent::where('event_type', 'SETTINGS_CHANGED')->count());
    }

    public function test_later_saves_change_only_what_is_sent_and_audit_only_what_changed(): void
    {
        $w = $this->world();
        $this->save($w, $this->full(['invoice_footer' => 'Old footer']))->assertOk();

        $response = $this->save($w, ['invoice_footer' => 'Thank you! Come again.', 'tin' => '123-456-789-00000']);

        $response->assertOk()->assertJson(['invoice_footer' => 'Thank you! Come again.', 'business_name' => 'Aling Nena Store']);
        $event = AuditEvent::where('event_type', 'SETTINGS_CHANGED')->orderByDesc('occurred_at')->orderByDesc('id')->firstOrFail();
        $this->assertSame(['invoice_footer' => 'Old footer'], $event->before_metadata);
        $this->assertSame(['invoice_footer' => 'Thank you! Come again.'], $event->after_metadata);
    }

    public function test_a_save_that_changes_nothing_writes_no_audit_event(): void
    {
        $w = $this->world();
        $this->save($w, $this->full())->assertOk();
        $before = AuditEvent::where('event_type', 'SETTINGS_CHANGED')->count();

        $this->save($w, $this->full())->assertOk();
        $this->save($w, [])->assertOk();

        $this->assertSame($before, AuditEvent::where('event_type', 'SETTINGS_CHANGED')->count());
    }

    public function test_optional_fields_can_be_cleared_but_identity_fields_cannot_be_blanked(): void
    {
        $w = $this->world();
        $this->save($w, $this->full(['branch_code' => '0001', 'invoice_header' => 'Welcome']))->assertOk();

        $this->save($w, ['branch_code' => null, 'invoice_header' => ''])->assertOk()->assertJson(['branch_code' => null, 'invoice_header' => null]);

        foreach (['business_name', 'registered_name', 'tin'] as $field) {
            $response = $this->save($w, [$field => '   ']);
            $response->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
            $this->assertArrayHasKey($field, $response->json('error.details'));
        }
        $this->assertSame('123-456-789-00000', StoreSettings::firstOrFail()->tin);
    }

    public function test_the_shape_of_each_value_is_validated(): void
    {
        $w = $this->world();
        $this->save($w, $this->full())->assertOk();

        foreach ([
            'email' => 'not-an-email',
            'business_name' => str_repeat('x', 256),
            'tin' => str_repeat('1', 51),
            'invoice_footer' => str_repeat('y', 501),
            'telephone' => str_repeat('9', 51),
        ] as $field => $value) {
            $response = $this->save($w, [$field => $value]);
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $response->json('error.details'), $field);
        }
        $this->assertSame('Aling Nena Store', StoreSettings::firstOrFail()->business_name);
    }

    public function test_the_store_can_never_be_chosen_by_the_request(): void
    {
        $w = $this->world();
        $other = $this->world();

        $this->save($w, $this->full(['store_id' => $other['storeId']]))->assertOk();

        $this->assertDatabaseHas('store_settings', ['store_id' => $w['storeId']]);
        $this->assertDatabaseMissing('store_settings', ['store_id' => $other['storeId']]);
    }

    // ---------------------------------------------------------------- access

    public function test_only_store_settings_manage_can_change_them(): void
    {
        $w = $this->world();

        $this->save($w, $this->full(), 'manager')->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->save($w, $this->full(), 'cashier')->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->assertSame(0, StoreSettings::count());

        $this->save($w, $this->full(), 'admin')->assertOk();
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/store-settings')->assertStatus(401);
        $this->patchJson('/api/v1/store-settings', ['tin' => '1'])->assertStatus(401);
    }

    // -------------------------------------------- effect on invoices (ADR-006)

    public function test_a_change_reaches_new_invoices_and_never_the_ones_already_issued(): void
    {
        $w = $this->world();
        $before = $this->ring($w);

        $this->save($w, $this->full(['branch_code' => '0007', 'invoice_header' => 'Fresh, hot & tasty', 'invoice_footer' => 'Thank you!']))->assertOk();
        $after = $this->ring($w);

        $old = $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$before['invoice']['id']}")->json('render_html');
        $new = $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$after['invoice']['id']}")->json('render_html');

        // The invoice issued before the settings existed still prints exactly as it did.
        $this->assertStringNotContainsString('Nena Reyes Trading', $old);
        $this->assertStringNotContainsString('Thank you!', $old);
        $this->assertStringContainsString('Nena Reyes Trading', $new);
        $this->assertStringContainsString('12 Rizal St., Quezon City', $new);
        $this->assertStringContainsString('TIN: 123-456-789-00000 &middot; Branch: 0007', $new);
        $this->assertStringContainsString('Fresh, hot &amp; tasty', $new);
        $this->assertStringContainsString('Thank you!', $new);

        // And changing the settings again leaves both untouched.
        $this->save($w, ['registered_name' => 'Renamed Corp', 'invoice_footer' => 'Different'])->assertOk();
        $this->assertSame($new, $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$after['invoice']['id']}")->json('render_html'));
        $this->assertSame($old, $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$before['invoice']['id']}")->json('render_html'));
        $this->assertStringNotContainsString('Renamed Corp', $new);
    }
}
