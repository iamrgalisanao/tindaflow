<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'TindaFlow') }}</title>

        {{-- A per-installation fingerprint, for anti-redistribution forensics only -- see App\Support\DeploymentId.
             Absent before the first store exists. Present on every page, not just the back office, so it survives
             even if someone strips the admin footer that reads it. --}}
        @if ($deploymentId = \App\Support\DeploymentId::current())
            <meta name="tindaflow-deployment" content="{{ $deploymentId }}">
        @endif

        {{-- No pre-built manifest or Vite dev server is required for this
             route to return 200 and set the XSRF-TOKEN cookie (Laravel's
             `web` middleware group does that regardless) -- tests/Database's
             CSRF-bootstrap test proves exactly that, and must keep passing
             on a fresh checkout before anyone has run `npm run build`. --}}
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @viteReactRefresh
            @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @endif
    </head>
    <body class="antialiased">
        <div id="app"></div>
    </body>
</html>
