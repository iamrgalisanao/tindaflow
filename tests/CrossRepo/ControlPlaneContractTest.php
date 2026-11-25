<?php

namespace Tests\CrossRepo;

use App\Models\AuditEvent;
use App\Models\Store;
use App\Services\License\Contract\Base64Url;
use App\Services\License\InstallationIdentity;
use App\Services\License\LicenseGate;
use App\Services\License\LicenseState;
use App\Services\License\RefusalReason;
use App\Support\DeploymentId;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use GuzzleHttp\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PDO;
use RuntimeException;
use Tests\Concerns\FakesHostHardware;
use Tests\Database\PostgresSchemaTestCase;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;
use Throwable;

/**
 * The real client against the real control plane (the private `tindaflow-control-plane` repository), over real HTTP, through
 * the real commands of both. Nothing of either side is faked except the two things no test can have: the vendor key (the
 * TEST-ONLY key of the golden vectors) and the Windows host helper's file.
 *
 * What it does NOT cover: TLS, DNS and the host helper itself. The client only accepts https control plane addresses, so its
 * requests are caught just before the network and replayed unchanged (method, path, headers, body) to the control plane served
 * on 127.0.0.1. The signed target address is the https one, which is what the control plane is configured with.
 *
 * Not part of the normal suites: it needs a checkout of the control plane with its dependencies installed. Run it with
 *   TINDAFLOW_CONTROL_PLANE_PATH=/path/to/tindaflow-control-plane php artisan test tests/CrossRepo
 * The control plane's CI runs it on every change there and once a day against this repository's main branch.
 */
class ControlPlaneContractTest extends PostgresSchemaTestCase
{
    use FakesHostHardware;
    use SignsTestCertificates;

    private const PRIMARY = 'https://licensing.example.ph';

    private const STANDBY = 'https://licensing-standby.example.ph';

    private string $controlPlane = '';

    private string $workDirectory = '';

    /** @var array<string, string> */
    private array $environment = [];

    /** @var resource|null */
    private $server = null;

    private string $deploymentCode = '';

    private string $storeId = '';

    private bool $loseRenewalAnswers = false;

    protected function setUp(): void
    {
        $path = (string) getenv('TINDAFLOW_CONTROL_PLANE_PATH');
        if ($path === '') {
            $this->markTestSkipped('Set TINDAFLOW_CONTROL_PLANE_PATH to a checkout of tindaflow-control-plane to run the cross-repository test.');
        }

        parent::setUp();

        // A path that was given but is wrong must fail, never skip: a CI job that silently skips this test would prove nothing.
        $this->assertFileExists($path.'/artisan', 'TINDAFLOW_CONTROL_PLANE_PATH is not a control plane checkout');
        $this->assertFileExists($path.'/vendor/autoload.php', 'install the control plane\'s dependencies first');
        $this->controlPlane = $path;

        $this->workDirectory = sys_get_temp_dir().'/tindaflow-cross-'.bin2hex(random_bytes(6));
        mkdir($this->workDirectory);
        touch($this->workDirectory.'/registry.sqlite');
        $this->environment = $this->controlPlaneEnvironment();

        config([
            'app.timezone' => 'Asia/Manila',
            'tindaflow.license.installation_file' => $this->workDirectory.'/installation.json',
            'tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()],
            'tindaflow.license.code_keys' => [1 => self::testCodePublicKeyBase64()],
        ]);
        $this->fakeHostHardware();

        $this->artisanThere(['migrate', '--force']);
        $port = $this->serveControlPlane();
        $this->routeClientRequestsTo($port);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        if ($this->workDirectory !== '') {
            File::deleteDirectory($this->workDirectory);
        }
        if ($this->controlPlane !== '') {
            parent::tearDown();
        }
    }

