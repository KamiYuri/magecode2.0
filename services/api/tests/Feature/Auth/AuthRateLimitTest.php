<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** openapi.yml rate-limit table: auth endpoints allow 10 requests/minute per IP. */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('auth');
    }

    public function test_repeated_failed_logins_are_throttled(): void
    {
        User::factory()->create(['username' => 'gideon', 'password' => Hash::make('secret-password')]);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'gideon', 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', ['login' => 'gideon', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_throttling_blocks_the_correct_password_too(): void
    {
        // Otherwise an attacker could keep guessing simply by interleaving a
        // known-good credential to reset nothing.
        User::factory()->create(['username' => 'gideon', 'password' => Hash::make('secret-password')]);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'gideon', 'password' => 'wrong']);
        }

        $this->postJson('/api/v1/auth/login', ['login' => 'gideon', 'password' => 'secret-password'])
            ->assertStatus(429);
    }

    public function test_registration_shares_the_auth_limit(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'nobody', 'password' => 'wrong']);
        }

        $this->postJson('/api/v1/auth/register', [])->assertStatus(429);
    }

    public function test_health_endpoint_is_not_throttled_by_the_auth_limiter(): void
    {
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $this->getJson('/api/v1/health')->assertOk();
        }
    }
}
