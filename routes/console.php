<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ADR-018 decision 3: keeps the licence's trusted-time mark moving. Inert (touches nothing) while the build carries no vendor key.
Schedule::command('tindaflow:license-heartbeat')->everyMinute()->withoutOverlapping();

// ADR-018 decision 1: tries to renew the certificate hourly, each PC after its own random delay of up to ten minutes. Does nothing in a
// build with no vendor key or an installation that is not activated. In the background so the delay never holds up the heartbeat.
Schedule::command('tindaflow:license-renew --jitter=600')->hourly()->withoutOverlapping()->runInBackground();