    public function test_activation_then_renewals_form_one_clean_chain(): void
    {
        $this->activate();

        $store = Store::findOrFail($this->storeId);
        $this->assertSame('Cross Repo Cafe', $store->name);
        $this->assertSame($this->deploymentCode, DeploymentId::current(), 'both sides derive the same deployment code from the store id');
        $this->assertSame(LicenseState::Active, $this->licenseState());

        $this->assertRenewal(0, 'still current');
        $this->assertSame(1, $this->heldSeq());

        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 2');
        $this->assertSame(2, $this->heldSeq());
        $this->assertFalse($this->lastRenewalAudit()['chain_gap'], 'the successor links to the token the client held: seq + 1 and prev = its SHA-256');

        // One changed component is tolerated: the chain goes on, the certificate keeps the approved hardware, and the provider is told.
        $this->fakeHostHardware(['bios_serial' => hash('sha256', 'a replaced board firmware')] + self::testFingerprint());
        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 3');
        $this->assertFalse($this->lastRenewalAudit()['chain_gap']);
        $this->assertEquals(self::testFingerprint(), $this->heldFingerprint(), 'the approved hardware is never replaced');
        $this->assertSame(LicenseState::Active, $this->licenseState(), 'two of three still match the certificate');
        $this->assertSame(1, $this->registryEvents('FINGERPRINT_CHANGED'));
    }

    public function test_hardware_cannot_be_walked_away_one_component_at_a_time(): void
    {
        $this->activate();
        $first = ['bios_serial' => hash('sha256', 'another bios')] + self::testFingerprint();
        $this->fakeHostHardware($first);
        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 2');

        $this->fakeHostHardware(['board_serial' => hash('sha256', 'another board')] + $first);
        $this->ageLatestCertificate();

        $this->assertRenewal(1, 'HTTP 403');
        $this->assertSame(2, $this->heldSeq());
    }

    public function test_a_renewal_whose_answer_was_lost_gets_the_same_certificate_again(): void
    {
        $this->activate();
        $this->ageLatestCertificate();

        // The control plane issues certificate 2, and neither address's answer arrives.
        $this->loseRenewalAnswers = true;
        $this->assertRenewal(1, 'could not be reached');
        $this->assertSame(1, $this->heldSeq());

        $this->loseRenewalAnswers = false;
        $this->assertRenewal(0, 'Renewed: certificate 2');

        $this->assertSame(2, (int) $this->registry()->query('select count(*) from issued_certificates')->fetchColumn(), 'no second certificate was issued');
        $this->assertGreaterThanOrEqual(1, $this->registryEvents('RENEWAL_REPLAYED'));
        $this->assertSame(0, $this->registryEvents('CHAIN_FORKED'));
    }

    public function test_a_computer_with_two_changed_components_is_refused(): void
    {
        $this->activate();
        $this->fakeHostHardware(['board_serial' => hash('sha256', 'another board'), 'bios_serial' => hash('sha256', 'another bios')] + self::testFingerprint());

        $this->assertRenewal(1, 'HTTP 403');
        $this->assertSame(1, $this->heldSeq());
        $this->assertSame(1, $this->registryEvents('FINGERPRINT_MISMATCH'));
    }

    public function test_a_request_signed_with_another_key_is_rejected_by_the_control_plane(): void
    {
        $this->activate();
        $identity = app(InstallationIdentity::class)->load();
        $other = sodium_crypto_sign_keypair();
        file_put_contents((string) config('tindaflow.license.installation_file'), json_encode(['secret_key' => base64_encode(sodium_crypto_sign_secretkey($other))] + $identity));

        $this->assertRenewal(1, 'HTTP 401');
        $this->assertSame(1, $this->heldSeq());
    }

