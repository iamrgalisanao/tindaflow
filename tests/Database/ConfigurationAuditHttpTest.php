<?php

namespace Tests\Database;

use App\Domain\Exceptions\ConcurrencyConflictException;
use App\Models\AuditEvent;
use App\Models\FiscalInstallation;
use App\Models\InvoiceSeries;
use App\Models\Terminal;
use App\Models\User;
use App\Services\StoreSetup\InvoiceSeriesService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Configuration changes leave a record, like the Users screen does: who revoked or enrolled a till, who created or closed an
 * invoice series, added a fiscal installation or tax registration, assigned a till to an installation, or changed a stock
 * location. Each event is written in the same transaction as the change, names the signed-in administrator, and never carries a
 * token, credential or password.
 */
class ConfigurationAuditHttpTest extends PostgresSchemaTestCase
{
    private function asAdmin(User $admin): static
    {
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();

        $login = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password']);
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    private function events(string $type): Collection
    {
        return AuditEvent::where('event_type', $type)->get();
    }

    public function test_revoking_a_till_is_audited_and_revoking_it_again_changes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $till = Terminal::factory()->create(['store_id' => $admin->store_id, 'terminal_code' => 'TILL-1']);

        $this->asAdmin($admin)->postJson("/api/v1/terminals/{$till->id}/revoke")->assertOk();
        $first = $till->refresh()->revoked_at;
        $this->travel(2)->hours();
        $this->asAdmin($admin)->postJson("/api/v1/terminals/{$till->id}/revoke")->assertOk();

        $this->assertEquals($first, $till->refresh()->revoked_at, 'the original revocation time stands');
        $event = $this->events('TERMINAL_REVOKED')->sole();
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame($till->id, $event->terminal_id);
        $this->assertSame('TILL-1', $event->after_metadata['terminal_code']);
    }

