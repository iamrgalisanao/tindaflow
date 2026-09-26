<?php

namespace Tests\Database;

use App\Models\FiscalInstallation;
use App\Models\InvoiceSeries;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * New forward-committed operations (store-setup pass, 2026-09-18) -- the
 * table InvoiceSeriesAllocator reads at checkout time to allocate an
 * invoice number.
 */
class InvoiceSeriesHttpTest extends PostgresSchemaTestCase
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

    public function test_creating_a_series_bootstraps_current_number_one_below_starting_number(): void
    {
        $admin = User::factory()->admin()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'MAIN',
            'prefix' => 'INV',
            'starting_number' => 100,
        ]);

        $response->assertStatus(201);
        $response->assertJson(['series_code' => 'MAIN', 'current_number' => 99, 'starting_number' => 100, 'status' => 'ACTIVE']);
    }

    public function test_a_second_active_series_for_the_same_installation_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);

        $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'MAIN',
            'starting_number' => 1,
        ])->assertStatus(201);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'SECONDARY',
            'starting_number' => 1,
        ]);

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'INVOICE_SERIES_ALREADY_ACTIVE']]);
    }

    public function test_closing_a_series_allows_a_new_one_to_be_activated(): void
    {
        $admin = User::factory()->admin()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);

        $first = $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'MAIN',
            'starting_number' => 1,
        ]);
        $first->assertStatus(201);

        $closed = $this->forwardSessionCookie($login)->postJson("/api/v1/invoice-series/{$first->json('id')}/close");
        $closed->assertOk();
        $closed->assertJson(['status' => 'CLOSED']);

        $second = $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'MAIN-2',
            'starting_number' => 1000,
        ]);

        $second->assertStatus(201);
    }

    /** Creates a series through the API, then makes it look like $issued invoices were allocated from it, and closes it. */
    private function closedSeriesThatIssued(TestResponse $login, FiscalInstallation $installation, int $start, int $issued): void
    {
        $created = $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'OLD-'.$start,
            'starting_number' => $start,
        ]);
        $created->assertStatus(201);

        InvoiceSeries::whereKey($created->json('id'))->update(['current_number' => $start - 1 + $issued]);

        $this->forwardSessionCookie($login)->postJson("/api/v1/invoice-series/{$created->json('id')}/close")->assertOk();
    }

    public function test_a_replacement_series_cannot_start_at_or_below_a_number_already_issued(): void
    {
        $admin = User::factory()->admin()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);
        $this->closedSeriesThatIssued($login, $installation, 1, 250);

        foreach ([1, 100, 250] as $start) {
            $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
                'fiscal_installation_id' => $installation->id,
                'series_code' => 'NEW-'.$start,
                'starting_number' => $start,
            ])->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        }

        $this->assertSame(1, InvoiceSeries::where('fiscal_installation_id', $installation->id)->count());

        $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'NEW-251',
            'starting_number' => 251,
        ])->assertStatus(201)->assertJson(['current_number' => 250, 'starting_number' => 251]);
    }

    public function test_the_refusal_names_the_starting_number_field(): void
    {
        $admin = User::factory()->admin()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);
        $this->closedSeriesThatIssued($login, $installation, 1, 250);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'AGAIN',
            'starting_number' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertArrayHasKey('starting_number', $response->json('error.details'));
    }

    public function test_an_earlier_series_that_never_issued_a_number_does_not_block_a_lower_start(): void
    {
        $admin = User::factory()->admin()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);
        $this->closedSeriesThatIssued($login, $installation, 1000, 0);

        $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'CORRECTED',
            'starting_number' => 1,
        ])->assertStatus(201);
    }

    public function test_a_series_of_another_fiscal_installation_does_not_reserve_numbers(): void
    {
        $admin = User::factory()->admin()->create();
        $used = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $other = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);
        $this->closedSeriesThatIssued($login, $used, 1, 250);

        $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $other->id,
            'series_code' => 'OTHER',
            'starting_number' => 1,
        ])->assertStatus(201);
    }

    public function test_an_active_series_still_answers_already_active_not_a_number_error(): void
    {
        $admin = User::factory()->admin()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);
        $first = $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'MAIN',
            'starting_number' => 1,
        ]);
        InvoiceSeries::whereKey($first->json('id'))->update(['current_number' => 40]);

        $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'SECOND',
            'starting_number' => 1,
        ])->assertStatus(409)->assertJson(['error' => ['code' => 'INVOICE_SERIES_ALREADY_ACTIVE']]);
    }

    public function test_closing_an_already_closed_series_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $series = InvoiceSeries::factory()->closed()->create(['store_id' => $admin->store_id, 'fiscal_installation_id' => $installation->id]);
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->postJson("/api/v1/invoice-series/{$series->id}/close");

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'INVOICE_SERIES_ALREADY_CLOSED']]);
    }

    public function test_closing_a_series_from_another_store_is_not_found(): void
    {
        $admin = User::factory()->admin()->create();
        $foreignSeries = InvoiceSeries::factory()->create();
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->postJson("/api/v1/invoice-series/{$foreignSeries->id}/close");

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'INVOICE_SERIES_NOT_FOUND']]);
    }

    public function test_a_non_admin_cannot_create_an_invoice_series(): void
    {
        $cashier = User::factory()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $cashier->store_id]);
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/invoice-series', [
            'fiscal_installation_id' => $installation->id,
            'series_code' => 'MAIN',
            'starting_number' => 1,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
    }
}
