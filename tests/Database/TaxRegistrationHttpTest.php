<?php

namespace Tests\Database;

use App\Models\TaxRegistration;
use App\Models\User;
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
        $admin = User::factory()->admin()->create();
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
        $admin = User::factory()->admin()->create();
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
        $admin = User::factory()->admin()->create();
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
}
