<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** B12: the caller's own account. */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');
        $this->user = User::factory()->create(['first_name' => 'Dung', 'last_name' => 'Hoang']);
    }

    public function test_it_returns_the_callers_own_account(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $this->user->id)
            ->assertJsonPath('data.first_name', 'Dung');
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_it_updates_the_editable_fields(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/profile', ['first_name' => 'Hùng', 'student_id' => '20200001'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Hùng')
            ->assertJsonPath('data.student_id', '20200001')
            // Untouched fields stay put: the request is a patch in PUT's clothing.
            ->assertJsonPath('data.last_name', 'Hoang');
    }

    public function test_the_password_change_needs_the_current_one(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'not-the-password',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_a_password_change_revokes_the_other_sessions(): void
    {
        $this->user->forceFill(['password' => Hash::make('the-old-password')])->save();

        $elsewhere = $this->user->createToken('phone')->accessToken;

        $this->actingAs($this->user)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'the-old-password',
                'password' => 'the-new-password',
                'password_confirmation' => 'the-new-password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('the-new-password', (string) $this->user->fresh()->password));
        // A password change is what you do when you think someone else has it.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $elsewhere->getKey()]);
    }

    public function test_an_avatar_is_stored_and_the_url_points_at_the_stream(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/api/v1/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('me.png', 10, 'image/png'),
            ])
            ->assertOk();

        $path = $this->user->fresh()?->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('minio')->assertExists($path);

        $this->assertStringEndsWith(
            "/api/v1/users/{$this->user->id}/avatar",
            (string) $response->json('data.avatar_url')
        );
    }

    /** The bytes come back through api, because no bucket is browser-reachable. */
    public function test_the_avatar_streams_back(): void
    {
        $this->actingAs($this->user)->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('me.png', 10, 'image/png'),
        ])->assertOk();

        $this->actingAs($this->user)
            ->get("/api/v1/users/{$this->user->id}/avatar")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_streaming_an_unset_avatar_is_a_404(): void
    {
        $this->actingAs($this->user)
            ->get("/api/v1/users/{$this->user->id}/avatar")
            ->assertNotFound();
    }

    public function test_replacing_an_avatar_removes_the_old_object(): void
    {
        $this->actingAs($this->user)->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('first.png', 10, 'image/png'),
        ])->assertOk();
        $first = $this->user->fresh()?->avatar_path;

        $this->actingAs($this->user)->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('second.png', 10, 'image/png'),
        ])->assertOk();
        $second = $this->user->fresh()?->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('minio')->assertMissing((string) $first);
        Storage::disk('minio')->assertExists((string) $second);
    }

    public function test_deleting_an_avatar_clears_the_row_and_the_object(): void
    {
        $this->actingAs($this->user)->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('me.png', 10, 'image/png'),
        ])->assertOk();
        $path = (string) $this->user->fresh()?->avatar_path;

        $this->actingAs($this->user)->deleteJson('/api/v1/profile/avatar')->assertNoContent();

        $this->assertNull($this->user->fresh()?->avatar_path);
        Storage::disk('minio')->assertMissing($path);
    }

    public function test_a_document_is_not_an_avatar(): void
    {
        $this->actingAs($this->user)
            ->post('/api/v1/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_an_oversized_image_is_refused(): void
    {
        $tooBig = AvatarStorageService::MAX_FILE_BYTES / 1024 + 1;

        $this->actingAs($this->user)
            ->post('/api/v1/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('huge.png', (int) $tooBig, 'image/png'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }
}
