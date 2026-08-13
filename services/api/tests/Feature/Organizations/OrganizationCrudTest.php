<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * The first endpoints to return the `{data, meta}` cursor envelope, so this
 * suite pins the envelope shape as much as it pins organization behaviour.
 */
class OrganizationCrudTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    public function test_listing_returns_only_organizations_the_caller_belongs_to(): void
    {
        $mine = $this->organizationWithAdmin();
        $this->organizationWithAdmin();
        Sanctum::actingAs($mine->creator);

        $response = $this->getJson('/api/v1/organizations')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_listing_carries_the_documented_cursor_meta(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $this->organizationMember($this->organizationWithAdmin(), OrganizationRole::Instructor, $user);
        }
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/organizations?per_page=2')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'description', 'email', 'avatar_url', 'creator', 'my_role',
                    'members_count', 'courses_count', 'created_at', 'updated_at']],
                'meta' => ['next_cursor', 'prev_cursor', 'per_page', 'has_more'],
            ]);

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertTrue($response->json('meta.has_more'));
        $this->assertNotNull($response->json('meta.next_cursor'));
        $this->assertNull($response->json('meta.prev_cursor'));

        $next = $this->getJson('/api/v1/organizations?per_page=2&cursor='.$response->json('meta.next_cursor'))
            ->assertOk();

        $this->assertCount(1, $next->json('data'));
        $this->assertFalse($next->json('meta.has_more'));
    }

    public function test_listing_clamps_per_page_to_the_documented_maximum(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->getJson('/api/v1/organizations?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_listing_reports_the_callers_own_role_and_the_counts(): void
    {
        $organization = $this->organizationWithAdmin();
        $instructor = $this->organizationMember($organization);
        $this->courseIn($organization);
        Sanctum::actingAs($instructor);

        $this->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.my_role', OrganizationRole::Instructor->value)
            ->assertJsonPath('data.0.members_count', 2)
            ->assertJsonPath('data.0.courses_count', 1)
            ->assertJsonPath('data.0.creator.id', $organization->creator_id);
    }

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/organizations')->assertUnauthorized();
    }

    public function test_a_system_admin_creates_an_organization_and_becomes_its_admin(): void
    {
        // Creation is System-Admin-only, but management needs an Org Admin
        // row, so the creator is enrolled in the same transaction
        // (decisions-v3 §7, 2026-08-13).
        $systemAdmin = User::factory()->systemAdmin()->create();
        Sanctum::actingAs($systemAdmin);

        $response = $this->postJson('/api/v1/organizations', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Hanoi University of Science and Technology')
            ->assertJsonPath('data.my_role', OrganizationRole::Admin->value)
            ->assertJsonPath('data.members_count', 1)
            ->assertJsonPath('data.courses_count', 0)
            ->assertJsonPath('data.creator.id', $systemAdmin->id);

        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $response->json('data.id'),
            'user_id' => $systemAdmin->id,
            'role' => OrganizationRole::Admin->value,
            'added_by' => $systemAdmin->id,
        ]);
    }

    public function test_creation_is_denied_to_an_org_admin(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->postJson('/api/v1/organizations', $this->payload())->assertForbidden();
    }

    public function test_creation_validates_the_payload(): void
    {
        Sanctum::actingAs(User::factory()->systemAdmin()->create());

        $this->postJson('/api/v1/organizations', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email'])
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_a_member_reads_the_organization_and_an_outsider_does_not(): void
    {
        $organization = $this->organizationWithAdmin();
        $instructor = $this->organizationMember($organization);

        Sanctum::actingAs($instructor);
        $this->getJson("/api/v1/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $organization->id);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden();
    }

    public function test_reading_a_missing_organization_is_a_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/organizations/404404')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_an_org_admin_updates_the_organization(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->putJson("/api/v1/organizations/{$organization->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->assertSame('Renamed', $organization->fresh()?->name);
    }

    public function test_a_system_admin_updates_any_organization(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs(User::factory()->systemAdmin()->create());

        $this->putJson("/api/v1/organizations/{$organization->id}", ['name' => 'Renamed'])->assertOk();
    }

    public function test_an_instructor_may_not_update_the_organization(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($this->organizationMember($organization));

        $this->putJson("/api/v1/organizations/{$organization->id}", ['name' => 'Renamed'])->assertForbidden();
    }

    public function test_update_leaves_untouched_fields_alone(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->putJson("/api/v1/organizations/{$organization->id}", ['description' => 'New description'])
            ->assertOk()
            ->assertJsonPath('data.name', $organization->name)
            ->assertJsonPath('data.description', 'New description');
    }

    public function test_the_admin_listing_shows_every_organization_to_a_system_admin(): void
    {
        $this->organizationWithAdmin();
        $this->organizationWithAdmin();
        Sanctum::actingAs(User::factory()->systemAdmin()->create());

        $response = $this->getJson('/api/v1/admin/organizations')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['next_cursor', 'prev_cursor', 'per_page', 'has_more']]);

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(Organization::count(), count($response->json('data')));
    }

    public function test_the_admin_listing_is_denied_to_an_org_admin(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->getJson('/api/v1/admin/organizations')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Hanoi University of Science and Technology',
            'description' => 'Trường Đại học Bách khoa Hà Nội',
            'email' => 'contact@hust.edu.vn',
        ], $overrides);
    }
}
