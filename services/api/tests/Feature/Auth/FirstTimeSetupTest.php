<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Students imported from a roster get a generated password and
 * is_first_time_register=true; this endpoint is how they take ownership of
 * the account.
 */
class FirstTimeSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_sets_the_password_and_clears_the_flag(): void
    {
        $user = User::factory()->firstTime()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/first-time-setup', [
            'password' => 'chosen-password',
            'password_confirmation' => 'chosen-password',
        ])->assertOk()->assertJsonPath('data.is_first_time_register', false);

        $user->refresh();
        $this->assertTrue(Hash::check('chosen-password', $user->password));
        $this->assertFalse($user->is_first_time_register);
    }

    public function test_setup_can_correct_the_imported_name(): void
    {
        $user = User::factory()->firstTime()->create(['first_name' => 'Wrong', 'last_name' => 'Name']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/first-time-setup', [
            'password' => 'chosen-password',
            'password_confirmation' => 'chosen-password',
            'first_name' => 'Đúng',
            'last_name' => 'Tên',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('Đúng', $user->first_name);
        $this->assertSame('Tên', $user->last_name);
    }

    public function test_setup_marks_the_address_verified(): void
    {
        // The generated password only reached the student by email, so
        // completing setup proves they read it.
        $user = User::factory()->firstTime()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/first-time-setup', [
            'password' => 'chosen-password',
            'password_confirmation' => 'chosen-password',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_setup_is_refused_once_the_flag_is_cleared(): void
    {
        $user = User::factory()->create(['is_first_time_register' => false]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/first-time-setup', [
            'password' => 'chosen-password',
            'password_confirmation' => 'chosen-password',
        ])->assertForbidden();
    }

    public function test_setup_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/first-time-setup', [
            'password' => 'chosen-password',
            'password_confirmation' => 'chosen-password',
        ])->assertUnauthorized();
    }

    public function test_setup_requires_a_confirmed_password(): void
    {
        Sanctum::actingAs(User::factory()->firstTime()->create());

        $this->postJson('/api/v1/auth/first-time-setup', [
            'password' => 'chosen-password',
            'password_confirmation' => 'mismatch',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
