<?php

// TindaFlow is a same-origin SPA + JSON API (session cookie, SameSite=Strict): no other origin ever needs to call
// it from a browser. With no paths listed, Laravel's CORS middleware does nothing, so no
// Access-Control-Allow-Origin header is sent and a cross-origin preflight is not answered. The framework's own
// default (every origin, every header) would have answered them.
return [

    'paths' => [],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