    public function test_a_suspended_deployment_is_not_renewed_until_it_is_resumed(): void
    {
        $this->activate();
        $this->ageLatestCertificate();

        $this->artisanThere(['license:suspend', $this->deploymentCode, '--reason=Unpaid']);
        $this->assertRenewal(1, 'HTTP 403');
        $this->assertSame(1, $this->heldSeq());
        $this->assertSame(LicenseState::Active, $this->licenseState(), 'suspending takes nothing away from the certificate already held');

        $this->artisanThere(['license:resume', $this->deploymentCode, '--reason=Paid']);
        $this->assertRenewal(0, 'Renewed: certificate 2');
    }

    public function test_an_installation_restored_from_an_old_backup_is_reported_as_a_fork(): void
    {
        $this->activate();
        $backup = (array) DB::table('license_state')->where('id', 1)->first(['certificate', 'certificate_seq']);
        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 2');

        DB::table('license_state')->where('id', 1)->update($backup);

        $this->assertRenewal(1, 'forked');
        $this->assertSame(1, $this->heldSeq(), 'a fork report changes nothing on the client');
        $this->assertSame(1, $this->registryEvents('CHAIN_FORKED'));
    }

    public function test_a_revocation_signed_by_the_control_plane_locks_the_client_for_good(): void
    {
        $this->activate();

        // The real command asks for the deployment code to be typed back.
        $this->artisanThere(['license:revoke', $this->deploymentCode, '--reason=Breach'], input: $this->deploymentCode."\n");
        $this->assertRenewal(0, 'revoked this installation');

        $verdict = app(LicenseGate::class)->check()->verdict;
        $this->assertSame(LicenseState::Locked, $verdict->state);
        $this->assertSame(RefusalReason::Revoked, $verdict->shiftOpenRefusal());

        $refusal = $this->artisanThere(['license:resume', $this->deploymentCode, '--reason=Mistake'], expectedExit: 1);
        $this->assertStringContainsString('cannot be resumed', $refusal, 'the provider knows the client cannot be unlocked');
    }

    public function test_an_extension_code_from_the_control_plane_keeps_an_offline_client_trading(): void
    {
        $this->activate();
        $certificate = app(LicenseGate::class);
        $expiry = new DateTimeImmutable((string) json_decode((string) Base64Url::decode(explode('.', (string) DB::table('license_state')->value('certificate'))[0]), true)['exp']);

        // The provider makes the code now; the shop is still offline a day after its certificate ran out.
        $this->artisanThere(['license:extension-code', $this->deploymentCode, '--reason=Fibre cut', '--base-seq=1']);
        $code = $this->latestOfflineCode();

        $this->travelTo(CarbonImmutable::instance($expiry)->addDay());
        $this->fakeHostHardware(generatedAt: CarbonImmutable::now()->format('Y-m-d\TH:i:sP'));
        $this->assertSame(LicenseState::Expired, $certificate->check()->verdict->state);

        $this->assertSame(0, Artisan::call('tindaflow:license-code', ['code' => [$code]]), Artisan::output());
        $this->assertSame(LicenseState::Active, $certificate->check()->verdict->state);

        // Back online, a renewal replaces the certificate and drops the extension.
        $this->travelBack();
        $this->fakeHostHardware();
        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 2');
        $this->assertNull(DB::table('license_state')->value('extended_until'));
    }

    public function test_a_time_reset_code_from_the_control_plane_unlocks_a_clock_once_set_ahead(): void
    {
        $this->activate();

        // The clock is set two months ahead while the system runs, then corrected: the recorded time stays ahead and the PC locks.
        $this->travelTo(CarbonImmutable::now()->addDays(60));
        $this->fakeHostHardware(generatedAt: CarbonImmutable::now()->format('Y-m-d\TH:i:sP'));
        Artisan::call('tindaflow:license-heartbeat');
        $this->travelBack();
        $this->assertSame(RefusalReason::ClockRollback, app(LicenseGate::class)->check()->verdict->shiftOpenRefusal());

        $this->artisanThere(['license:time-reset-code', $this->deploymentCode, '--reason=Clock was set ahead']);
        $this->assertSame(0, Artisan::call('tindaflow:license-code', ['code' => [$this->latestOfflineCode()]]), Artisan::output());

        // The heartbeat must not take the wrongly stamped hardware file as the time again.
        Artisan::call('tindaflow:license-heartbeat');
        $this->assertSame(LicenseState::Active, $this->licenseState());
    }

