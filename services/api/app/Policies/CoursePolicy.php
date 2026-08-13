<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Course;
use App\Models\Organization;
use App\Models\User;
use App\Services\MembershipService;

class CoursePolicy
{
    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * Organization staff see the course directly; students and TAs reach it
     * through a section they belong to, so enrolment in any section of the
     * course is enough to read it.
     */
    public function view(User $user, Course $course): bool
    {
        return $this->memberships->isOrganizationMember($user, $course->organization_id)
            || $this->belongsToASectionOf($user, $course);
    }

    /**
     * Listing an organization's courses follows the same two routes in as
     * reading one; which courses come back is the query's business.
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->memberships->isOrganizationMember($user, $organization)
            || $this->belongsToASectionIn($user, $organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->memberships->isOrganizationAdmin($user, $organization);
    }

    public function update(User $user, Course $course): bool
    {
        return $this->memberships->isOrganizationAdmin($user, $course->organization_id);
    }

    public function delete(User $user, Course $course): bool
    {
        return $this->update($user, $course);
    }

    private function belongsToASectionOf(User $user, Course $course): bool
    {
        return $user->sectionMemberships()
            ->whereHas('section.semester', fn ($query) => $query->where('course_id', $course->id))
            ->exists();
    }

    private function belongsToASectionIn(User $user, Organization $organization): bool
    {
        return $user->sectionMemberships()
            ->whereHas(
                'section.semester.course',
                fn ($query) => $query->where('organization_id', $organization->id)
            )
            ->exists();
    }
}
