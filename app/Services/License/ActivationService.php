<?php

namespace App\Services\License;

use App\Enums\SaleDocumentMode;
use App\Models\Store;
use App\Services\Audit\AuditTrail;
use App\Services\License\Contract\ActivationBundle;
use App\Services\License\Contract\Base64Url;
use App\Services\License\Contract\BundleVerifier;
use App\Services\License\Contract\Certificate;
use App\Services\License\Contract\CertificateRejected;
use App\Services\License\Contract\CertificateVerifier;
use App\Services\License\Time\TrustedClock;
use App\Services\StoreSetup\InventoryLocationService;
use App\Services\StoreSetup\SaleDocumentSettingsService;
use App\Services\Terminal\TerminalProvisioner;
use App\Services\Users\UserService;
use App\Support\DeploymentId;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-018 decision 9: turns a verified activation bundle into a working store, in ONE transaction. It creates the store with the
 * id the provider minted (so the deployment code is the one the provider knows), the break-glass ADMIN (a generated passphrase,
 * shown once to the caller and never stored), an optional MANAGER whose password was typed on site, SLIP mode and footer, the
 * default stock location and the first till, then stores the certificate in `license_state`. Everything goes through the existing
 * services, so the audit rows and rules are the ones the screens already produce. Idempotent per installation: running it again
 * with the same bundle changes nothing.
 *
 * It refuses, with a message the technician can act on, whenever something does not add up: no vendor key in the build, a bundle
 * made for another PC, a certificate that disagrees with its bundle, a lapsed certificate, a clock more than an hour behind the
 * provider's, or a database that already holds a shop.
 *
 * A shop that is already here is licensed with {@see adopt()} instead (ADR-019 slice 3), which records the license for its store
 * and changes nothing else: no user, setting, till, location or sale. The settings in the bundle are not applied. That takes the
 * place of the `--force` that ADR-018 decision 9 named, which would have set a shop up over its own records.
 *
 * A licensed shop on a new or repaired computer is licensed to it with {@see replace()} (ADR-019 slice 3), likewise changing nothing
 * but the license. The provider has by then retired the old computer.
 */
final class ActivationService
{
    /** The tolerance of ADR-018 decision 3, reused: a PC whose clock is further behind the provider than this would lock itself out at once. */
    private const CLOCK_SKEW_SECONDS = 3600;

    public function __construct(
        private readonly InstallationIdentity $identity,
        private readonly UserService $users,
        private readonly SaleDocumentSettingsService $documents,
        private readonly InventoryLocationService $locations,
        private readonly TerminalProvisioner $terminals,
        private readonly TrustedClock $clock,
        private readonly HostHardware $hardware,
        private readonly FingerprintMatcher $matcher,
    ) {}

    /** The installation the stored license names. Read without checking the signature, so only for matching and for the record. */
    public static function licensedInstallation(): ?string
    {
        $token = (string) DB::table('license_state')->where('id', 1)->value('certificate');
        $payload = json_decode((string) Base64Url::decode(explode('.', $token)[0]), true);

        return is_array($payload) && is_string($payload['installation'] ?? null) ? $payload['installation'] : null;
    }

    /**
     * @param  array{name: string, email: string}  $admin
     * @param  array{name: string, email: string, password: string}|null  $manager
     *
     * @throws ActivationRefused
     */
    public function activate(string $bundleToken, array $admin, ?array $manager = null, ?DateTimeImmutable $now = null): ActivationResult
    {
        $now ??= CarbonImmutable::now();
        [$bundle, $certificate] = $this->verified($bundleToken, $now);

        $state = DB::table('license_state')->where('id', 1)->first();
        if (($state->certificate ?? null) !== null) {
            return $this->alreadyActivated($state->certificate, $bundle);
        }

        $this->assertThisHardware($certificate, $now);
        $this->assertCanActivate($bundle);

        $adminPassword = Str::password(20);
        $result = DB::transaction(function () use ($bundle, $certificate, $admin, $manager, $adminPassword) {
            $store = (new Store)->forceFill(['id' => $bundle->storeId, 'name' => $bundle->storeName]);
            $store->save();

            $adminUser = $this->users->create($store->id, ['name' => $admin['name'], 'email' => $admin['email'], 'role' => 'ADMIN', 'password' => $adminPassword]);
            if ($manager !== null) {
                $this->users->create($store->id, ['name' => $manager['name'], 'email' => $manager['email'], 'role' => 'MANAGER', 'password' => $manager['password']], $adminUser);
            }

            $this->documents->changeMode($adminUser, SaleDocumentMode::from($bundle->saleDocumentMode));
            $this->documents->setSlipFooter($adminUser, $bundle->slipFooter);
            $this->locations->create($store->id, $bundle->defaultLocation, true, $adminUser);
            $terminal = $this->terminals->create($store, $bundle->tillCode, $adminUser, 'activation');

            $this->recordLicense($bundle, $certificate, $adminUser->id);

            return new ActivationResult($store, $adminUser, $terminal, $adminPassword, false, $certificate);
        });

        Cache::forget('deployment_id');

        return $result;
    }

