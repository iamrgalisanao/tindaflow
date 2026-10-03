<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\TaxRegistration;
use App\Models\User;
use App\Services\StoreSetup\StoreSetupReadinessService;
use Illuminate\Testing\TestResponse;

/** openapi.yaml StoreSettings tag's tax-registrations paths -- already-frozen contract, implemented here for the first time. */
class TaxRegistrationHttpTest extends PostgresSchemaTestCase
{
    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function forwardSessionCookie(TestResponse $prior): static
    {
        $this->app['auth']->forgetGuards();

        $cookie = collect($prior->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    public function test_an_admin_can_create_a_tax_registration(): void
    {
        $admin = $this->fiscalAdmin();
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/tax-registrations', [
            'registration_type' => 'VAT',
            'effective_from' => '2026-01-01',
        ]);

        $response->assertStatus(201);
        $response->assertJson(['registration_type' => 'VAT', 'effective_from' => '2026-01-01', 'effective_to' => null]);
    }

    public function test_creating_a_new_registration_closes_the_prior_current_one(): void
    {
        $admin = $this->fiscalAdmin();
        $login = $this->login($admin);

        $first = $this->forwardSessionCookie($login)->postJson('/api/v1/tax-registrations', [
            'registration_type' => 'NON_VAT',
            'effective_from' => '2026-01-01',
        ]);
        $first->assertStatus(201);

        $second = $this->forwardSessionCookie($login)->postJson('/api/v1/tax-registrations', [
            'registration_type' => 'VAT',
            'effective_from' => '2026-06-01',
        ]);
        $second->assertStatus(201);

        $firstRow = TaxRegistration::find($first->json('id'));
        $this->assertSame('2026-05-31', $firstRow->effective_to->toDateString(), 'prior registration must close the day before the new one starts, never the same day (resolver reads inclusive-both-ends)');
        $this->assertNull(TaxRegistration::find($second->json('id'))->effective_to);
    }

    public function test_a_new_registration_effective_before_the_current_one_is_rejected(): void
    {
        $admin = $this->fiscalAdmin();
        $login = $this->login($admin);

        $this->forwardSessionCookie($login)->postJson('/api/v1/tax-registrations', [
            'registration_type' => 'VAT',
            'effective_from' => '2026-06-01',
        ])->assertStatus(201);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/tax-registrations', [
            'registration_type' => 'VAT',
            'effective_from' => '2026-01-01',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
    }

    public function test_a_non_admin_cannot_create_a_tax_registration(): void
    {
        $cashier = User::factory()->create();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/tax-registrations', [
            'registration_type' => 'VAT',
            'effective_from' => '2026-01-01',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
    }

    public function test_list_requires_no_capability_and_scopes_to_the_actors_store(): void
    {
        $cashier = User::factory()->create();
        TaxRegistration::factory()->create(['store_id' => $cashier->store_id]);
        TaxRegistration::factory()->create();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->getJson('/api/v1/tax-registrations');

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    private function register(TestResponse $login, string $from, string $type = 'VAT'): TestResponse
    {
        return $this->forwardSessionCookie($login)->postJson('/api/v1/tax-registrations', ['registration_type' => $type, 'effective_from' => $from]);
    }

    public function test_a_registration_that_has_not_started_is_corrected_in_place_instead_of_stranding_the_shop(): void
    {
        $admin = $this->fiscalAdmin();
        $login = $this->login($admin);
        $mistaken = $this->register($login, now()->addDays(7)->toDateString());
        $mistaken->assertStatus(201);

        $fixed = $this->register($login, now()->toDateString());

        $fixed->assertStatus(201);
        $this->assertSame($mistaken->json('id'), $fixed->json('id'), 'the same row, corrected');
        $this->assertSame(1, TaxRegistration::where('store_id', $admin->store_id)->count());
        $row = TaxRegistration::sole();
        $this->assertSame(now()->toDateString(), $row->effective_from->toDateString());
        $this->assertNull($row->effective_to);
        $event = AuditEvent::where('event_type', 'TAX_REGISTRATION_CORRECTED')->sole();
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame(now()->addDays(7)->toDateString(), $event->before_metadata['effective_from']);
        $this->assertSame(now()->toDateString(), $event->after_metadata['effective_from']);
    }

    public function test_correcting_the_type_and_a_later_date_also_works(): void
    {
        $admin = $this->fiscalAdmin();
        $login = $this->login($admin);
        $this->register($login, now()->addDays(7)->toDateString(), 'VAT')->assertStatus(201);

        $this->register($login, now()->addDays(14)->toDateString(), 'NON_VAT')->assertStatus(201);

        $row = TaxRegistration::sole();
        $this->assertSame('NON_VAT', $row->registration_type);
        $this->assertSame(now()->addDays(14)->toDateString(), $row->effective_from->toDateString());
    }

    public function test_correcting_a_registration_that_has_not_started_moves_the_end_of_the_one_before_it(): void
    {
        $admin = $this->fiscalAdmin();
        $login = $this->login($admin);
        $this->register($login, '2026-01-01')->assertStatus(201);
        $this->register($login, now()->addDays(7)->toDateString())->assertStatus(201);
        $this->assertSame(now()->addDays(6)->toDateString(), TaxRegistration::where('effective_from', '2026-01-01')->sole()->effective_to->toDateString());

        $this->register($login, now()->addDays(2)->toDateString())->assertStatus(201);

        $this->assertSame(2, TaxRegistration::count());
        $this->assertSame(now()->addDay()->toDateString(), TaxRegistration::where('effective_from', '2026-01-01')->sole()->effective_to->toDateString(), 'no gap and no overlap');
        $this->assertSame(1, TaxRegistration::whereNull('effective_to')->count());
    }

    public function test_a_correction_must_still_leave_the_previous_registration_a_day_of_its_own(): void
    {
        $admin = $this->fiscalAdmin();
        $login = $this->login($admin);
        $this->register($login, '2026-01-01')->assertStatus(201);
        $this->register($login, now()->addDays(7)->toDateString())->assertStatus(201);

        $this->register($login, '2026-01-01')->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);

        $this->assertSame(now()->addDays(7)->toDateString(), TaxRegistration::whereNull('effective_to')->sole()->effective_from->toDateString(), 'nothing changed');
        $this->assertSame(0, AuditEvent::where('event_type', 'TAX_REGISTRATION_CORRECTED')->count());
    }

    public function test_a_registration_that_has_started_is_never_edited(): void
    {
        $admin = $this->fiscalAdmin();
        $login = $this->login($admin);
        $started = $this->register($login, now()->subDays(10)->toDateString());

        $this->register($login, now()->subDays(20)->toDateString())->assertStatus(422);
        $next = $this->register($login, now()->addDay()->toDateString());

        $next->assertStatus(201);
        $this->assertNotSame($started->json('id'), $next->json('id'), 'a new row, the started one is closed, not rewritten');
        $this->assertSame(now()->toDateString(), TaxRegistration::find($started->json('id'))->effective_to->toDateString());
        $this->assertSame(0, AuditEvent::where('event_type', 'TAX_REGISTRATION_CORRECTED')->count());
    }

    public function test_once_corrected_to_today_the_till_has_a_current_registration(): void
    {
        $admin = $this->fiscalAdmin();
        $login = $this->login($admin);
        $this->register($login, now()->addDays(7)->toDateString());
        $this->register($login, now()->toDateString());

        $ready = app(StoreSetupReadinessService::class)->forStore($admin->store_id);

        $this->assertTrue($ready['checks']['tax_registration']);
    }
}