    /** The code as the registry holds it; license:codes prints this same text. */
    private function latestOfflineCode(): string
    {
        $code = (string) $this->registry()->query('select code from offline_codes order by ordinal desc limit 1')->fetchColumn();
        $this->assertStringContainsString($code, $this->artisanThere(['license:codes', $this->deploymentCode]));

        return $code;
    }

    /** The offline path end to end, through the real commands: the client's request, the provider's bundle, the client applying it. */
    private function activate(): void
    {
        $request = $this->workDirectory.'/request.json';
        $bundle = $this->workDirectory.'/bundle.txt';

        $this->assertSame(0, Artisan::call('tindaflow:activate', ['--request' => true, '--out' => $request]), Artisan::output());

        $registered = $this->artisanThere(['license:register-client', 'Cross Repo Cafe', '--seats=2', '--footer=NOT A BIR-REGISTERED INVOICE']);
        $this->assertSame(1, preg_match('/deployment code ([0-9A-F]{8}), store id ([0-9a-f-]{36})/', $registered, $found), $registered);
        [, $this->deploymentCode, $this->storeId] = $found;

        $this->artisanThere(['license:issue-bundle', $this->deploymentCode, $request, '--out='.$bundle]);
        $this->assertSame(0, Artisan::call('tindaflow:activate', ['--bundle' => $bundle, '--admin-email' => 'owner@example.test']), Artisan::output());
    }

    private function assertRenewal(int $expectedExit, string $expectedText): void
    {
        $exit = Artisan::call('tindaflow:license-renew');
        $output = Artisan::output();

        $this->assertSame($expectedExit, $exit, $output.$this->serverLog());
        $this->assertStringContainsString($expectedText, $output, $this->serverLog());
    }

    private function licenseState(): LicenseState
    {
        return app(LicenseGate::class)->check()->verdict->state;
    }

    private function heldSeq(): int
    {
        return (int) DB::table('license_state')->where('id', 1)->value('certificate_seq');
    }

    /** @return array<string, string> the approved hardware inside the certificate held */
    private function heldFingerprint(): array
    {
        $token = (string) DB::table('license_state')->where('id', 1)->value('certificate');

        return json_decode((string) Base64Url::decode(explode('.', $token)[0]), true)['fp'];
    }

    /** @return array<string, mixed> */
    private function lastRenewalAudit(): array
    {
        return AuditEvent::where('event_type', 'LICENSE_RENEWED')->get()->sortByDesc(fn (AuditEvent $event) => $event->after_metadata['seq'])->first()->after_metadata;
    }

