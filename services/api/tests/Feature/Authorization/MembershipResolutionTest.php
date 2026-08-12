<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\OrganizationRole;
use App\Enums\SectionRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Section;
use App\Models\SectionMember;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipResolutionTest extends TestCase
{
    use RefreshDatabase;

    private MembershipService $memberships;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberships = app(MembershipService::class);
    }

    public function test_a_user_holds_different_roles_in_different_sections(): void
    {
        // The same person teaches one class and takes another. Caching a
        // single "current role" per user would leak across both.
        $user = User::factory()->create();
        $teaches = Section::factory()->create();
        $attends = Section::factory()->create();
        SectionMember::factory()->create(['section_id' => $teaches->id, 'user_id' => $user->id, 'role' => SectionRole::Instructor]);
        SectionMember::factory()->create(['section_id' => $attends->id, 'user_id' => $user->id, 'role' => SectionRole::Student]);

        $this->assertSame(SectionRole::Instructor, $this->memberships->sectionRole($user, $teaches));
        $this->assertSame(SectionRole::Student, $this->memberships->sectionRole($user, $attends));
    }

    public function test_a_non_member_has_no_section_role(): void
    {
        $this->assertNull($this->memberships->sectionRole(User::factory()->create(), Section::factory()->create()));
    }

    public function test_organization_role_is_resolved_independently_of_sections(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => OrganizationRole::Instructor,
        ]);

        $this->assertSame(OrganizationRole::Instructor, $this->memberships->organizationRole($user, $organization));
        $this->assertFalse($this->memberships->isOrganizationAdmin($user, $organization));
    }

    public function test_org_admin_is_recognised_across_the_whole_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->admin()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
        ]);
        $section = Section::factory()->create();

        $this->assertTrue($this->memberships->isOrganizationAdmin($user, $organization));
        // Admin of a different organization gains nothing here.
        $this->assertFalse($this->memberships->isOrganizationAdmin($user, $section->semester->course->organization));
    }

    public function test_section_staff_covers_instructor_and_ta_but_not_student(): void
    {
        $section = Section::factory()->create();
        $instructor = User::factory()->create();
        $ta = User::factory()->create();
        $student = User::factory()->student()->create();
        SectionMember::factory()->create(['section_id' => $section->id, 'user_id' => $instructor->id, 'role' => SectionRole::Instructor]);
        SectionMember::factory()->create(['section_id' => $section->id, 'user_id' => $ta->id, 'role' => SectionRole::Ta]);
        SectionMember::factory()->create(['section_id' => $section->id, 'user_id' => $student->id, 'role' => SectionRole::Student]);

        $this->assertTrue($this->memberships->isSectionStaff($instructor, $section));
        $this->assertTrue($this->memberships->isSectionStaff($ta, $section));
        $this->assertFalse($this->memberships->isSectionStaff($student, $section));

        $this->assertTrue($this->memberships->isSectionInstructor($instructor, $section));
        $this->assertFalse($this->memberships->isSectionInstructor($ta, $section));
    }

    public function test_resolution_does_not_grow_queries_with_repeated_lookups(): void
    {
        $user = User::factory()->create();
        $section = Section::factory()->create();
        SectionMember::factory()->create(['section_id' => $section->id, 'user_id' => $user->id, 'role' => SectionRole::Instructor]);

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            $this->memberships->sectionRole($user, $section);
        }
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        // Policies ask the same question repeatedly while authorizing one
        // request; re-querying each time turns every listing into an N+1.
        $this->assertSame(1, $queries, 'Repeated resolution for the same user+section must hit the database once');
    }
}
