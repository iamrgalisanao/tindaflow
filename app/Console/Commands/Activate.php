<?php

namespace App\Console\Commands;

use App\Domain\Exceptions\DomainException;
use App\Models\Shift;
use App\Models\Store;
use App\Services\License\ActivationRefused;
use App\Services\License\ActivationService;
use App\Services\License\Contract\Certificate;
use App\Services\License\HostHardware;
use App\Services\License\InstallationIdentity;
use App\Services\License\LicenseGate;
use App\Services\Terminal\TerminalSeats;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ADR-018 decision 9, the offline path: `--request` writes what the provider needs to register this PC (its installation UUID and
 * public key), and `--bundle` applies the provider's signed reply. The first creates nothing but the identity file; the second
 * creates the store, its first users, settings, stock location and till, and stores the first certificate, all or nothing.
 *
 * For a shop that already trades (ADR-019 slice 3), both take `--adopt`: the request then names the shop's own store, and applying
 * the bundle records the license for it and changes nothing else.
 */
class Activate extends Command
{
    protected $signature = 'tindaflow:activate
        {--request : Make this PC\'s activation request for the provider (creates the identity file once; safe to repeat)}
        {--out= : With --request, write the request to this file instead of the screen}
        {--adopt : License the shop already on this computer, changing nothing else (with --request, and again with --bundle)}
        {--bundle= : The provider\'s activation bundle file, to apply}
        {--admin-email= : With --bundle, the sign-in email of the break-glass administrator}
        {--admin-name=Store Administrator : With --bundle, the administrator\'s name}
        {--manager-email= : With --bundle, also create a manager with this email (the password is asked for)}
        {--manager-name=Store Manager : With --bundle, the manager\'s name}';

    protected $description = 'Activate this installation: make the activation request, or apply the provider\'s bundle';

    public function handle(InstallationIdentity $identity, ActivationService $activation): int
    {
        if ($this->option('request') === ($this->option('bundle') !== null)) {
            $this->error('Give exactly one of --request or --bundle=FILE.');

            return self::FAILURE;
        }

        if ($this->option('request')) {
            return $this->request($identity, app(HostHardware::class));
        }

        return $this->option('adopt') ? $this->adoptBundle($activation) : $this->applyBundle($activation);
    }

    private function request(InstallationIdentity $identity, HostHardware $hardware): int
    {
        // Before anything is made: which shop this request is for, if one is already here.
        $stores = Store::query()->oldest('created_at')->get(['id', 'name']);
        if ($this->option('adopt') && $stores->count() !== 1) {
            $this->error($stores->isEmpty()
                ? 'There is no shop to adopt here. For a new installation leave out --adopt. No request was made.'
                : "This database holds {$stores->count()} stores, and a license covers one shop. No request was made; ask your TindaFlow provider.");

            return self::FAILURE;
        }
        if (! $this->option('adopt') && $stores->isNotEmpty()) {
            $this->error("This computer already has a shop ('{$stores->first()->name}'). To license it as it is, add --adopt. No request was made.");

            return self::FAILURE;
        }
        $adopted = $this->option('adopt') ? ['id' => $stores->first()->id, 'name' => $stores->first()->name] : null;

        // A certificate binds the three hardware hashes, so a request without them cannot be answered. Say so here, not after a round trip.
        $reading = $hardware->read();
        if (! $reading->isFresh()) {
            $this->error('The host helper has not written a current hardware file ('.$reading->status.' at '.$hardware->path().'). Install it (docker/host-helper/Install-HostHelper.ps1), or run its -Check to see whether this computer can supply the three values. No request was made.');

            return self::FAILURE;
        }

        $json = json_encode($identity->request($reading->fingerprint, $adopted), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($this->option('out') !== null) {
            file_put_contents((string) $this->option('out'), $json."\n");
            $this->info('Activation request written to '.$this->option('out').'. Send that file to your TindaFlow provider.');

            return self::SUCCESS;
        }

        $this->line($json);
        $this->info('Send the request above to your TindaFlow provider.');

        return self::SUCCESS;
    }

    private function adoptBundle(ActivationService $activation): int
    {
        $file = (string) $this->option('bundle');
        if (! is_file($file)) {
            $this->error("There is no file at '{$file}'.");

            return self::FAILURE;
        }
        if ($this->option('admin-email') !== null || $this->option('manager-email') !== null) {
            $this->error('--adopt creates no user: the shop keeps the ones it has. Leave out --admin-email and --manager-email. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $result = $activation->adopt((string) file_get_contents($file));
        } catch (ActivationRefused $refused) {
            $this->error($refused->getMessage().' Nothing was changed.');

            return self::FAILURE;
        }

        if ($result->alreadyActive) {
            $this->info("Already activated for '{$result->store->name}' (deployment {$result->certificate->deployment}). Nothing was changed.");

            return self::SUCCESS;
        }

        $certificate = $result->certificate;
        $this->info("Adopted '{$result->store->name}' (deployment {$certificate->deployment}); certificate valid until ".$certificate->exp->format('Y-m-d H:i').'.');
        $this->line('Nothing else in the shop was changed: its users, settings, tills and sales are as they were.');
        if ($certificate->mode === Certificate::MODE_REPORT) {
            $this->line('This certificate is in report mode: nothing is refused, and what would be refused is logged.');
        }
        $tills = app(TerminalSeats::class)->inUse();
        if ($tills > $certificate->seats) {
            $this->warn("This shop has {$tills} tills and the license covers {$certificate->seats}. Nothing is blocked: the till limit in the license is not enforced yet. Tell your TindaFlow provider.");
        }
        if (Shift::where('status', 'OPEN')->exists()) {
            $this->line('A shift is open. It carries on.');
        }
        $refusal = app(LicenseGate::class)->check()->verdict->shiftOpenRefusal();
        if ($refusal !== null) {
            $this->warn("A new shift would be refused ({$refusal->value}). Run tindaflow:license-status and tell your TindaFlow provider.");
        }

        return self::SUCCESS;
    }

    private function applyBundle(ActivationService $activation): int
    {
        $file = (string) $this->option('bundle');
        if (! is_file($file)) {
            $this->error("There is no file at '{$file}'.");

            return self::FAILURE;
        }

        $adminEmail = trim((string) $this->option('admin-email'));
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Give the administrator\'s sign-in email with --admin-email=.');

            return self::FAILURE;
        }

        $manager = $this->manager();
        if ($manager === false) {
            return self::FAILURE;
        }

        try {
            $result = $activation->activate((string) file_get_contents($file), ['name' => (string) $this->option('admin-name'), 'email' => $adminEmail], $manager);
        } catch (ActivationRefused|DomainException|ValidationException $refused) {
            $this->error($refused->getMessage().' Nothing was changed.');

            return self::FAILURE;
        }

        if ($result->alreadyActive) {
            $this->info("Already activated for '{$result->store->name}' (deployment {$result->certificate->deployment}). Nothing was changed.");

            return self::SUCCESS;
        }

        $this->info("Activated '{$result->store->name}' (deployment {$result->certificate->deployment}); certificate valid until ".$result->certificate->exp->format('Y-m-d H:i').'.');
        $this->warn("Break-glass administrator '{$result->admin->email}'. Write this passphrase down and seal it; it is shown once and stored nowhere:");
        $this->getOutput()->writeln((string) $result->adminPassword, OutputInterface::OUTPUT_RAW);
        $this->line("Created till '{$result->terminal->terminal_code}'. Next: sign in as an administrator, open Terminals, click \"Enrollment token\" on that till, and enter the token on the till computer.");

        return self::SUCCESS;
    }

    /** @return array{name: string, email: string, password: string}|null|false null = no manager asked for, false = refused */
    private function manager(): array|null|false
    {
        $email = trim((string) $this->option('manager-email'));
        if ($email === '') {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('--manager-email is not a valid email address.');

            return false;
        }

        $password = (string) $this->secret('Manager password (at least 8 characters)');
        if (mb_strlen($password) < 8 || $password !== (string) $this->secret('Type it again')) {
            $this->error('The manager password must be at least 8 characters and typed the same twice. Nothing was changed.');

            return false;
        }

        return ['name' => (string) $this->option('manager-name'), 'email' => $email, 'password' => $password];
    }
}
