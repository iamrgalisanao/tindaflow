<?php

namespace Tests\Database\Concerns;

use App\Models\FiscalDay;
use App\Models\FiscalInstallation;
use App\Models\InventoryLocation;
use App\Models\InvoiceSeries;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A store with everything a real void or refund needs, built through the real endpoints wherever a
 * behaviour matters: a cashier ringing sales at terminal T1 (their shift open in fiscal day D1) and a
 * manager at a *different* terminal T2 with their own fiscal day and shift -- because approval and
 * immediate execution must be attributed to the executing terminal's context, never the sale's.
 */
trait BuildsSalesScenario
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

    /** A fresh session as $user, carrying an enrolled terminal's credential when one is given. */
    private function asUser(User $user, ?TestResponse $enrollment = null): static
    {
        $this->unencryptedCookies = [];
        $this->defaultCookies = [];

        $acting = $this->forwardSessionCookie($this->login($user));
        if ($enrollment === null) {
            return $acting;
        }
        $cookie = collect($enrollment->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal');

        return $acting->withUnencryptedCookie('tindaflow_terminal', $cookie?->getValue() ?? '')->withCredentials();
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    /**
     * @return array{
     *     storeId: string, t1: Terminal, t2: Terminal, cashier: User, manager: User, admin: User,
     *     cashierShift: Shift, managerShift: Shift, enroll1: TestResponse, enroll2: TestResponse,
     *     location: InventoryLocation, product: Product, product2: Product
     * }
     */
    private function world(): array
    {
        $cashierShift = Shift::factory()->create();
        $storeId = $cashierShift->fiscalDay->store_id;
        $t1 = $cashierShift->terminal;

        $fiscalInstallation = FiscalInstallation::factory()->create(['store_id' => $storeId]);
        InvoiceSeries::factory()->create(['store_id' => $storeId, 'fiscal_installation_id' => $fiscalInstallation->id]);
        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'terminal_id' => $t1->id,
            'fiscal_installation_id' => $fiscalInstallation->id, 'effective_from' => now()->subYear(),
            'effective_to' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tax_registrations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'registration_type' => 'VAT',
            'effective_from' => now()->subYear()->toDateString(), 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $location = InventoryLocation::factory()->create(['store_id' => $storeId, 'is_default' => true]);

        $manager = User::factory()->manager()->create(['store_id' => $storeId]);
        $admin = User::factory()->admin()->create(['store_id' => $storeId]);
        $t2 = Terminal::factory()->create(['store_id' => $storeId]);
        $fiscalDay2 = FiscalDay::factory()->create(['store_id' => $storeId, 'terminal_id' => $t2->id]);
        $managerShift = Shift::factory()->create(['terminal_id' => $t2->id, 'fiscal_day_id' => $fiscalDay2->id, 'cashier_id' => $manager->id]);

        $enroll = function (Terminal $terminal) use ($admin): TestResponse {
            $login = $this->login($admin);
            $token = $this->forwardSessionCookie($login)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);

            return $this->forwardSessionCookie($login)->postJson('/api/v1/terminal/enroll', ['token' => $token->json('token')]);
        };

        return [
            'storeId' => $storeId,
            't1' => $t1,
            't2' => $t2,
            'cashier' => $cashierShift->cashier,
            'manager' => $manager,
            'admin' => $admin,
            'cashierShift' => $cashierShift,
            'managerShift' => $managerShift,
            'enroll1' => $enroll($t1),
            'enroll2' => $enroll($t2),
            'location' => $location,
            'product' => Product::factory()->create(['store_id' => $storeId, 'selling_price' => '100.00', 'cost' => '60.00']),
            'product2' => Product::factory()->create(['store_id' => $storeId, 'selling_price' => '50.00', 'cost' => '30.00']),
        ];
    }

    /**
     * Rings a real sale as the cashier at T1. Defaults to two of the first product paid in cash.
     *
     * @param  array<string, mixed>  $w
     * @param  list<array{0: Product, 1: string}>|null  $lines
     * @return array<string, mixed> the SaleDetail body
     */
    private function ring(array $w, ?array $lines = null, string $method = 'CASH'): array
    {
        $lines ??= [[$w['product'], '2']];
        $items = array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines);
        $total = array_reduce($lines, fn (string $carry, array $line) => bcadd($carry, bcmul($line[0]->selling_price, $line[1], 2), 2), '0.00');

        $response = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => $items,
            'payments' => [['method' => $method, 'amount' => $total]],
        ], $this->key());
        $response->assertStatus(201);

        return $response->json();
    }
}
