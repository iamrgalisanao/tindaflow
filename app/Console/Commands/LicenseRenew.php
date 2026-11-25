<?php

namespace App\Console\Commands;

use App\Services\License\Renewal\RenewalResult;
use App\Services\License\Renewal\RenewalService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ADR-018 decision 1: asks the provider for the next certificate. The scheduler runs it hourly with a random delay (so a fleet
 * does not call in on the same second); a person runs it as "check the license now". Safe to run any time: it does nothing in a
 * build with no vendor key or on an installation that is not activated, and a failed attempt changes nothing but the recorded error.
 *
 * With no internet (ADR-019 slice 3), `--request` writes the signed request to carry to the provider and `--apply` applies the
 * reply. Without `--out`, the request is the only thing printed, so that it can be redirected into a file from outside a container.
 */
class LicenseRenew extends Command
{
    protected $signature = 'tindaflow:license-renew
        {--jitter=0 : Wait a random 0 to N seconds first (the scheduler uses this)}
        {--request : No internet: write a renewal request file to send to the provider}
        {--out= : With --request, the file to write (otherwise the request is printed)}
        {--apply= : No internet: apply the reply file the provider sent back}';

    protected $description = 'Ask the provider for the next license certificate, online or by file';

    public function handle(RenewalService $renewals): int
    {
        $apply = $this->option('apply');
        if ($this->option('request') && $apply !== null) {
            $this->error('Give --request or --apply, not both.');

            return self::FAILURE;
        }

        if ($this->option('request')) {
            return $this->writeRequest($renewals->requestFile());
        }

        if ($apply !== null) {
            $contents = is_file((string) $apply) ? file_get_contents((string) $apply) : false;
            if ($contents === false) {
                $this->error('No such file: '.$apply);

                return self::FAILURE;
            }
            $result = $renewals->applyFile($contents);

            // Skipped means nothing was applied, which a person applying a file needs to hear as a failure.
            return $this->report($result, $result->failed() || $result->outcome === RenewalResult::SKIPPED);
        }

        $jitter = max(0, (int) $this->option('jitter'));
        if ($jitter > 0) {
            sleep(random_int(0, $jitter));
        }

        $result = $renewals->renew();

        return $this->report($result, $result->failed());
    }

    private function writeRequest(RenewalResult $result): int
    {
        if ($result->document === null) {
            return $this->report($result, true);
        }

        $out = $this->option('out');
        if ($out === null) {
            $this->getOutput()->writeln($result->document, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        if (file_put_contents((string) $out, $result->document."\n") === false) {
            $this->error('Could not write '.$out.'.');

            return self::FAILURE;
        }
        $this->info("Renewal request written to {$out}. Send that file to your TindaFlow provider, then apply the reply with: php artisan tindaflow:license-renew --apply=FILE");

        return self::SUCCESS;
    }

    private function report(RenewalResult $result, bool $failed): int
    {
        $failed ? $this->error($result->message) : $this->line($result->message);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
