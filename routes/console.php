<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ADR-018 decision 3: keeps the licence's trusted-time mark moving. Inert (touches nothing) while the build carries no vendor key.
Schedule::command('tindaflow:license-heartbeat')->everyMinute()->withoutOverlapping();
