<?php

namespace Tests\Database;

use App\Models\User;

/** The ceiling on API traffic per signed-in user; the 429 is the catalogued RATE_LIMITED (with Retry-After). */
class ApiThrottleHttpTest extends PostgresSchemaTestCase
{
    private function signedIn(): static
    {
        $user = User::factory()->create();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    public function test_a_user_past_the_ceiling_is_rate_limited_with_the_catalogued_code(): void
    {
        config(['tindaflow.api_throttle.per_minute' => 3]);
        $client = $this->signedIn();

        foreach (range(1, 3) as $ignored) {
            $client->getJson('/api/v1/auth/me')->assertOk();
        }
        $limited = $client->getJson('/api/v1/auth/me');

        $limited->assertStatus(429);
        $limited->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertNotNull($limited->json('error.request_id'));
        $limited->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_default_ceiling_leaves_a_busy_till_untouched(): void
    {
        $this->assertGreaterThanOrEqual(300, config('tindaflow.api_throttle.per_minute'));

        $client = $this->signedIn();
        foreach (range(1, 40) as $ignored) {
            $client->getJson('/api/v1/auth/me')->assertOk();
        }
    }
}
