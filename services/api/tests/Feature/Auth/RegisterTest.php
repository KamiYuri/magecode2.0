<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'newcomer',
            'email' => 'newcomer@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'first_name' => 'New',
            'last_name' => 'Comer',
        ], $overrides);
    }

    public function test_registration_creates_an_unverified_user(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $user = User::where('username', 'newcomer')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->is_first_time_register);
        $this->assertNotSame('secret-password', $user->password, 'Password must be hashed');
    }

    public function test_registration_sends_a_verification_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        Notification::assertSentTo(User::where('username', 'newcomer')->firstOrFail(), VerifyEmail::class);
    }

    public function test_registration_fires_the_registered_event(): void
    {
        Event::fake([Registered::class]);

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        Event::assertDispatched(Registered::class);
    }

    public function test_registration_does_not_return_a_token(): void
    {
        // The account is unusable until the address is verified, so handing
        // out a token here would defeat the verification step.
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $this->assertNull($response->json('data.token'));
    }

    public function test_registration_rejects_a_duplicate_username_or_email(): void
    {
        User::factory()->create(['username' => 'taken', 'email' => 'taken@example.test']);

        $this->postJson('/api/v1/auth/register', $this->payload(['username' => 'taken']))
            ->assertUnprocessable()->assertJsonValidationErrors('username');

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'taken@example.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_requires_a_confirmed_password_of_at_least_eight_characters(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['password_confirmation' => 'different']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/register', $this->payload([
            'password' => 'short', 'password_confirmation' => 'short',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_registration_requires_every_documented_field(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'email', 'password', 'first_name', 'last_name']);
    }

    public function test_student_id_is_optional_but_must_be_unique(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['student_id' => '20210001']))->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->payload([
            'username' => 'another', 'email' => 'another@example.test', 'student_id' => '20210001',
        ]))->assertUnprocessable()->assertJsonValidationErrors('student_id');
    }
}
