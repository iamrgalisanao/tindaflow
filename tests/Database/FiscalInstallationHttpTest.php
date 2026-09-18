<?php

namespace Tests\Database;

use App\Models\FiscalInstallation;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\TerminalFiscalInstallation;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * openapi.yaml FiscalInstallation tag -- List/Create (already-frozen
 * contract, implemented here for the first time) plus AssignTerminal (a
 * wholly new operation, store-setup pass 2026-09-18). AssignTerminal is
 * the operation that closes the exact gap a fresh store hit during POS
 * checkout verification: no HTTP path existed to create the
 * terminal_fiscal_installations row FiscalInstallationResolver reads.
 */
class FiscalInstallationHttpTest extends PostgresSchemaTestCase
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

    public function test_an_admin_can_create_a_fiscal_installation(): void
    {
        $admin = User::factory()->admin()->create();
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/fiscal-installations', [
            'deployment_model' => 'STANDALONE',
            'software_version' => '2.1.0',
            'machine_serial_number' => 'SN-001',
            'accreditation' => ['number' => 'ACC-123', 'date' => '2026-01-01'],
            'permit_to_use' => ['number' => 'PTU-456', 'min' => 'MIN-789', 'date' => '2026-01-01'],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'deployment_model' => 'STANDALONE',
            'software_version' => '2.1.0',
            'store_id' => $admin->store_id,
            'accreditation' => ['number' => 'ACC-123'],
            'permit_to_use' => ['number' => 'PTU-456', 'min' => 'MIN-789'],
            'terminals' => [],
        ]);
        $this->assertSame(1, FiscalInstallation::count());
    }

    public function test_a_non_admin_cannot_create_a_fiscal_installation(): void
    {
        $cashier = User::factory()->create();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/fiscal-installations', [
            'deployment_model' => 'STANDALONE',
            'software_version' => '1.0.0',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
    }

    public function test_list_only_returns_the_actors_own_store_installations(): void
    {
        $admin = User::factory()->admin()->create();
        $own = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $other = FiscalInstallation::factory()->create();
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->getJson('/api/v1/fiscal-installations');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($own->id));
        $this->assertFalse($ids->contains($other->id));
    }

    public function test_assigning_a_terminal_creates_the_mapping(): void
    {
        $admin = User::factory()->admin()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $terminal = Terminal::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)
            ->postJson("/api/v1/fiscal-installations/{$installation->id}/terminals", ['terminal_id' => $terminal->id]);

        $response->assertStatus(201);
        $response->assertJson([
            'terminal_id' => $terminal->id,
            'fiscal_installation_id' => $installation->id,
            'effective_to' => null,
        ]);
        $this->assertSame(1, TerminalFiscalInstallation::count());
    }

    public function test_reassigning_a_terminal_closes_the_prior_mapping(): void
    {
        $admin = User::factory()->admin()->create();
        $firstInstallation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $secondInstallation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $terminal = Terminal::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);

        $first = $this->forwardSessionCookie($login)
            ->postJson("/api/v1/fiscal-installations/{$firstInstallation->id}/terminals", ['terminal_id' => $terminal->id]);
        $first->assertStatus(201);

        $second = $this->forwardSessionCookie($login)
            ->postJson("/api/v1/fiscal-installations/{$secondInstallation->id}/terminals", ['terminal_id' => $terminal->id]);
        $second->assertStatus(201);

        $this->assertSame(2, TerminalFiscalInstallation::count());
        $this->assertNotNull(TerminalFiscalInstallation::find($first->json('id'))->effective_to, 'the prior mapping must be closed');
        $this->assertNull(TerminalFiscalInstallation::find($second->json('id'))->effective_to);
    }

    public function test_assigning_a_terminal_from_another_store_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $installation = FiscalInstallation::factory()->create(['store_id' => $admin->store_id]);
        $foreignTerminal = Terminal::factory()->create();
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)
            ->postJson("/api/v1/fiscal-installations/{$installation->id}/terminals", ['terminal_id' => $foreignTerminal->id]);

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'TERMINAL_NOT_FOUND']]);
    }

    public function test_assigning_to_a_fiscal_installation_from_another_store_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $foreignInstallation = FiscalInstallation::factory()->create();
        $terminal = Terminal::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)
            ->postJson("/api/v1/fiscal-installations/{$foreignInstallation->id}/terminals", ['terminal_id' => $terminal->id]);

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'FISCAL_INSTALLATION_NOT_FOUND']]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/fiscal-installations');

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }
}