    /**
     * Licenses the shop already on this computer. The bundle must be for its store, which the provider registers from a request
     * made with `--request --adopt`. Every other check is the same as a new activation's.
     *
     * @throws ActivationRefused
     */
    public function adopt(string $bundleToken, ?DateTimeImmutable $now = null): ActivationResult
    {
        $now ??= CarbonImmutable::now();
        [$bundle, $certificate] = $this->verified($bundleToken, $now);

        $state = DB::table('license_state')->where('id', 1)->first();
        if (($state->certificate ?? null) !== null) {
            return $this->alreadyActivated($state->certificate, $bundle);
        }

        $this->assertThisHardware($certificate, $now);

        $result = DB::transaction(function () use ($bundle, $certificate) {
            $store = $this->onlyShop($bundle, 'For a new installation leave out --adopt.', 'Make a new request with --adopt, for the provider to register this shop with its own id.');
            $this->recordLicense($bundle, $certificate, null, ['adopted' => true]);

            return new ActivationResult($store, null, null, null, false, $certificate);
        });

        Cache::forget('deployment_id');

        return $result;
    }

    /**
     * Licenses the shop on this computer to this computer, in place of the one it was licensed to before. The bundle must answer
     * the replacement request made here (`--request --replace`), whose new identity takes over together with the license: if the
     * power fails between the two, applying the same bundle again finishes the job.
     *
     * @throws ActivationRefused
     */
    public function replace(string $bundleToken, ?DateTimeImmutable $now = null): ActivationResult
    {
        $now ??= CarbonImmutable::now();
        $pending = $this->identity->pending();
        $noRequest = 'This computer has made no replacement request. Make one with --request --replace and send it to your TindaFlow provider.';
        // With nothing pending this can only be a repeat, for which the bundle must be this computer's own.
        [$bundle, $certificate] = $this->verified($bundleToken, $now, $pending, $pending === null ? $noRequest : null);
        $licensed = self::licensedInstallation();

        if ($licensed === $bundle->installation) {
            if ($pending !== null) {
                $this->identity->promotePending();
            }

            return $this->alreadyActivated((string) DB::table('license_state')->where('id', 1)->value('certificate'), $bundle);
        }
        if ($pending === null) {
            throw new ActivationRefused($noRequest);
        }
        if (DB::table('license_state')->where('id', 1)->value('revoked')) {
            throw new ActivationRefused("This shop's license has been revoked, and replacing the computer does not undo that. Activation was not applied.");
        }

        $this->assertThisHardware($certificate, $now);

        $result = DB::transaction(function () use ($bundle, $certificate, $licensed) {
            $store = $this->onlyShop($bundle, 'Restore the shop first (docker/README.md, "Restoring a whole shop").', 'Check that the right shop was restored here.');
            $this->recordLicense($bundle, $certificate, null, ['replaced_installation' => $licensed]);
            // Last, so that nothing is committed if it fails.
            $this->identity->promotePending();

            return new ActivationResult($store, null, null, null, false, $certificate);
        });

        Cache::forget('deployment_id');

        return $result;
    }

    private function onlyShop(ActivationBundle $bundle, string $ifNone, string $ifAnother): Store
    {
        $stores = Store::query()->lockForUpdate()->get();

        if ($stores->isEmpty()) {
            throw new ActivationRefused("This database holds no shop. {$ifNone} Activation was not applied.");
        }
        if ($stores->count() > 1) {
            throw new ActivationRefused("This database holds {$stores->count()} stores, and a license covers one shop. Activation was not applied; ask your TindaFlow provider.");
        }
        if ($stores->first()->id !== $bundle->storeId) {
            throw new ActivationRefused("This bundle was made for another store than the one here. {$ifAnother} Activation was not applied.");
        }

        return $stores->first();
    }

