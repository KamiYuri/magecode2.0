<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_a_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'gideon@example.test']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'gideon@example.test'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_answers_the_same_way_for_an_unknown_address(): void
    {
        Notification::fake();

        // The spec requires a flat 200 so the endpoint cannot be used to
        // discover which addresses have accounts.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_forgot_password_validates_the_address_format(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_reset_password_changes_the_password_with_a_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'gideon@example.test']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $token = $this->capturedResetToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_reset_password_rejects_a_forged_token(): void
    {
        $user = User::factory()->create(['email' => 'gideon@example.test']);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'forged',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_reset_password_revokes_existing_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'gideon@example.test']);
        $user->createToken('old-session');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $this->capturedResetToken($user),
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        // A password reset is the remedy for a stolen session, so it must end
        // every session rather than only setting a new password.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reset_password_requires_a_confirmed_password(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'gideon@example.test',
            'token' => 'whatever',
            'password' => 'brand-new-password',
            'password_confirmation' => 'mismatch',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    private function capturedResetToken(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        return (string) $token;
    }
}
