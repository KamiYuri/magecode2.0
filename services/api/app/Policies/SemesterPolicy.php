<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Course;
use App\Models\Semester;
use App\Models\User;
use App\Services\MembershipService;

class SemesterPolicy
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function view(User $user, Semester $semester): bool
    {
        return app(CoursePolicy::class)->view($user, $semester->course);
    }

    /** Whoever may read the course may list its semesters. */
    public function viewAny(User $user, Course $course): bool
    {
        return app(CoursePolicy::class)->view($user, $course);
    }

    public function create(User $user, Course $course): bool
    {
        return $this->memberships->isOrganizationAdmin($user, $course->organization_id);
    }

    /**
     * Semesters carry the publish/lock policy and the analysis thresholds
     * (D-16, D-62), which govern every section beneath them — so only the Org
     * Admin may change one, never a section instructor.
     */
    public function update(User $user, Semester $semester): bool
    {
        return $this->memberships->isOrganizationAdmin($user, $semester->course->organization_id);
    }

    public function delete(User $user, Semester $semester): bool
    {
        return $this->update($user, $semester);
    }
}