    /** The license itself: the certificate, where to renew it, the trusted time it shows, and the audit entry. @param  array<string, mixed>  $extra */
    private function recordLicense(ActivationBundle $bundle, Certificate $certificate, ?string $actorUserId, array $extra = []): void
    {
        DB::table('license_state')->updateOrInsert(['id' => 1], [
            'certificate' => $bundle->certificate,
            'certificate_seq' => $certificate->seq,
            'revoked' => false,
            'chain_invalid' => false,
            'control_plane' => json_encode($bundle->controlPlane, JSON_THROW_ON_ERROR),
            // A bundle starts a license afresh. Extensions and offline codes belong to one computer's certificates, so what an
            // earlier one used (carried here by a restore) does not count against this one.
            'extended_until' => null,
            'extension_base_seq' => null,
            'extensions_in_a_row' => 0,
            'last_code_ordinal' => 0,
            'pending_request_id' => null,
            'last_renewal_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->clock->advanceTo($certificate->iat);

        AuditTrail::record($bundle->storeId, 'LICENSE_ACTIVATED', $actorUserId, 'store', $bundle->storeId, null, [
            'installation' => $bundle->installation,
            'deployment' => $certificate->deployment,
            'kid' => $certificate->kid,
            'seq' => $certificate->seq,
            'mode' => $certificate->mode,
            'seats' => $certificate->seats,
            'exp' => $certificate->exp->format('Y-m-d\TH:i:sP'),
        ] + $extra);
    }

    /**
     * @param  array{installation: string, public_key: string, secret_key: string}|null  $identity  the one the bundle must be made
     *                                                                                              for; null = the one in use
     * @return array{0: ActivationBundle, 1: Certificate}
     */
    private function verified(string $bundleToken, DateTimeImmutable $now, ?array $identity = null, ?string $otherComputer = null): array
    {
        $ring = app(LicenseGate::class)->keyRing();
        if ($ring->isEmpty()) {
            throw new ActivationRefused('This build carries no vendor key, so there is nothing to activate against.');
        }

        try {
            $bundle = (new BundleVerifier($ring))->verify(trim($bundleToken));
            $certificate = (new CertificateVerifier($ring))->verify($bundle->certificate);
        } catch (CertificateRejected $e) {
            throw new ActivationRefused('The activation bundle is not valid ('.$e->reason.'). Ask your TindaFlow provider for a new one.');
        }

        $forReplacement = $identity !== null;
        try {
            $identity ??= $this->identity->load();
        } catch (\RuntimeException $e) {
            throw new ActivationRefused($e->getMessage());
        }
        if (! $forReplacement && $bundle->installation === ($this->identity->pending()['installation'] ?? null)) {
            throw new ActivationRefused('This bundle answers the replacement request made here: apply it with --replace.');
        }

        if ($bundle->installation !== $identity['installation'] || ! hash_equals($identity['public_key'], $bundle->installationKey)) {
            throw new ActivationRefused($otherComputer ?? 'This bundle was made for a different computer. Send your own activation request to the provider.');
        }

        if ($certificate->store !== $bundle->storeId || $certificate->installation !== $bundle->installation || $certificate->deployment !== $bundle->deployment
            || DeploymentId::shorten($bundle->storeId) !== $bundle->deployment) {
            throw new ActivationRefused('The bundle and its certificate do not agree about the store or the installation. Ask your TindaFlow provider.');
        }

        if ($certificate->seats < 1) {
            throw new ActivationRefused('The certificate allows no tills. Ask your TindaFlow provider.');
        }

        if ($certificate->exp <= $now) {
            throw new ActivationRefused('The certificate has already expired. Ask your TindaFlow provider for a new bundle.');
        }

        if ($certificate->iat->getTimestamp() - $now->getTimestamp() > self::CLOCK_SKEW_SECONDS) {
            throw new ActivationRefused("This computer's clock is more than an hour behind the provider's. Correct the date and time, then try again.");
        }

        return [$bundle, $certificate];
    }

    /**
     * ADR-018 decision 9: the certificate must have been made for this computer's hardware, by the same 2-of-3 rule the license is
     * later checked with. Here an unreadable hardware file refuses, because nothing has been activated yet.
     */
    private function assertThisHardware(Certificate $certificate, DateTimeImmutable $now): void
    {
        $reading = $this->hardware->read($now);
        if (! $reading->isFresh()) {
            throw new ActivationRefused('The hardware file is '.strtolower($reading->status).', so this computer cannot be checked against the certificate. Run the TindaFlow host helper, then try again.');
        }

        $matched = $this->matcher->matchedCount($certificate->fingerprint, $reading->fingerprint);
        if (! $this->matcher->isAcceptable($matched)) {
            throw new ActivationRefused("The certificate was made for different hardware ({$matched} of 3 values match; ".FingerprintMatcher::REQUIRED_MATCHES.' are needed). Send a new activation request from this computer to your provider.');
        }
    }

    private function assertCanActivate(ActivationBundle $bundle): void
    {
        if (DB::table('sales')->exists()) {
            throw new ActivationRefused('This database already holds sales. To license the shop that is here, make a new request with --adopt. Activation was not applied.');
        }

        if (Store::where('id', '!=', $bundle->storeId)->exists()) {
            throw new ActivationRefused('This database already has a different store. To license the shop that is here, make a new request with --adopt. Activation was not applied.');
        }

        if (Store::whereKey($bundle->storeId)->exists()) {
            throw new ActivationRefused('This database already holds this store. To license it without setting it up again, use --adopt. Activation was not applied.');
        }
    }

    private function alreadyActivated(string $storedCertificate, ActivationBundle $bundle): ActivationResult
    {
        $ring = app(LicenseGate::class)->keyRing();
        try {
            $stored = (new CertificateVerifier($ring))->verify($storedCertificate);
        } catch (CertificateRejected) {
            throw new ActivationRefused('This installation holds a certificate this build no longer accepts. Ask your TindaFlow provider; nothing was changed.');
        }

        if ($stored->store !== $bundle->storeId) {
            throw new ActivationRefused('This installation is already activated for a different store. Nothing was changed.');
        }
        if ($stored->installation !== $bundle->installation) {
            throw new ActivationRefused('This shop is licensed to another computer. On a new or repaired computer, make a request with --replace.');
        }

        return new ActivationResult(Store::findOrFail($bundle->storeId), null, null, null, true, $stored);
    }
}
