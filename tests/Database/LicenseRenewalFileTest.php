<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Services\License\Contract\KeyRing;
use App\Services\License\Contract\SignedEnvelope;
use App\Services\License\InstallationIdentity;
use App\Services\License\LicenseGate;
use App\Services\License\LicenseState;
use App\Services\License\RefusalReason;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesHostHardware;
use Tests\Concerns\FakesLicensedInstallation;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/**
 * ADR-019 slice 3: renewal by file, for a shop with no internet, and the clock lock an online renewal must be able to clear.
 * The reply file is only as good as the vendor signature inside it; nothing here goes to the network. TEST-ONLY keys.
 */
class LicenseRenewalFileTest extends PostgresSchemaTestCase
{
    use FakesHostHardware;
    use FakesLicensedInstallation;
    use SignsTestCertificates;

    private string $held = '';

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()]]);
        $this->fakeLicensedInstallation();
        $this->fakeHostHardware();

        // An expired license: certificate 5 ran out two days ago.
        $this->held = $this->certificate(5, issuedDaysAgo: 33, expiresInDays: -2);
        DB::table('license_state')->updateOrInsert(['id' => 1], [
            'certificate' => $this->held, 'certificate_seq' => 5, 'revoked' => false, 'control_plane' => json_encode(['https://cp.example.ph']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    private function certificate(int $seq, float $issuedDaysAgo = 0, int $expiresInDays = 30, array $overrides = []): string
    {
        $now = CarbonImmutable::now();

        return self::sign(self::payload($overrides + [
            'seq' => $seq, 'prev' => hash('sha256', $this->held),
            'iat' => $now->subMinutes((int) round($issuedDaysAgo * 1440))->format('Y-m-d\TH:i:sP'),
            'exp' => $now->addDays($expiresInDays)->format('Y-m-d\TH:i:sP'),
            'jti' => str_pad(dechex($seq), 32, '0', STR_PAD_LEFT),
        ]));
    }

    private function file(string $contents = ''): string
    {
        $this->files[] = $path = sys_get_temp_dir().'/tindaflow-test-renewal-'.uniqid('', true);
        if ($contents !== '') {
            file_put_contents($path, $contents);
        }

        return $path;
    }

    /** @param  array<string, mixed>  $reply */
    private function applyReply(array $reply): int
    {
        return Artisan::call('tindaflow:license-renew', ['--apply' => $this->file((string) json_encode($reply))]);
    }

    private function renewedReply(string $certificate): array
    {
        return ['v' => 1, 'status' => 'RENEWED', 'certificate' => $certificate];
    }

    private function state(): object
    {
        return DB::table('license_state')->where('id', 1)->first();
    }

    private function licenseState(): LicenseState
    {
        return app(LicenseGate::class)->check()->verdict->state;
    }

    /** @return array<string, mixed> the signed document in the request file */
    private function requestDocument(): array
    {
        $path = $this->file();
        $this->assertSame(0, Artisan::call('tindaflow:license-renew', ['--request' => true, '--out' => $path]), Artisan::output());
        $identity = app(InstallationIdentity::class)->load();

        return SignedEnvelope::open(trim((string) file_get_contents($path)), new KeyRing([$identity['installation'] => $identity['public_key']]));
    }

    public function test_the_request_file_is_signed_by_this_computer_and_says_what_the_online_request_says(): void
    {
        Http::fake();

        $document = $this->requestDocument();

        $this->assertSame('tindaflow.renewal-request', $document['typ']);
        $this->assertSame(1, $document['v']);
        $this->assertSame(self::licensedInstallationId(), $document['kid']);
        $this->assertSame(self::licensedInstallationId(), $document['installation']);
        $this->assertSame($this->held, $document['certificate']);
        $this->assertEquals(self::testFingerprint(), $document['fingerprint']);
        $this->assertSame($document['request_id'], $this->state()->pending_request_id, 'saved, so a retry online or by file is the same request');
        $this->assertArrayNotHasKey('clock_locked', $document);
        $this->assertSame($document['request_id'], $this->requestDocument()['request_id']);
        Http::assertNothingSent();
    }

    public function test_the_request_file_never_holds_the_private_key(): void
    {
        $path = $this->file();
        Artisan::call('tindaflow:license-renew', ['--request' => true, '--out' => $path]);

        $identity = app(InstallationIdentity::class)->load();
        $written = (string) file_get_contents($path);
        $this->assertStringNotContainsString($identity['secret_key'], $written);
        $this->assertStringNotContainsString($identity['secret_key'], (string) base64_decode(strtr(explode('.', trim($written))[0], '-_', '+/')));
    }

    public function test_no_request_is_made_where_there_is_nothing_to_renew_or_no_current_hardware_file(): void
    {
        $path = $this->file();

        config(['tindaflow.license.hardware_file' => sys_get_temp_dir().'/tindaflow-no-such-hardware-file.json']);
        $this->assertSame(1, Artisan::call('tindaflow:license-renew', ['--request' => true, '--out' => $path]));
        $this->assertStringContainsString('HOST_HELPER_STALE', Artisan::output());

        $this->fakeHostHardware();
        DB::table('license_state')->where('id', 1)->update(['revoked' => true]);
        $this->assertSame(1, Artisan::call('tindaflow:license-renew', ['--request' => true, '--out' => $path]));

        DB::table('license_state')->where('id', 1)->update(['revoked' => false, 'certificate' => null]);
        $this->assertSame(1, Artisan::call('tindaflow:license-renew', ['--request' => true, '--out' => $path]));

        config(['tindaflow.license.vendor_keys' => []]);
        $this->assertSame(1, Artisan::call('tindaflow:license-renew', ['--request' => true, '--out' => $path]));

        $this->assertFileDoesNotExist($path);
    }

    public function test_request_and_apply_cannot_be_given_together(): void
    {
        $this->assertSame(1, Artisan::call('tindaflow:license-renew', ['--request' => true, '--apply' => $this->file('{}')]));
        $this->assertStringContainsString('not both', Artisan::output());
    }

    public function test_applying_the_reply_brings_an_expired_shop_back(): void
    {
        Http::fake();
        $this->assertSame(LicenseState::Expired, $this->licenseState());
        DB::table('license_state')->where('id', 1)->update(['extended_until' => CarbonImmutable::now()->subDay()->format('Y-m-d\TH:i:sP'), 'extension_base_seq' => 5, 'extensions_in_a_row' => 2, 'last_code_ordinal' => 7, 'pending_request_id' => '00000000-0000-4000-8000-000000000000']);
        $next = $this->certificate(6);

        $this->assertSame(0, $this->applyReply($this->renewedReply($next)), Artisan::output());

        $this->assertSame(LicenseState::Active, $this->licenseState());
        $state = $this->state();
        $this->assertSame($next, $state->certificate);
        $this->assertSame(0, (int) $state->extensions_in_a_row);
        $this->assertSame(7, (int) $state->last_code_ordinal);
        $this->assertNull($state->pending_request_id);
        $this->assertSame('file', AuditEvent::where('event_type', 'LICENSE_RENEWED')->sole()->after_metadata['via']);
        Http::assertNothingSent();
    }

    /** A reply can be kept and applied weeks later, so it must not set the recorded time back and give that time again. */
    public function test_a_reply_applied_late_never_moves_the_recorded_time_back(): void
    {
        $mark = CarbonImmutable::now();
        DB::table('license_state')->where('id', 1)->update(['trusted_time_hwm' => $mark->format('Y-m-d\TH:i:sP')]);

        $this->assertSame(0, $this->applyReply($this->renewedReply($this->certificate(6, issuedDaysAgo: 20, expiresInDays: 10))), Artisan::output());

        $this->assertSame($mark->getTimestamp(), CarbonImmutable::parse($this->state()->trusted_time_hwm)->getTimestamp());
    }

    public function test_a_reply_applied_late_with_the_clock_set_back_stays_locked(): void
    {
        $mark = CarbonImmutable::now()->addDays(20);
        DB::table('license_state')->where('id', 1)->update(['trusted_time_hwm' => $mark->format('Y-m-d\TH:i:sP')]);

        $this->applyReply($this->renewedReply($this->certificate(6)));

        $this->assertSame(RefusalReason::ClockRollback, app(LicenseGate::class)->check()->verdict->shiftOpenRefusal());
        $this->assertSame($mark->getTimestamp(), CarbonImmutable::parse($this->state()->trusted_time_hwm)->getTimestamp());
    }

    public function test_a_reply_moves_the_recorded_time_forward(): void
    {
        DB::table('license_state')->where('id', 1)->update(['trusted_time_hwm' => CarbonImmutable::now()->subDays(5)->format('Y-m-d\TH:i:sP')]);
        $next = $this->certificate(6);

        $this->applyReply($this->renewedReply($next));

        $this->assertLessThan(60, abs(CarbonImmutable::parse($this->state()->trusted_time_hwm)->diffInSeconds(CarbonImmutable::now())));
    }

    public function test_every_reply_that_should_not_be_applied_changes_nothing(): void
    {
        $now = CarbonImmutable::now();
        $cases = [
            'older than the one held' => [$this->renewedReply($this->certificate(4)), 'older'],
            'same number, another certificate' => [$this->renewedReply($this->certificate(5, overrides: ['jti' => str_repeat('f', 32)])), 'forked'],
            'for another computer' => [$this->renewedReply($this->certificate(6, overrides: ['installation' => '11111111-2222-4333-8444-555555555555'])), 'different installation'],
            'already expired' => [$this->renewedReply($this->certificate(6, issuedDaysAgo: 40, expiresInDays: -1)), 'already expired'],
            'signed by someone else' => [$this->renewedReply(self::sign(self::payload(['seq' => 6]), 'someone-else')), 'refused'],
            'dated more than an hour ahead of this clock' => [$this->renewedReply($this->certificate(6, overrides: ['iat' => $now->addHours(3)->format('Y-m-d\TH:i:sP')])), 'clock'],
            'an unsigned revocation' => [['v' => 1, 'status' => 'REVOKED'], 'not signed'],
            'no certificate' => [['v' => 1, 'status' => 'RENEWED'], 'not a reply'],
            'another answer' => [['v' => 1, 'status' => 'FORKED'], 'not a reply'],
        ];
        $before = (array) $this->state();

        foreach ($cases as $label => [$reply, $why]) {
            $this->assertSame(1, $this->applyReply($reply), $label);
            $this->assertStringContainsString($why, Artisan::output(), $label);
        }
        $this->assertSame(1, Artisan::call('tindaflow:license-renew', ['--apply' => $this->file('not json')]));
        $this->assertSame(1, Artisan::call('tindaflow:license-renew', ['--apply' => sys_get_temp_dir().'/tindaflow-no-such-reply.json']));

        $after = (array) $this->state();
        unset($before['last_renewal_error'], $before['updated_at'], $after['last_renewal_error'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_reply_saved_by_a_windows_editor_with_a_byte_order_mark_is_read(): void
    {
        $reply = "\xEF\xBB\xBF".json_encode($this->renewedReply($this->certificate(6))).PHP_EOL;

        $this->assertSame(0, Artisan::call('tindaflow:license-renew', ['--apply' => $this->file($reply)]), Artisan::output());
        $this->assertSame(6, (int) $this->state()->certificate_seq);
    }

    public function test_a_signed_revocation_by_file_locks_the_shop(): void
    {
        $revocation = self::sign(['v' => 1, 'typ' => 'tindaflow.revocation', 'kid' => 'test-2026-01', 'store' => self::licensedStoreId(), 'installation' => self::licensedInstallationId(), 'iat' => CarbonImmutable::now()->format('Y-m-d\TH:i:sP')]);

        $this->assertSame(0, $this->applyReply(['v' => 1, 'status' => 'REVOKED', 'revocation' => $revocation]), Artisan::output());

        $this->assertSame(RefusalReason::Revoked, app(LicenseGate::class)->check()->verdict->shiftOpenRefusal());
        $this->assertSame(1, AuditEvent::where('event_type', 'LICENSE_REVOKED')->count());
    }

    public function test_an_online_request_from_a_computer_locked_by_its_clock_says_so_and_no_other_does(): void
    {
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/v1/nonce') ? Http::response(['nonce' => 'nonce-0123456789abcdef']) : Http::response('', 503));
        $sent = fn (): array => Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v1/renewals'))
            ->map(fn (array $pair) => json_decode($pair[0]->body(), true))->values()->all();

        Artisan::call('tindaflow:license-renew');
        $this->assertArrayNotHasKey('clock_locked', $sent()[0]);

        DB::table('license_state')->where('id', 1)->update(['trusted_time_hwm' => CarbonImmutable::now()->addDays(3)->format('Y-m-d\TH:i:sP')]);
        Artisan::call('tindaflow:license-renew');

        $all = $sent();
        $this->assertTrue(end($all)['clock_locked']);
    }

    public function test_the_request_file_of_a_computer_locked_by_its_clock_says_so(): void
    {
        DB::table('license_state')->where('id', 1)->update(['trusted_time_hwm' => CarbonImmutable::now()->addDays(3)->format('Y-m-d\TH:i:sP')]);

        $this->assertTrue($this->requestDocument()['clock_locked']);
    }

    public function test_an_unchanged_answer_that_leaves_the_clock_lock_says_what_to_do(): void
    {
        DB::table('license_state')->where('id', 1)->update(['certificate' => $current = $this->certificate(5), 'trusted_time_hwm' => CarbonImmutable::now()->addDays(3)->format('Y-m-d\TH:i:sP')]);
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/v1/nonce')
            ? Http::response(['nonce' => 'nonce-0123456789abcdef'])
            : Http::response(['v' => 1, 'status' => 'UNCHANGED', 'certificate' => $current]));

        Artisan::call('tindaflow:license-renew');
        $output = Artisan::output(); // reading it empties it

        $this->assertStringContainsString('still locked', $output);
        $this->assertStringContainsString('time-reset code', $output);
    }
}
