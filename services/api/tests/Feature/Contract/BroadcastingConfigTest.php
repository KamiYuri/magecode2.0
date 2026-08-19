<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use Tests\TestCase;

/**
 * The null broadcaster is not a quiet no-op. `NullBroadcaster::auth()` returns
 * without checking anything, so `POST /broadcasting/auth` answers 200 for every
 * channel and every user — U-8's whole matrix, bypassed by an unset
 * environment variable.
 *
 * E9 found exactly that on the deployed stack: the api container selected no
 * broadcaster, the framework default applied, and a student authorised another
 * student's private submission channel. The suite could not see it because it
 * pins the driver to reverb itself (C7).
 */
class BroadcastingConfigTest extends TestCase
{
    public function test_the_default_broadcaster_is_never_null_when_the_environment_is_silent(): void
    {
        $default = config('broadcasting.default');

        $this->assertNotSame('null', $default,
            'the null broadcaster authorises every channel for every user');
    }

    public function test_an_unset_broadcast_connection_falls_back_to_reverb(): void
    {
        // The config file is what decides this; reading it directly is the
        // only way to see the fallback, since the test environment sets the
        // variable.
        $config = require config_path('broadcasting.php');

        $this->assertSame('reverb', $config['default'],
            'with BROADCAST_CONNECTION unset the fallback must be a real driver');
    }

    public function test_the_reverb_connection_is_configured(): void
    {
        $this->assertSame('reverb', config('broadcasting.connections.reverb.driver'));
    }
}
