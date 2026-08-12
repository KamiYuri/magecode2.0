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
}
