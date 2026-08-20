<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * B6 left these out because C1 had not yet landed the MinIO layer. Writing an
 * organization's avatar is administering it; reading is the same stream the
 * user avatars use.
 */
class OrganizationAvatarTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');
        $this->admin = User::factory()->create();
        $this->organization = $this->organizationWithAdmin($this->admin);
    }

    public function test_an_admin_uploads_and_it_streams_back(): void
    {
        $this->actingAs($this->admin)
            ->post("/api/v1/organizations/{$this->organization->id}/avatar", [
                'avatar' => UploadedFile::fake()->create('logo.png', 10, 'image/png'),
            ])
            ->assertOk();

        Storage::disk('minio')->assertExists((string) $this->organization->fresh()?->avatar_path);

        $this->actingAs($this->admin)
            ->get("/api/v1/organizations/{$this->organization->id}/avatar")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_the_resource_links_to_the_stream_once_one_is_set(): void
    {
        $this->actingAs($this->admin)
            ->post("/api/v1/organizations/{$this->organization->id}/avatar", [
                'avatar' => UploadedFile::fake()->create('logo.png', 10, 'image/png'),
            ])->assertOk();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/organizations/{$this->organization->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.avatar_url',
                url("/api/v1/organizations/{$this->organization->id}/avatar")
            );
    }

    public function test_an_unset_avatar_is_a_404_and_a_null_url(): void
    {
        $this->actingAs($this->admin)
            ->getJson("/api/v1/organizations/{$this->organization->id}")
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        $this->actingAs($this->admin)
            ->get("/api/v1/organizations/{$this->organization->id}/avatar")
            ->assertNotFound();
    }

    public function test_an_instructor_may_not_change_the_organizations_face(): void
    {
        $instructor = $this->organizationMember($this->organization, OrganizationRole::Instructor);

        $this->actingAs($instructor)
            ->post("/api/v1/organizations/{$this->organization->id}/avatar", [
                'avatar' => UploadedFile::fake()->create('logo.png', 10, 'image/png'),
            ])
            ->assertForbidden();
    }

    public function test_an_admin_removes_it(): void
    {
        $this->actingAs($this->admin)
            ->post("/api/v1/organizations/{$this->organization->id}/avatar", [
                'avatar' => UploadedFile::fake()->create('logo.png', 10, 'image/png'),
            ])->assertOk();
        $path = (string) $this->organization->fresh()?->avatar_path;

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/organizations/{$this->organization->id}/avatar")
            ->assertNoContent();

        $this->assertNull($this->organization->fresh()?->avatar_path);
        Storage::disk('minio')->assertMissing($path);
    }
}
