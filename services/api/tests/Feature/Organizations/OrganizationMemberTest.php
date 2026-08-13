<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * Bulk membership: existing users are added, known members are skipped, and
 * unknown addresses are provisioned as first-time accounts and invited.
 */
class OrganizationMemberTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    public function test_listing_returns_the_roster_with_the_documented_envelope(): void
    {
        $organization = $this->organizationWithAdmin();
        $invited = $this->organizationMember($organization);
        OrganizationMember::where('user_id', $invited->id)->update(['added_by' => $organization->creator_id]);
        Sanctum::actingAs($organization->creator);

        $response = $this->getJson("/api/v1/organizations/{$organization->id}/members")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'user' => ['id', 'username', 'first_name', 'last_name', 'student_id', 'avatar_url'],
                    'role', 'added_by', 'created_at']],
                'meta' => ['next_cursor', 'prev_cursor', 'per_page', 'has_more'],
            ]);

        $this->assertCount(2, $response->json('data'));

        $rows = collect($response->json('data'))->keyBy('user.id');
        $this->assertSame($organization->creator_id, $rows[$invited->id]['added_by']['id']);
        // The founding admin has no adder: the DB column is nullable even
        // though the contract types added_by as a plain UserSummary.
        $this->assertNull($rows[$organization->creator_id]['added_by']);
    }

    public function test_listing_filters_by_role_and_search(): void
    {
        $organization = $this->organizationWithAdmin();
        $this->organizationMember($organization, OrganizationRole::Instructor, User::factory()->create([
            'first_name' => 'Quỳnh', 'last_name' => 'Nguyễn', 'username' => 'quynhnn',
        ]));
        $this->organizationMember($organization, OrganizationRole::Instructor);
        Sanctum::actingAs($organization->creator);

        $byRole = $this->getJson("/api/v1/organizations/{$organization->id}/members?role=admin")->assertOk();
        $this->assertCount(1, $byRole->json('data'));
        $this->assertSame($organization->creator_id, $byRole->json('data.0.user.id'));

        $bySearch = $this->getJson("/api/v1/organizations/{$organization->id}/members?search=quynh")->assertOk();
        $this->assertCount(1, $bySearch->json('data'));
        $this->assertSame('quynhnn', $bySearch->json('data.0.user.username'));
    }

    public function test_listing_is_readable_by_any_member_but_not_an_outsider(): void
    {
        $organization = $this->organizationWithAdmin();

        Sanctum::actingAs($this->organizationMember($organization));
        $this->getJson("/api/v1/organizations/{$organization->id}/members")->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/organizations/{$organization->id}/members")->assertForbidden();
    }

    public function test_adding_members_counts_added_skipped_and_invited(): void
    {
        Notification::fake();
        $organization = $this->organizationWithAdmin();
        $existing = User::factory()->create();
        $alreadyMember = $this->organizationMember($organization);
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'members' => [
                ['email' => $existing->email, 'role' => 'instructor'],
                ['email' => $alreadyMember->email, 'role' => 'admin'],
                ['email' => 'newcomer@hust.edu.vn', 'role' => 'instructor'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.invited', 1);

        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $existing->id,
            'role' => OrganizationRole::Instructor->value,
            'added_by' => $organization->creator_id,
        ]);
        // Skipped means untouched: the payload asked for admin, the row stays.
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $alreadyMember->id,
            'role' => OrganizationRole::Instructor->value,
        ]);
    }

    public function test_an_unknown_address_is_provisioned_and_invited(): void
    {
        Notification::fake();
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'members' => [['email' => 'newcomer@hust.edu.vn', 'role' => 'instructor']],
        ])->assertOk()->assertJsonPath('data.invited', 1);

        $invitee = User::where('email', 'newcomer@hust.edu.vn')->firstOrFail();
        $this->assertTrue($invitee->is_first_time_register);
        $this->assertNull($invitee->email_verified_at);
        $this->assertSame('newcomer', $invitee->username);
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $invitee->id,
        ]);

        Notification::assertSentTo($invitee, OrganizationInvitation::class);
    }

    public function test_a_derived_username_that_is_taken_gets_a_suffix(): void
    {
        Notification::fake();
        User::factory()->create(['username' => 'newcomer']);
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'members' => [['email' => 'newcomer@hust.edu.vn', 'role' => 'instructor']],
        ])->assertOk();

        $this->assertSame('newcomer-2', User::where('email', 'newcomer@hust.edu.vn')->value('username'));
    }

    public function test_a_repeated_address_in_one_payload_is_added_once(): void
    {
        Notification::fake();
        $organization = $this->organizationWithAdmin();
        $existing = User::factory()->create();
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'members' => [
                ['email' => $existing->email, 'role' => 'instructor'],
                ['email' => $existing->email, 'role' => 'admin'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.skipped', 1);

        $this->assertSame(2, OrganizationMember::where('organization_id', $organization->id)->count());
    }

    public function test_adding_members_validates_the_payload(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'members' => [['email' => 'not-an-email', 'role' => 'student']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['members.0.email', 'members.0.role']);

        $this->postJson("/api/v1/organizations/{$organization->id}/members", ['members' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['members']);
    }

    public function test_adding_members_is_denied_to_an_instructor(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($this->organizationMember($organization));

        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'members' => [['email' => 'newcomer@hust.edu.vn', 'role' => 'instructor']],
        ])->assertForbidden();
    }

    public function test_a_system_admin_may_manage_members_of_any_organization(): void
    {
        Notification::fake();
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs(User::factory()->systemAdmin()->create());

        $this->postJson("/api/v1/organizations/{$organization->id}/members", [
            'members' => [['email' => 'newcomer@hust.edu.vn', 'role' => 'admin']],
        ])->assertOk()->assertJsonPath('data.invited', 1);
    }

    public function test_a_member_role_can_be_changed(): void
    {
        $organization = $this->organizationWithAdmin();
        $instructor = $this->organizationMember($organization);
        $membership = OrganizationMember::where('user_id', $instructor->id)->firstOrFail();
        Sanctum::actingAs($organization->creator);

        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$membership->id}", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', OrganizationRole::Admin->value);

        $this->assertSame(OrganizationRole::Admin, $membership->fresh()?->role);
    }

    public function test_the_last_admin_may_not_be_demoted(): void
    {
        $organization = $this->organizationWithAdmin();
        $this->organizationMember($organization);
        $membership = OrganizationMember::where('user_id', $organization->creator_id)->firstOrFail();
        Sanctum::actingAs($organization->creator);

        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$membership->id}", ['role' => 'instructor'])
            ->assertConflict()
            ->assertJsonPath('code', 'LAST_ADMIN');

        $this->assertSame(OrganizationRole::Admin, $membership->fresh()?->role);
    }

    public function test_an_admin_may_be_demoted_while_another_admin_remains(): void
    {
        $organization = $this->organizationWithAdmin();
        $second = $this->organizationMember($organization, OrganizationRole::Admin);
        $membership = OrganizationMember::where('user_id', $second->id)->firstOrFail();
        Sanctum::actingAs($organization->creator);

        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$membership->id}", ['role' => 'instructor'])
            ->assertOk();
    }

    public function test_a_member_can_be_removed(): void
    {
        $organization = $this->organizationWithAdmin();
        $instructor = $this->organizationMember($organization);
        $membership = OrganizationMember::where('user_id', $instructor->id)->firstOrFail();
        Sanctum::actingAs($organization->creator);

        $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$membership->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('organization_members', ['id' => $membership->id]);
    }

    public function test_the_last_admin_may_not_be_removed(): void
    {
        $organization = $this->organizationWithAdmin();
        $membership = OrganizationMember::where('user_id', $organization->creator_id)->firstOrFail();
        Sanctum::actingAs($organization->creator);

        $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$membership->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'LAST_ADMIN');

        $this->assertDatabaseHas('organization_members', ['id' => $membership->id]);
    }

    public function test_a_membership_of_another_organization_is_not_found(): void
    {
        $organization = $this->organizationWithAdmin();
        $other = $this->organizationWithAdmin();
        $foreign = OrganizationMember::where('organization_id', $other->id)->firstOrFail();
        Sanctum::actingAs($organization->creator);

        $this->putJson("/api/v1/organizations/{$organization->id}/members/{$foreign->id}", ['role' => 'instructor'])
            ->assertNotFound();

        $this->deleteJson("/api/v1/organizations/{$organization->id}/members/{$foreign->id}")
            ->assertNotFound();
    }
}
