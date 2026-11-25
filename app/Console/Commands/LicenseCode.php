<?php

namespace App\Console\Commands;

use App\Services\License\OfflineCodeRefused;
use App\Services\License\OfflineCodeService;
use Illuminate\Console\Command;

/**
 * ADR-018 decisions 3 and 6: applies an offline code the provider sent (an extension, or a time reset). It asks for the code rather
 * than only taking it as an argument, because a code ends in a check symbol that a shell may read as its own (`*`, `~`, `$`).
 * The Manager area will offer the same entry later.
 */
class LicenseCode extends Command
{
    protected $signature = 'tindaflow:license-code {code?* : The code (leave it out to be asked for it, which is safer)}';

    protected $description = 'Apply an offline code from the provider (extend the license, or reset its recorded time)';

    public function handle(OfflineCodeService $codes): int
    {
        $text = implode('', (array) $this->argument('code'));
        if (trim($text) === '') {
            $text = (string) $this->ask('Paste the code');
        }

        try {
            $this->info($codes->apply($text));
        } catch (OfflineCodeRefused $refused) {
            $this->error($refused->getMessage().' Nothing was changed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
