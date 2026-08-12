<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_username_returns_a_bearer_token(): void
    {
        $user = User::factory()->create(['username' => 'gideon', 'password' => Hash::make('secret-password')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'gideon',
            'password' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'username', 'email']]])
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_accepts_email_in_the_same_field(): void
    {
        User::factory()->create(['email' => 'gideon@example.test', 'password' => Hash::make('secret-password')]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'gideon@example.test',
            'password' => 'secret-password',
        ])->assertOk();
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        User::factory()->create(['username' => 'gideon', 'password' => Hash::make('secret-password')]);

        $this->postJson('/api/v1/auth/login', ['login' => 'gideon', 'password' => 'wrong'])
            ->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_rejects_an_unknown_account_without_revealing_which_field_failed(): void
    {
        $response = $this->postJson('/api/v1/auth/login', ['login' => 'nobody', 'password' => 'secret-password']);

        $response->assertUnauthorized();
        $this->assertSame(
            __('auth.failed'),
            $response->json('message'),
            'A missing account and a wrong password must be indistinguishable'
        );
    }

    public function test_login_requires_both_fields(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['login', 'password']);
    }

    public function test_returned_token_authenticates_subsequent_requests(): void
    {
        User::factory()->create(['username' => 'gideon', 'password' => Hash::make('secret-password')]);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => 'gideon',
            'password' => 'secret-password',
        ])->json('data.token');

        // Logout is the only authenticated route this task ships, so it
        // doubles as proof the issued token is accepted.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
    }
}
