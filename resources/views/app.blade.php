<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'TindaFlow') }}</title>

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
