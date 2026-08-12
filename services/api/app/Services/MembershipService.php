<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrganizationRole;
use App\Enums\SectionRole;
use App\Models\Organization;
use App\Models\Section;
use App\Models\User;

/**
 * Resolves what a user is *in a given place*. Roles are per-membership, never
 * per-user: the same person can teach L01 and be enrolled in L02 (D-04), so
 * there is deliberately no "current role" accessor.
 *
 * Results are memoised per (user, scope) for the life of the request because a
 * single authorization pass asks the same question many times — once per
 * policy check, and once per row when filtering a listing.
 */
class MembershipService
{
    /** @var array<string, SectionRole|null> */
    private array $sectionRoles = [];

    /** @var array<string, OrganizationRole|null> */
    private array $organizationRoles = [];

    public function sectionRole(User $user, Section|int $section): ?SectionRole
    {
        $sectionId = $section instanceof Section ? $section->id : $section;
        $key = "{$user->id}:{$sectionId}";

        return $this->sectionRoles[$key] ??= $user->sectionMemberships()
            ->where('section_id', $sectionId)
            ->value('role');
    }

    public function organizationRole(User $user, Organization|int $organization): ?OrganizationRole
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;
        $key = "{$user->id}:{$organizationId}";

        return $this->organizationRoles[$key] ??= $user->organizationMemberships()
            ->where('organization_id', $organizationId)
            ->value('role');
    }

    public function isOrganizationAdmin(User $user, Organization|int $organization): bool
    {
        return $this->organizationRole($user, $organization) === OrganizationRole::Admin;
    }

    public function isOrganizationMember(User $user, Organization|int $organization): bool
    {
        return $this->organizationRole($user, $organization) !== null;
    }

    /** Instructor or TA — the roles allowed to see other people's work in a section. */
    public function isSectionStaff(User $user, Section|int $section): bool
    {
        return in_array(
            $this->sectionRole($user, $section),
            [SectionRole::Instructor, SectionRole::Ta],
            true
        );
    }

    public function isSectionInstructor(User $user, Section|int $section): bool
    {
        return $this->sectionRole($user, $section) === SectionRole::Instructor;
    }

    public function isSectionMember(User $user, Section|int $section): bool
    {
        return $this->sectionRole($user, $section) !== null;
    }

    /**
     * True when the user administers the organization that ultimately owns
     * this section. Org Admin is the only role with cross-section visibility.
     */
    public function administersSection(User $user, Section $section): bool
    {
        return $this->isOrganizationAdmin($user, $section->semester->course->organization_id);
    }
}
