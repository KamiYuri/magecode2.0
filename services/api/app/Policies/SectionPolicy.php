<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Section;
use App\Models\Semester;
use App\Models\User;
use App\Services\MembershipService;

class SectionPolicy
{
    public function __construct(private readonly MembershipService $memberships) {}

    /** The isolation boundary (D-04): membership, or the Org Admin above it. */
    public function view(User $user, Section $section): bool
    {
        return $this->memberships->isSectionMember($user, $section)
            || $this->memberships->administersSection($user, $section);
    }

    /**
     * The gate only asks whether the semester is readable at all; D-04 is
     * enforced on the rows, where a section instructor sees L01 and not L02.
     */
    public function viewAny(User $user, Semester $semester): bool
    {
        return app(SemesterPolicy::class)->view($user, $semester);
    }

    public function create(User $user, Semester $semester): bool
    {
        return $this->memberships->isOrganizationAdmin($user, $semester->course->organization_id);
    }

    public function update(User $user, Section $section): bool
    {
        return $this->memberships->administersSection($user, $section);
    }

    public function delete(User $user, Section $section): bool
    {
        return $this->update($user, $section);
    }

    /** Enrolling or removing students; the roster is staff territory. */
    public function manageMembers(User $user, Section $section): bool
    {
        return $this->memberships->isSectionInstructor($user, $section)
            || $this->memberships->administersSection($user, $section);
    }
}