    private function registry(): PDO
    {
        return new PDO('sqlite:'.$this->workDirectory.'/registry.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** The control plane re-issues only after 24 hours, so make the one it holds two days old. */
    private function ageLatestCertificate(): void
    {
        $this->registry()->exec("update issued_certificates set iat = datetime('now', '-2 days')");
    }

    private function registryEvents(string $event): int
    {
        $query = $this->registry()->prepare('select count(*) from registry_events where event = ?');
        $query->execute([$event]);

        return (int) $query->fetchColumn();
    }

    /**
     * A clean environment: nothing of this application's own configuration may leak into the control plane's.
     *
     * @return array<string, string>
     */
    private function controlPlaneEnvironment(): array
    {
        $inherited = array_intersect_key(getenv(), array_flip(['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'TEMP', 'TMP', 'HOME', 'USERPROFILE', 'PHPRC', 'PHP_INI_SCAN_DIR']));

        return $inherited + [
            'APP_ENV' => 'local',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_TIMEZONE' => 'UTC',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->workDirectory.'/registry.sqlite',
            'DB_URL' => '',
            'CACHE_STORE' => 'database',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'stderr',
            'LICENSING_SIGNING_KID' => 'test-2026-01',
            'LICENSING_SIGNING_KEY' => base64_encode(sodium_crypto_sign_secretkey(self::testKeyPair())),
            'LICENSING_CODE_KEY_ID' => '1',
            'LICENSING_CODE_SIGNING_KEY' => base64_encode(sodium_crypto_sign_secretkey(self::testKeyPair('tindaflow-code-test-only'))),
            'LICENSING_CONTROL_PLANE_URLS' => self::PRIMARY.','.self::STANDBY,
            'LICENSING_TIMEZONE' => 'Asia/Manila',
        ];
    }

    /** @return list<string> the PHP command, loading sodium where a plain child process would not have it */
    private function php(): array
    {
        static $needsSodium = null;
        if ($needsSodium === null) {
            [$output] = $this->process([PHP_BINARY, '-r', 'echo extension_loaded("sodium") ? "yes" : "no";']);
            $needsSodium = trim($output) !== 'yes';
        }

        return $needsSodium ? [PHP_BINARY, '-d', 'extension=sodium'] : [PHP_BINARY];
    }

    /** @param  list<string>  $arguments */
    private function artisanThere(array $arguments, int $expectedExit = 0, ?string $input = null): string
    {
        [$output, $exit] = $this->process([...$this->php(), 'artisan', ...$arguments], $input);
        $this->assertSame($expectedExit, $exit, 'control plane: artisan '.implode(' ', $arguments)."\n".$output);

        return $output;
    }

    /**
     * @param  list<string>  $command
     * @return array{0: string, 1: int}
     */
    private function process(array $command, ?string $input = null): array
    {
        $errors = $this->workDirectory.'/process-errors.log';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes, $this->controlPlane, $this->environment);
        if ($process === false) {
            throw new RuntimeException('Could not start '.implode(' ', $command));
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        return [$output.(string) @file_get_contents($errors), $exit];
    }

    private function serveControlPlane(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $log = $this->workDirectory.'/server.log';
        $router = $this->controlPlane.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
        $this->server = proc_open(
            [...$this->php(), '-S', "127.0.0.1:{$port}", $router],
            [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $this->controlPlane.'/public',
            $this->environment,
        );

        $client = new Client(['http_errors' => false, 'timeout' => 2]);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            try {
                if ($client->get("http://127.0.0.1:{$port}/up")->getStatusCode() === 200) {
                    return $port;
                }
            } catch (Throwable) {
                // not listening yet
            }
            usleep(100_000);
        }

        $this->fail('The control plane did not start.'.$this->serverLog());
    }

    private function serverLog(): string
    {
        return "\n--- control plane log ---\n".substr((string) @file_get_contents($this->workDirectory.'/server.log'), -4000);
    }

    private function routeClientRequestsTo(int $port): void
    {
        $client = new Client(['http_errors' => false, 'timeout' => 20]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($client, $port) {
            $url = $request->url();
            if (! str_starts_with($url, self::PRIMARY.'/') && ! str_starts_with($url, self::STANDBY.'/')) {
                throw new RuntimeException("The client called an address the bundle did not name: {$url}");
            }

            $response = $client->request($request->method(), "http://127.0.0.1:{$port}".parse_url($url, PHP_URL_PATH), [
                'headers' => array_map(fn (array $values) => implode(', ', $values), array_diff_key($request->headers(), ['Host' => true])),
                'body' => $request->body(),
            ]);

            if ($this->loseRenewalAnswers && str_ends_with($url, '/v1/renewals')) {
                return Http::response('', 503);
            }

            return Http::response((string) $response->getBody(), $response->getStatusCode(), ['Content-Type' => $response->getHeaderLine('Content-Type')]);
        });
    }
}
