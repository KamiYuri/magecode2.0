<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Course;
use App\Models\Tag;
use App\Models\User;
use App\Services\MembershipService;

/** Tags are course-scoped metadata (D-15); any organization staff may curate them. */
class TagPolicy
{
    public function __construct(private readonly MembershipService $memberships) {}

    /** Listing a course's vocabulary is reading the course. */
    public function viewAny(User $user, Course $course): bool
    {
        return app(CoursePolicy::class)->view($user, $course);
    }

    public function view(User $user, Tag $tag): bool
    {
        return app(CoursePolicy::class)->view($user, $tag->course);
    }

    public function create(User $user, Course $course): bool
    {
        return $this->memberships->isOrganizationMember($user, $course->organization_id);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $this->memberships->isOrganizationMember($user, $tag->course->organization_id);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $this->update($user, $tag);
    }
}
