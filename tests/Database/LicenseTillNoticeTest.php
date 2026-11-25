<?php

namespace Tests\Database;

use App\Models\FiscalDay;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesLicensedInstallation;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/**
 * ADR-019, the till warning: a cashier, who cannot see the License screen, is told before a new shift is refused, and, once it
 * is, until when the open shift can sell. The till is told nothing unless something will really be refused.
 */
class LicenseTillNoticeTest extends PostgresSchemaTestCase
{
    use FakesLicensedInstallation;
    use SignsTestCertificates;

    private Terminal $terminal;

    private User $cashier;

    private TestResponse $session;

    private TestResponse $enrolled;

    protected function setUp(): void
    {
        parent::setUp();

        // Enrolled before the build is made license-managed, so that setting up needs no certificate.
        $this->fakeLicensedInstallation();
        $store = Store::findOrFail(self::licensedStoreId());
        $this->terminal = Terminal::factory()->unenrolled()->create(['store_id' => $store->id]);
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $this->cashier = User::factory()->create(['store_id' => $store->id]);

        $adminSession = $this->login($admin);
        $token = $this->withSession_($adminSession)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $this->terminal->id])->json('token');
        $this->enrolled = $this->withSession_($adminSession)->postJson('/api/v1/terminal/enroll', ['token' => $token])->assertOk();
        $this->session = $this->login($this->cashier);
    }

    private function login(User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function withSession_(TestResponse $login): static
    {
        $this->app['auth']->forgetGuards();
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), (string) $cookie?->getValue())->withCredentials();
    }

    private function asCashierAtTheTill(): static
    {
        $cookie = collect($this->enrolled->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal');

        return $this->withSession_($this->session)->withUnencryptedCookie('tindaflow_terminal', (string) $cookie?->getValue());
    }

    private function notice(): ?array
    {
        $response = $this->asCashierAtTheTill()->getJson('/api/v1/license/till-notice')->assertOk();
        $this->assertSame(['notice'], array_keys($response->json()));

        return $response->json('notice');
    }

    /** @param  array<string, mixed>  $row */
    private function license(int $daysToExpiry, string $mode = 'enforce', array $row = []): void
    {
        config(['tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()]]);
        $now = CarbonImmutable::now();
        $token = self::sign(self::payload([
            'mode' => $mode, 'iat' => $now->subDays(15)->format('Y-m-d\TH:i:sP'), 'exp' => $now->addDays($daysToExpiry)->format('Y-m-d\TH:i:sP'),
        ]));

        DB::table('license_state')->updateOrInsert(['id' => 1], $row + ['certificate' => $token, 'revoked' => false, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function openShiftSince(CarbonImmutable $openedAt): void
    {
        $day = FiscalDay::factory()->create(['store_id' => $this->terminal->store_id, 'terminal_id' => $this->terminal->id]);
        Shift::factory()->create(['terminal_id' => $this->terminal->id, 'fiscal_day_id' => $day->id, 'cashier_id' => $this->cashier->id, 'status' => 'OPEN', 'opened_at' => $openedAt]);
    }

    public function test_a_build_with_no_vendor_key_says_nothing_and_touches_no_license_table(): void
    {
        $this->assertNull($this->notice());
        $this->assertSame(0, DB::table('license_state')->count());
    }

    public function test_it_says_nothing_while_the_license_has_more_than_a_week_to_run(): void
    {
        $this->license(8);

        $this->assertNull($this->notice());
    }

    public function test_in_the_last_week_it_gives_the_date_the_license_runs_out(): void
    {
        $this->license(3);

        $notice = $this->notice();

        $this->assertSame('EXPIRING', $notice['kind']);
        $this->assertEqualsWithDelta(CarbonImmutable::now()->addDays(3)->getTimestamp(), CarbonImmutable::parse($notice['valid_until'])->getTimestamp(), 60);
        $this->assertNull($notice['reason']);
        $this->assertFalse($notice['sales_stopped']);
    }

    public function test_an_offline_extension_moves_the_date_it_gives(): void
    {
        $until = CarbonImmutable::now()->addDays(5)->startOfSecond();
        $this->license(1, row: ['extended_until' => $until->format('Y-m-d\TH:i:sP'), 'extension_base_seq' => 42]);

        $this->assertSame($until->getTimestamp(), CarbonImmutable::parse($this->notice()['valid_until'])->getTimestamp());
    }

    public function test_once_expired_with_no_shift_open_it_says_a_new_one_cannot_be_opened(): void
    {
        $this->license(-1);

        $this->assertSame(
            ['kind' => 'NEW_SHIFT_REFUSED', 'valid_until' => null, 'reason' => 'EXPIRED', 'sales_stop_at' => null, 'sales_stopped' => false],
            $this->notice(),
        );
    }

    public function test_with_a_shift_open_it_says_until_when_that_shift_can_sell_and_then_that_it_cannot(): void
    {
        $this->license(-1);
        $openedAt = CarbonImmutable::now()->subHours(10)->startOfSecond();
        $this->openShiftSince($openedAt);

        $notice = $this->notice();
        $this->assertSame($openedAt->addHours(18)->getTimestamp(), CarbonImmutable::parse($notice['sales_stop_at'])->getTimestamp());
        $this->assertFalse($notice['sales_stopped']);

        $this->travel(9)->hours();
        $this->assertTrue($this->notice()['sales_stopped']);
    }

    public function test_a_locked_license_gives_its_reason(): void
    {
        $this->license(10, row: ['revoked' => true]);

        $this->assertSame('REVOKED', $this->notice()['reason']);
    }

    public function test_report_mode_says_nothing_whatever_the_state(): void
    {
        foreach ([3, -1] as $days) {
            $this->license($days, mode: 'report');

            $this->assertNull($this->notice(), "{$days} days");
        }
    }

    public function test_it_carries_no_license_details(): void
    {
        $this->license(3);

        $this->assertSame(['kind', 'valid_until', 'reason', 'sales_stop_at', 'sales_stopped'], array_keys($this->notice()));
    }

    public function test_it_needs_a_signed_in_user_on_an_enrolled_till(): void
    {
        $this->license(3);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        [$this->defaultCookies, $this->unencryptedCookies] = [[], []];
        $this->getJson('/api/v1/license/till-notice')->assertUnauthorized();

        $this->withSession_($this->session)->getJson('/api/v1/license/till-notice')->assertForbidden();
    }
}
