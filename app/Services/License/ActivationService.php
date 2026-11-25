<?php

namespace App\Services\License;

use App\Enums\SaleDocumentMode;
use App\Models\Store;
use App\Services\Audit\AuditTrail;
use App\Services\License\Contract\ActivationBundle;
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
 * provider's, a database that already holds sales, or another store.
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
            $store = Store::find($bundle->storeId) ?? (new Store)->forceFill(['id' => $bundle->storeId, 'name' => $bundle->storeName]);
            $store->save();

            $adminUser = $this->users->create($store->id, ['name' => $admin['name'], 'email' => $admin['email'], 'role' => 'ADMIN', 'password' => $adminPassword]);
            if ($manager !== null) {
                $this->users->create($store->id, ['name' => $manager['name'], 'email' => $manager['email'], 'role' => 'MANAGER', 'password' => $manager['password']], $adminUser);
            }

            $this->documents->changeMode($adminUser, SaleDocumentMode::from($bundle->saleDocumentMode));
            $this->documents->setSlipFooter($adminUser, $bundle->slipFooter);
            $this->locations->create($store->id, $bundle->defaultLocation, true, $adminUser);
            $terminal = $this->terminals->create($store, $bundle->tillCode, $adminUser, 'activation');

            DB::table('license_state')->updateOrInsert(['id' => 1], [
                'certificate' => $bundle->certificate,
                'certificate_seq' => $certificate->seq,
                'revoked' => false,
                'chain_invalid' => false,
                'control_plane' => json_encode($bundle->controlPlane, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->clock->advanceTo($certificate->iat);

            AuditTrail::record($store->id, 'LICENSE_ACTIVATED', $adminUser->id, 'store', $store->id, null, [
                'installation' => $bundle->installation,
                'deployment' => $certificate->deployment,
                'kid' => $certificate->kid,
                'seq' => $certificate->seq,
                'mode' => $certificate->mode,
                'seats' => $certificate->seats,
                'exp' => $certificate->exp->format('Y-m-d\TH:i:sP'),
            ]);

            return new ActivationResult($store, $adminUser, $terminal, $adminPassword, false, $certificate);
        });

        Cache::forget('deployment_id');

        return $result;
    }

    /** @return array{0: ActivationBundle, 1: Certificate} */
    private function verified(string $bundleToken, DateTimeImmutable $now): array
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

        try {
            $identity = $this->identity->load();
        } catch (\RuntimeException $e) {
            throw new ActivationRefused($e->getMessage());
        }

        if ($bundle->installation !== $identity['installation'] || ! hash_equals($identity['public_key'], $bundle->installationKey)) {
            throw new ActivationRefused('This bundle was made for a different computer. Send your own activation request to the provider.');
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
            throw new ActivationRefused('This database already holds sales. Activation is for a new installation and was not applied.');
        }

        $other = Store::where('id', '!=', $bundle->storeId)->exists();
        if ($other) {
            throw new ActivationRefused('This database already has a different store. Activation was not applied.');
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

        if ($stored->installation !== $bundle->installation || $stored->store !== $bundle->storeId) {
            throw new ActivationRefused('This installation is already activated for a different store. Nothing was changed.');
        }

        return new ActivationResult(Store::findOrFail($bundle->storeId), null, null, null, true, $stored);
    }
}
