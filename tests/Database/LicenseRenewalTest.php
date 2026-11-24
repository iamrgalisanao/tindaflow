<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Store;
use App\Services\License\InstallationIdentity;
use App\Services\License\Renewal\Rfc9421Signer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesHostHardware;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/**
 * ADR-018 decisions 1, 5 and 7: the renewal client. It sends a signed request, applies only what passes every check, never lets an
 * unsigned answer change anything, reuses the request id after a lost answer, fails over to the standby, and does nothing where
 * there is nothing to renew. The control plane is faked with Http::fake; signatures are made with the TEST-ONLY key.
 */
class LicenseRenewalTest extends PostgresSchemaTestCase
{
    use FakesHostHardware;
    use SignsTestCertificates;

    private const STORE = '0b8f6f0e-2f0a-4d27-9a3e-0c1b6a5d4e11';

    private const PRIMARY = 'https://cp.example.ph';

    private const STANDBY = 'https://cp2.example.ph';

    private string $identityFile;

    /** @var array{installation: string, public_key: string, secret_key: string} */
    private array $identity;

    private string $currentToken;

    private bool $down = false;

    /** @var array{0: array<string, mixed>, 1: int} */
    private array $answer = [[], 200];

    protected function setUp(): void
    {
        parent::setUp();

        $this->identityFile = sys_get_temp_dir().'/tindaflow-test-identity-'.uniqid().'.json';
        config(['tindaflow.license.installation_file' => $this->identityFile, 'tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()]]);
        $this->identity = app(InstallationIdentity::class)->ensure();
        $this->fakeHostHardware();

        (new Store)->forceFill(['id' => self::STORE, 'name' => 'TSC Canteen'])->save();
        $this->storeCertificate(5);
    }

    protected function tearDown(): void
    {
        @unlink($this->identityFile);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function certificatePayload(int $seq, array $overrides = []): array
    {
        $now = CarbonImmutable::now();

        return $overrides + [
            'store' => self::STORE, 'installation' => $this->identity['installation'], 'deployment' => '6A5D4E11', 'seq' => $seq,
            'prev' => $seq === 1 ? null : hash('sha256', 'previous'),
            'iat' => $now->format('Y-m-d\TH:i:sP'), 'exp' => $now->addDays(30)->format('Y-m-d\TH:i:sP'),
            'jti' => str_pad(dechex($seq), 32, '0', STR_PAD_LEFT),
        ];
    }

    private function storeCertificate(int $seq): void
    {
        $this->currentToken = self::sign(self::payload($this->certificatePayload($seq, [
            'iat' => CarbonImmutable::now()->subDays(2)->format('Y-m-d\TH:i:sP'), 'exp' => CarbonImmutable::now()->addDays(28)->format('Y-m-d\TH:i:sP'),
        ])));

        DB::table('license_state')->updateOrInsert(['id' => 1], [
            'certificate' => $this->currentToken, 'certificate_seq' => $seq, 'revoked' => false, 'control_plane' => json_encode([self::PRIMARY, self::STANDBY]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function successor(int $seq, array $overrides = [], ?string $label = null): string
    {
        $payload = self::payload($this->certificatePayload($seq, ['prev' => hash('sha256', $this->currentToken)] + $overrides));

        return $label === null ? self::sign($payload) : self::sign($payload, $label);
    }

    private function revocation(array $overrides = [], ?string $label = null): string
    {
        $payload = $overrides + [
            'v' => 1, 'typ' => 'tindaflow.revocation', 'kid' => 'test-2026-01', 'store' => self::STORE,
            'installation' => $this->identity['installation'], 'iat' => CarbonImmutable::now()->format('Y-m-d\TH:i:sP'),
        ];

        return $label === null ? self::sign($payload) : self::sign($payload, $label);
    }

    /** @param  array<string, mixed>  $renewals  the answer to POST /v1/renewals on the primary */
    private function fakeControlPlane(array $renewals, int $status = 200): void
    {
        $this->down = false;
        $this->answer = [$renewals, $status];

        // One closure for the whole test: it reads the properties when a request arrives, so a later call changes the answer.
        Http::fake(function (Request $request) {
            if ($this->down) {
                return Http::response('', 503);
            }

            return match (true) {
                str_ends_with($request->url(), '/v1/nonce') => Http::response(['nonce' => 'nonce-0123456789abcdef']),
                default => Http::response($this->answer[0], $this->answer[1]),
            };
        });
    }

    private function state(): object
    {
        return DB::table('license_state')->where('id', 1)->first();
    }

    private function renew(): string
    {
        Artisan::call('tindaflow:license-renew');

        return Artisan::output();
    }

    public function test_a_successor_replaces_the_certificate_and_moves_the_mark_to_the_providers_time(): void
    {
        $token = $this->successor(6);
        $this->fakeControlPlane(['v' => 1, 'status' => 'RENEWED', 'certificate' => $token]);

        $this->assertSame(0, Artisan::call('tindaflow:license-renew'));

        $state = $this->state();
        $this->assertSame($token, $state->certificate);
        $this->assertSame(6, (int) $state->certificate_seq);
        $this->assertNull($state->pending_request_id);
        $this->assertNull($state->last_renewal_error);
        $this->assertNotNull($state->last_renewed_at);
        $this->assertNotNull($state->trusted_time_hwm);

        $event = AuditEvent::where('event_type', 'LICENSE_RENEWED')->sole();
        $this->assertSame(6, $event->after_metadata['seq']);
        $this->assertSame(5, $event->after_metadata['previous_seq']);
        $this->assertFalse($event->after_metadata['chain_gap']);
        $this->assertStringNotContainsString($token, json_encode($event->toArray()), 'no certificate in the audit log');
    }

    public function test_the_request_is_signed_per_rfc_9421_and_carries_the_certificate_and_a_request_id(): void
    {
        $this->fakeControlPlane(['v' => 1, 'status' => 'RENEWED', 'certificate' => $this->successor(6)]);

        $this->renew();

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::PRIMARY.'/v1/renewals') {
                return false;
            }

            $body = json_decode($request->body(), true);
            $params = '("@method" "@target-uri" "content-digest" "content-type");keyid="'.$this->identity['installation'].'";tag="tindaflow-renewal";nonce="nonce-0123456789abcdef"';
            $base = Rfc9421Signer::signatureBase('POST', $request->url(), $request->header('Content-Digest')[0], 'application/json', $params);
            preg_match('/\Asig1=:(.+):\z/', $request->header('Signature')[0], $match);

            return $body['certificate'] === $this->currentToken
                && $body['installation'] === $this->identity['installation']
                && preg_match('/\A[0-9a-f-]{36}\z/', $body['request_id']) === 1
                && $request->header('Content-Digest')[0] === Rfc9421Signer::contentDigest($request->body())
                && $request->header('Signature-Input')[0] === 'sig1='.$params
                && sodium_crypto_sign_verify_detached(base64_decode($match[1], true), $base, base64_decode($this->identity['public_key'], true));
        });
    }

    public function test_the_request_carries_the_hardware_hashes_from_the_host_helper_file(): void
    {
        $this->fakeControlPlane(['v' => 1, 'status' => 'UNCHANGED', 'certificate' => $this->currentToken]);

        $this->renew();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/renewals') && json_decode($request->body(), true)['fingerprint'] === self::testFingerprint());
    }

    public function test_a_missing_stale_or_invalid_hardware_file_stops_the_renewal_before_anything_is_sent(): void
    {
        Http::fake();

        config(['tindaflow.license.hardware_file' => sys_get_temp_dir().'/tindaflow-no-such-hardware-file.json']);
        $this->assertSame(1, Artisan::call('tindaflow:license-renew'));
        $this->assertStringContainsString('HOST_HELPER_STALE: the hardware file is missing', (string) $this->state()->last_renewal_error);

        $this->fakeHostHardware(generatedAt: CarbonImmutable::now()->subHours(27)->format('Y-m-d\TH:i:sP'));
        $this->assertSame(1, Artisan::call('tindaflow:license-renew'));
        $this->assertStringContainsString('stale', (string) $this->state()->last_renewal_error);

        Http::assertNothingSent();
        $this->assertNull($this->state()->pending_request_id, 'no request was made, so none was saved');
    }

    public function test_the_same_certificate_back_is_reported_unchanged(): void
    {
        $this->fakeControlPlane(['v' => 1, 'status' => 'UNCHANGED', 'certificate' => $this->currentToken]);

        $output = $this->renew();

        $this->assertStringContainsString('still current', $output);
        $this->assertSame($this->currentToken, $this->state()->certificate);
        $this->assertSame(0, AuditEvent::where('event_type', 'LICENSE_RENEWED')->count());
    }

    public function test_a_lost_answer_is_retried_with_the_same_request_id(): void
    {
        $this->fakeControlPlane([]);
        $this->down = true;
        $this->assertSame(1, Artisan::call('tindaflow:license-renew'));
        $firstId = $this->state()->pending_request_id;
        $this->assertNotNull($firstId, 'saved before the request was sent');
        $this->assertStringContainsString('could not be reached', (string) $this->state()->last_renewal_error);

        $this->fakeControlPlane(['v' => 1, 'status' => 'RENEWED', 'certificate' => $this->successor(6)]);
        $this->assertSame(0, Artisan::call('tindaflow:license-renew'));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/renewals') && json_decode($request->body(), true)['request_id'] === $firstId);
        $this->assertNull($this->state()->pending_request_id);
    }

    public function test_when_the_primary_is_down_the_standby_answers(): void
    {
        Http::fake([
            self::PRIMARY.'/v1/nonce' => Http::response('', 503),
            self::STANDBY.'/v1/nonce' => Http::response(['nonce' => 'nonce-fedcba9876543210']),
            self::STANDBY.'/v1/renewals' => Http::response(['v' => 1, 'status' => 'RENEWED', 'certificate' => $this->successor(6)]),
        ]);

        $this->assertSame(0, Artisan::call('tindaflow:license-renew'));
        $this->assertSame(6, (int) $this->state()->certificate_seq);
    }

    public function test_a_certificate_that_goes_backwards_is_refused_and_nothing_changes(): void
    {
        $this->fakeControlPlane(['v' => 1, 'status' => 'RENEWED', 'certificate' => self::sign(self::payload($this->certificatePayload(4)))]);

        $this->assertSame(1, Artisan::call('tindaflow:license-renew'));

        $this->assertSame($this->currentToken, $this->state()->certificate);
        $this->assertStringContainsString('seq went backwards', (string) $this->state()->last_renewal_error);
    }

    public function test_a_certificate_for_another_installation_is_refused(): void
    {
        $this->fakeControlPlane(['v' => 1, 'status' => 'RENEWED', 'certificate' => $this->successor(6, ['installation' => '11111111-2222-4333-8444-555555555555'])]);

        $this->assertSame(1, Artisan::call('tindaflow:license-renew'));
        $this->assertSame($this->currentToken, $this->state()->certificate);
    }

    public function test_a_certificate_signed_by_another_key_is_refused(): void
    {
        $this->fakeControlPlane(['v' => 1, 'status' => 'RENEWED', 'certificate' => $this->successor(6, [], 'someone-else')]);

        $this->assertSame(1, Artisan::call('tindaflow:license-renew'));
        $this->assertSame($this->currentToken, $this->state()->certificate);
        $this->assertStringContainsString('BAD_SIGNATURE', (string) $this->state()->last_renewal_error);
    }

    public function test_an_unchanged_answer_that_carries_a_different_certificate_is_refused(): void
    {
        $this->fakeControlPlane(['v' => 1, 'status' => 'UNCHANGED', 'certificate' => $this->successor(6)]);

        $this->assertSame(1, Artisan::call('tindaflow:license-renew'));
        $this->assertSame($this->currentToken, $this->state()->certificate);
    }

    public function test_a_fork_report_changes_nothing_and_is_recorded(): void
    {
        $this->fakeControlPlane(['error' => 'FORKED'], 409);

        $this->assertSame(1, Artisan::call('tindaflow:license-renew'));

        $state = $this->state();
        $this->assertFalse((bool) $state->revoked);
        $this->assertFalse((bool) $state->chain_invalid);
        $this->assertSame($this->currentToken, $state->certificate);
        $this->assertStringContainsString('forked', (string) $state->last_renewal_error);
    }

    public function test_a_signed_revocation_locks_the_installation(): void
    {
        $this->fakeControlPlane(['v' => 1, 'status' => 'REVOKED', 'revocation' => $this->revocation()]);

        $this->assertSame(0, Artisan::call('tindaflow:license-renew'));

        $this->assertTrue((bool) $this->state()->revoked);
        $this->assertSame(1, AuditEvent::where('event_type', 'LICENSE_REVOKED')->count());
    }

    public function test_an_unsigned_or_misaddressed_revocation_changes_nothing(): void
    {
        foreach ([
            ['v' => 1, 'status' => 'REVOKED'],
            ['v' => 1, 'status' => 'REVOKED', 'revocation' => $this->revocation([], 'someone-else')],
            ['v' => 1, 'status' => 'REVOKED', 'revocation' => $this->revocation(['installation' => '11111111-2222-4333-8444-555555555555'])],
        ] as $answer) {
            $this->fakeControlPlane($answer);

            $this->assertSame(1, Artisan::call('tindaflow:license-renew'));
            $this->assertFalse((bool) $this->state()->revoked);
        }
    }

    public function test_the_mark_is_rebaselined_back_to_the_providers_time_and_the_audit_says_so(): void
    {
        DB::table('license_state')->where('id', 1)->update(['trusted_time_hwm' => CarbonImmutable::now()->addDays(3)->format('Y-m-d\TH:i:sP')]);
        $this->fakeControlPlane(['v' => 1, 'status' => 'RENEWED', 'certificate' => $this->successor(6)]);

        $this->assertSame(0, Artisan::call('tindaflow:license-renew'));

        $this->assertLessThan(60, abs(CarbonImmutable::parse($this->state()->trusted_time_hwm)->diffInSeconds(CarbonImmutable::now())));
        $this->assertNotNull(AuditEvent::where('event_type', 'LICENSE_RENEWED')->sole()->after_metadata['time_rebaselined_from']);
    }

    public function test_a_gap_in_the_chain_is_accepted_but_audited(): void
    {
        $this->fakeControlPlane(['v' => 1, 'status' => 'RENEWED', 'certificate' => $this->successor(9)]);

        $this->assertSame(0, Artisan::call('tindaflow:license-renew'));

        $this->assertSame(9, (int) $this->state()->certificate_seq);
        $this->assertTrue(AuditEvent::where('event_type', 'LICENSE_RENEWED')->sole()->after_metadata['chain_gap']);
    }

    public function test_it_does_nothing_where_there_is_nothing_to_renew(): void
    {
        Http::fake();

        config(['tindaflow.license.vendor_keys' => []]);
        $this->assertStringContainsString('no vendor key', $this->renew());

        config(['tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()]]);
        DB::table('license_state')->where('id', 1)->update(['revoked' => true]);
        $this->assertStringContainsString('revoked', $this->renew());

        DB::table('license_state')->where('id', 1)->update(['revoked' => false, 'certificate' => null]);
        $this->assertStringContainsString('not activated', $this->renew());

        Http::assertNothingSent();
    }

    public function test_a_renewal_already_running_is_not_started_twice(): void
    {
        Http::fake();
        $lock = Cache::lock('license-renewal', 60);
        $this->assertTrue($lock->get());

        $this->assertStringContainsString('already running', $this->renew());

        Http::assertNothingSent();
        $lock->release();
    }

    public function test_an_identity_file_that_does_not_match_the_certificate_sends_nothing(): void
    {
        Http::fake();
        file_put_contents($this->identityFile, json_encode(['installation' => '11111111-2222-4333-8444-555555555555'] + $this->identity));

        $this->assertSame(1, Artisan::call('tindaflow:license-renew'));
        Http::assertNothingSent();
    }

    public function test_the_renewal_is_scheduled_hourly_with_a_random_delay_and_without_overlap(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'tindaflow:license-renew'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertStringContainsString('--jitter=600', (string) $event->command);
        $this->assertTrue($event->withoutOverlapping);
    }
}
