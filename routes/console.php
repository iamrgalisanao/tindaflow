<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The `scheduler` container's liveness marker (docker/compose.yaml): its health check fails when this file has not been touched for three
// minutes, so a dead or stuck scheduler shows in `docker compose ps` instead of letting the license quietly run out.
Schedule::call(fn () => @touch(storage_path('framework/scheduler-alive')))->name('scheduler-alive')->everyMinute();

// The overlap locks below expire after a few minutes, not Laravel's default day, so a container killed mid-run (a restart, a power cut)
// cannot hold a lock in the database cache and silently block the license commands for 24 hours.

// ADR-018 decision 3: keeps the licence's trusted-time mark moving. Inert (touches nothing) while the build carries no vendor key.
Schedule::command('tindaflow:license-heartbeat')->everyMinute()->withoutOverlapping(5);

// ADR-018 decision 1: tries to renew the certificate hourly, each PC after its own random delay of up to ten minutes. Does nothing in a
// build with no vendor key or an installation that is not activated. In the background so the delay never holds up the heartbeat.
Schedule::command('tindaflow:license-renew --jitter=600')->hourly()->withoutOverlapping(25)->runInBackground();
