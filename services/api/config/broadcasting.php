<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This file exists because its absence was a security hole. Without it the
    | framework default applies, which is `null` — and the null broadcaster
    | does not merely drop events: `NullBroadcaster::auth()` returns without
    | checking anything, so `POST /broadcasting/auth` answers 200 for **every
    | channel and every user**. E9 found a student authorising another
    | student's `private-submission` channel on the deployed stack, while the
    | test suite passed because it pins the driver to `reverb` explicitly
    | (C7 made that choice for exactly this reason).
    |
    | The fallback is therefore `reverb`, not `null`: a missing environment
    | variable now costs a connection error, which is loud, instead of silent
    | universal authorisation (U-8).
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Timeout on the api side, not the client's: a Reverb server
                // that is down must not hold an HTTP request open while a
                // student waits for a page.
                'timeout' => env('REVERB_TIMEOUT', 5),
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        // Kept because Laravel's own tooling names it, and deliberately not
        // the default: see the note above.
        'null' => [
            'driver' => 'null',
        ],

    ],

];
