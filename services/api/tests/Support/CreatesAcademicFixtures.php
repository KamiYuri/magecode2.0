<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\OrganizationRole;
use App\Enums\SectionRole;
use App\Models\Course;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Section;
use App\Models\SectionMember;
use App\Models\Semester;
use App\Models\User;

/**
 * Builders for the Org → Course → Semester → Section chain, so a CRUD test can
 * state the one relationship it cares about instead of assembling five rows.
 *
 * Deliberately smaller than AuthorizationMatrixTest's fixed world: that one is
 * a security document and stays self-contained.
 */
trait CreatesAcademicFixtures
{
    protected function organizationWithAdmin(?User $admin = null): Organization
    {
        $admin ??= User::factory()->create();
        $organization = Organization::factory()->create(['creator_id' => $admin->id]);

        OrganizationMember::factory()->admin()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
        ]);

        return $organization;
    }

    protected function organizationMember(
        Organization $organization,
        OrganizationRole $role = OrganizationRole::Instructor,
        ?User $user = null,
    ): User {
        $user ??= User::factory()->create();

        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    protected function courseIn(Organization $organization, array $attributes = []): Course
    {
        return Course::factory()->create($attributes + [
            'organization_id' => $organization->id,
            'creator_id' => $organization->creator_id,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function semesterIn(Course $course, array $attributes = []): Semester
    {
        return Semester::factory()->create($attributes + [
            'course_id' => $course->id,
            'creator_id' => $course->creator_id,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function sectionIn(Semester $semester, array $attributes = []): Section
    {
        return Section::factory()->create($attributes + [
            'semester_id' => $semester->id,
            'creator_id' => $semester->creator_id,
        ]);
    }

    protected function sectionMember(
        Section $section,
        SectionRole $role = SectionRole::Student,
        ?User $user = null,
    ): User {
        $user ??= User::factory()->create();

        SectionMember::factory()->create([
            'section_id' => $section->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }
}