    public function test_issuing_a_token_and_enrolling_are_audited_without_the_token_or_credential(): void
    {
        $admin = User::factory()->admin()->create();
        $till = Terminal::factory()->unenrolled()->create(['store_id' => $admin->store_id, 'terminal_code' => 'TILL-1']);

        $token = $this->asAdmin($admin)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $till->id])->assertStatus(201)->json('token');
        $enrolled = $this->asAdmin($admin)->postJson('/api/v1/terminal/enroll', ['token' => $token])->assertOk();

        $issued = $this->events('TERMINAL_ENROLLMENT_TOKEN_ISSUED')->sole();
        $this->assertSame($admin->id, $issued->actor_user_id);
        $this->assertSame('TILL-1', $issued->after_metadata['terminal_code']);
        $done = $this->events('TERMINAL_ENROLLED')->sole();
        $this->assertSame($admin->id, $done->actor_user_id);
        $this->assertFalse($done->after_metadata['re_enrolled_after_revocation']);

        $credential = collect($enrolled->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal')?->getValue();
        $log = json_encode(AuditEvent::all()->toArray());
        $this->assertStringNotContainsString($token, $log);
        $this->assertStringNotContainsString(hash('sha256', $token), $log);
        $this->assertNotNull($credential);
        $this->assertStringNotContainsString($credential, $log);
    }

    public function test_re_enrolling_a_revoked_till_says_so(): void
    {
        $admin = User::factory()->admin()->create();
        $till = Terminal::factory()->create(['store_id' => $admin->store_id, 'revoked_at' => now()]);

        $token = $this->asAdmin($admin)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $till->id])->json('token');
        $this->asAdmin($admin)->postJson('/api/v1/terminal/enroll', ['token' => $token])->assertOk();

        $this->assertTrue($this->events('TERMINAL_ENROLLED')->sole()->after_metadata['re_enrolled_after_revocation']);
    }

    public function test_an_invoice_series_creation_and_closing_are_audited(): void
    {
        $admin = $this->fiscalAdmin();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);

        $created = $this->asAdmin($admin)->postJson('/api/v1/invoice-series', ['fiscal_installation_id' => $installation->id, 'series_code' => 'MAIN', 'prefix' => 'INV-', 'starting_number' => 1])->assertStatus(201);
        $this->asAdmin($admin)->postJson("/api/v1/invoice-series/{$created->json('id')}/close")->assertOk();

        $open = $this->events('INVOICE_SERIES_CREATED')->sole();
        $this->assertSame($admin->id, $open->actor_user_id);
        $this->assertSame($created->json('id'), $open->entity_id);
        $this->assertSame('MAIN', $open->after_metadata['series_code']);
        $close = $this->events('INVOICE_SERIES_CLOSED')->sole();
        $this->assertSame('ACTIVE', $close->before_metadata['status']);
        $this->assertNull($close->after_metadata['last_number_issued'], 'it issued no number');
    }

    public function test_closing_a_series_that_issued_numbers_records_the_last_one(): void
    {
        $admin = $this->fiscalAdmin();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $series = InvoiceSeries::factory()->create(['store_id' => $admin->store_id, 'fiscal_installation_id' => $installation->id, 'current_number' => 41, 'starting_number' => 1]);

        $this->asAdmin($admin)->postJson("/api/v1/invoice-series/{$series->id}/close")->assertOk();

        $this->assertSame(41, $this->events('INVOICE_SERIES_CLOSED')->sole()->after_metadata['last_number_issued']);
    }

    public function test_a_refused_series_writes_no_event(): void
    {
        $admin = $this->fiscalAdmin();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $series = InvoiceSeries::factory()->create(['store_id' => $admin->store_id, 'fiscal_installation_id' => $installation->id]);

        $this->asAdmin($admin)->postJson('/api/v1/invoice-series', ['fiscal_installation_id' => $series->fiscal_installation_id, 'series_code' => 'B', 'starting_number' => 1])->assertStatus(409);
        $this->asAdmin($admin)->postJson("/api/v1/invoice-series/{$series->id}/close")->assertOk();
        $this->asAdmin($admin)->postJson("/api/v1/invoice-series/{$series->id}/close")->assertStatus(409);

        $this->assertSame(0, $this->events('INVOICE_SERIES_CREATED')->count());
        $this->assertSame(1, $this->events('INVOICE_SERIES_CLOSED')->count());
    }

    public function test_a_fiscal_installation_and_assigning_a_till_to_it_are_audited(): void
    {
        $admin = $this->fiscalAdmin();
        $till = Terminal::factory()->create(['store_id' => $admin->store_id, 'terminal_code' => 'TILL-1']);

        $installation = $this->asAdmin($admin)->postJson('/api/v1/fiscal-installations', ['deployment_model' => 'STANDALONE', 'software_version' => '1.0.0'])->assertStatus(201);
        $this->asAdmin($admin)->postJson("/api/v1/fiscal-installations/{$installation->json('id')}/terminals", ['terminal_id' => $till->id])->assertStatus(201);
        $other = $this->asAdmin($admin)->postJson('/api/v1/fiscal-installations', ['deployment_model' => 'STANDALONE', 'software_version' => '1.0.1'])->json('id');
        $this->asAdmin($admin)->postJson("/api/v1/fiscal-installations/{$other}/terminals", ['terminal_id' => $till->id])->assertStatus(201);

        $this->assertSame(2, $this->events('FISCAL_INSTALLATION_CREATED')->count());
        $assigned = $this->events('FISCAL_INSTALLATION_TERMINAL_ASSIGNED');
        $this->assertSame(2, $assigned->count());
        $moved = $assigned->first(fn ($event) => $event->before_metadata !== null);
        $this->assertSame($installation->json('id'), $moved->before_metadata['fiscal_installation_id']);
        $this->assertSame($other, $moved->after_metadata['fiscal_installation_id']);
        $this->assertSame($till->id, $moved->terminal_id);
        $this->assertSame($admin->id, $moved->actor_user_id);
    }

    public function test_a_normal_tax_registration_is_audited_with_the_one_it_closed(): void
    {
        $admin = $this->fiscalAdmin();

        $this->asAdmin($admin)->postJson('/api/v1/tax-registrations', ['registration_type' => 'NON_VAT', 'effective_from' => now()->subDays(30)->toDateString()])->assertStatus(201);
        $this->asAdmin($admin)->postJson('/api/v1/tax-registrations', ['registration_type' => 'VAT', 'effective_from' => now()->subDays(5)->toDateString()])->assertStatus(201);

        $events = $this->events('TAX_REGISTRATION_CREATED');
        $this->assertSame(2, $events->count());
        $second = $events->first(fn ($event) => $event->after_metadata['registration_type'] === 'VAT');
        $this->assertSame($admin->id, $second->actor_user_id);
        $this->assertSame(now()->subDays(6)->toDateString(), $second->after_metadata['closed_previous_as_of']);
        $this->assertNull($events->first(fn ($event) => $event->after_metadata['registration_type'] === 'NON_VAT')->after_metadata['closed_previous_as_of']);
    }

    public function test_stock_location_changes_are_audited_and_a_no_op_is_not(): void
    {
        $admin = User::factory()->admin()->create();

        $location = $this->asAdmin($admin)->postJson('/api/v1/inventory-locations', ['name' => 'Counter'])->assertStatus(201);
        $id = $location->json('id');
        $this->asAdmin($admin)->patchJson("/api/v1/inventory-locations/{$id}", ['name' => 'Counter'])->assertOk();
        $this->asAdmin($admin)->patchJson("/api/v1/inventory-locations/{$id}", ['name' => 'Back room'])->assertOk();

        $this->assertSame(1, $this->events('INVENTORY_LOCATION_CREATED')->count());
        $update = $this->events('INVENTORY_LOCATION_UPDATED')->sole();
        $this->assertSame('Counter', $update->before_metadata['name']);
        $this->assertSame('Back room', $update->after_metadata['name']);
        $this->assertSame($admin->id, $update->actor_user_id);
    }

    public function test_the_audit_row_and_the_series_change_succeed_or_fail_together(): void
    {
        $admin = $this->fiscalAdmin();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT test_no_series_events CHECK (event_type <> 'INVOICE_SERIES_CREATED')");

        try {
            $this->expectException(ConcurrencyConflictException::class);
            app(InvoiceSeriesService::class)->create($admin->store_id, ['fiscal_installation_id' => $installation->id, 'series_code' => 'MAIN', 'starting_number' => 1], $admin);
        } finally {
            DB::rollBack();
            DB::beginTransaction();
            $this->assertSame(0, InvoiceSeries::count());
        }
    }
}
