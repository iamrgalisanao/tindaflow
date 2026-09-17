<?php

// New in A1 (Module A auth/session foundation). Centralizes small
// application-level settings that don't belong in a stock Laravel config
// file, starting with the login throttle's attempts/window (Module A
// Decision Register SS14 Ruling 6) so neither is a magic number embedded
// in the rate limiter registration or its tests.
return [

    'login_throttle' => [
        'max_attempts' => env('LOGIN_THROTTLE_MAX_ATTEMPTS', 5),
        'decay_minutes' => env('LOGIN_THROTTLE_DECAY_MINUTES', 1),
    ],

];
