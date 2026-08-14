<?php

declare(strict_types=1);

return [

    /*
     * The disk every storage service writes through. Named separately from
     * `filesystems.default` so a second disk could coexist if one is ever needed.
     */
    'disk' => env('MINIO_DISK', 'minio'),

    /*
     * Pre-signed GET lifetime in seconds. D-85 sets 6h: long enough to cover the
     * maximum queue wait plus the 30-minute analysis timeout plus a buffer, so a
     * job that waits behind a backlog still finds its URL valid.
     *
     * URLs are signed against the internal endpoint. SigV4 covers the Host
     * header, so they are only fetchable from inside the compose network — which
     * is what SIM/AID/VUL need (D-80/D-85). That is deliberate and final:
     * anything a browser must read is streamed by an api route instead, so no
     * bucket is ever published (v3 §7, 2026-08-14).
     */
    'presigned_url_ttl' => (int) env('MINIO_PRESIGNED_URL_TTL', 21600),

];
