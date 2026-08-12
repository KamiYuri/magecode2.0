<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_signed_link_verifies_the_address(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();

        $this->getJson($this->verificationUrl($user))->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_a_tampered_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson("/api/v1/auth/email/verify/{$user->id}/wrong-hash")->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinute(),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->getJson($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verifying_twice_is_harmless(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->verificationUrl($user);

        $this->getJson($url)->assertOk();
        $verifiedAt = $user->fresh()->email_verified_at;

        $this->getJson($url)->assertOk();

        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at, 'Re-verifying must not move the timestamp');
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );
    }
}
