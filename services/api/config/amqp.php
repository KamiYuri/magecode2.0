<?php

declare(strict_types=1);

return [

    /*
     * Connection to the broker. The names match the RABBITMQ_* variables the
     * compose file already hands this service, and the Go workers' RABBITMQ_URL
     * points at the same host and vhost.
     */
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'magecode'),
    'password' => env('RABBITMQ_PASSWORD', ''),
    'vhost' => env('RABBITMQ_VHOST', 'magecode'),

    /*
     * Seconds to wait for the socket, and for the broker to confirm a publish.
     * Both are short on purpose: this runs inside a request that has already
     * stored the submission, so a slow broker must not hold a student waiting
     * on a response whose outcome is already decided.
     */
    'connect_timeout' => (float) env('RABBITMQ_CONNECT_TIMEOUT', 3.0),
    'publish_timeout' => (float) env('RABBITMQ_PUBLISH_TIMEOUT', 5.0),

];
