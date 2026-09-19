<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * docs/06-backend/stage-23-production-readiness.md: the browser-facing hardening -- security headers, no CORS, an
 * expired session that reads as 401 rather than a server fault, request ids, and a ceiling on API traffic.
 * None of it needs a database (a guest never reaches one).
 */
class ProductionHardeningTest extends TestCase
{
    public function test_an_unauthenticated_request_is_a_401_whatever_it_accepts(): void
    {
        // A CSV export fetched after the session expired sends `Accept: text/csv`; it used to die with a 500
        // (RouteNotFoundException for a `login` route that does not exist).
        foreach (['text/csv', 'text/html', '*/*', 'application/json'] as $accept) {
            $response = $this->withHeaders(['Accept' => $accept])->get('/api/v1/auth/me');

            $response->assertStatus(401);
            $response->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
            $this->assertNotNull($response->json('error.request_id'));
        }

        $this->withHeaders(['Accept' => 'text/csv'])->get('/api/v1/products/export')->assertStatus(401);
        $this->withHeaders(['Accept' => 'text/csv'])->get('/api/v1/electronic-journal-entries')->assertStatus(401);
    }

    public function test_every_response_carries_the_security_headers(): void
    {
        foreach (['/', '/up', '/api/v1/auth/me'] as $path) {
            $response = $this->get($path);

            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'DENY');
            $response->assertHeader('Referrer-Policy', 'same-origin');
            $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
            $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
        }
    }

    public function test_the_content_security_policy_locks_scripts_frames_and_origins_but_allows_the_invoice_frames_inline_style(): void
    {
        $policy = $this->get('/')->headers->get('Content-Security-Policy');

        foreach (["default-src 'self'", "script-src 'self'", "frame-ancestors 'none'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "connect-src 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertDoesNotMatchRegularExpression('/script-src[^;]*unsafe-inline/', $policy);
    }

    public function test_strict_transport_security_is_sent_only_over_https(): void
    {
        $this->assertNull($this->get('/')->headers->get('Strict-Transport-Security'));

        $secure = $this->withServerVariables(['HTTPS' => 'on'])->get('https://localhost/');
        $this->assertSame('max-age=31536000', $secure->headers->get('Strict-Transport-Security'));
    }

    public function test_no_cross_origin_access_is_granted(): void
    {
        $simple = $this->withHeaders(['Origin' => 'https://evil.example'])->get('/api/v1/auth/me');
        $this->assertNull($simple->headers->get('Access-Control-Allow-Origin'));

        $preflight = $this->withHeaders([
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ])->call('OPTIONS', '/api/v1/auth/login');
        $this->assertNull($preflight->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($preflight->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_a_well_formed_inbound_request_id_is_echoed_and_anything_else_is_replaced(): void
    {
        $good = $this->withHeaders(['X-Request-ID' => 'req-2026.03.10:abc_123'])->get('/api/v1/auth/me');
        $good->assertHeader('X-Request-ID', 'req-2026.03.10:abc_123');
        $good->assertJsonPath('error.request_id', 'req-2026.03.10:abc_123');

        foreach ([str_repeat('a', 129), 'short', 'has spaces in it', "line\tbreak-ish", '<script>alert(1)</script>'] as $hostile) {
            $response = $this->withHeaders(['X-Request-ID' => $hostile])->get('/api/v1/auth/me');

            $this->assertNotSame($hostile, $response->headers->get('X-Request-ID'));
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->headers->get('X-Request-ID'));
        }
    }
}
