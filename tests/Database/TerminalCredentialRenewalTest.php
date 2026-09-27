<?php

namespace Tests\Database;

use App\Models\Terminal;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * The terminal credential cookie is renewed whenever the till is used, so it lasts from the till's last use and not from
 * enrollment (browsers cap a cookie's lifetime; without renewal every till enrolled on go-live day would stop on the same day
 * about 13 months later).
 */
class TerminalCredentialRenewalTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private function credentialCookie(TestResponse $response): ?Cookie
    {
        return collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'tindaflow_terminal');
    }

    public function test_a_till_that_is_used_gets_its_credential_cookie_renewed_with_the_same_value_and_a_fresh_lifetime(): void
    {
        $w = $this->world();
        $original = $this->credentialCookie($w['enroll1']);

        $response = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/shifts/current');

        $renewed = $this->credentialCookie($response);
        $this->assertNotNull($renewed, 'using the till must renew its credential cookie');
        // Both are encrypted with a fresh IV, so the strings differ; what matters is that the renewed one IS the same credential.
        $this->assertNotSame('', $renewed->getValue());
        $this->asUser($w['cashier'], $response)->getJson('/api/v1/shifts/current')->assertOk();
        $this->assertSame($original->isHttpOnly(), $renewed->isHttpOnly());
        $this->assertSame($original->getSameSite(), $renewed->getSameSite());
        $this->assertSame($original->getPath(), $renewed->getPath());
        $this->assertEqualsWithDelta(time() + 399 * 86400, $renewed->getExpiresTime(), 120, 'a fresh 399 days from now, not counted from enrollment');
    }

    public function test_the_lifetime_stays_within_what_browsers_will_honour(): void
    {
        $this->assertLessThanOrEqual(400 * 24 * 60, (int) config('tindaflow.terminal_credential.lifetime_minutes'), 'Chrome caps a cookie at 400 days');
    }

    public function test_a_revoked_terminal_is_refused_and_gets_no_renewed_cookie(): void
    {
        $w = $this->world();
        Terminal::where('id', $w['t1']->id)->update(['revoked_at' => now()]);

        $response = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/shifts/current');

        $response->assertStatus(403);
        $this->assertNull($this->credentialCookie($response), 'a credential that does not resolve is never renewed');
    }

    public function test_a_browser_with_no_credential_gets_none_set(): void
    {
        $w = $this->world();

        $response = $this->asUser($w['cashier'])->getJson('/api/v1/shifts/current');

        $response->assertStatus(403);
        $this->assertNull($this->credentialCookie($response));
    }
}
