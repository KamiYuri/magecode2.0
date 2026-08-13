<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Problem;
use App\Models\Section;
use App\Models\User;
use App\Services\MembershipService;

class ProblemPolicy
{
    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * Membership in the owning section. Whether a *student* may see this
     * particular problem right now also depends on publish state; that is a
     * query filter, not an authorization question, so it lives in the reads.
     */
    public function view(User $user, Problem $problem): bool
    {
        return $this->memberships->isSectionMember($user, $problem->section_id)
            || $this->memberships->administersSection($user, $problem->section);
    }

    /**
     * Listing is open to the whole section; which rows come back depends on
     * publish state, which the query decides.
     */
    public function viewAny(User $user, Section $section): bool
    {
        return $this->memberships->isSectionMember($user, $section)
            || $this->memberships->administersSection($user, $section);
    }

    public function create(User $user, Section $section): bool
    {
        return $this->memberships->isSectionInstructor($user, $section)
            || $this->memberships->administersSection($user, $section);
    }

    public function update(User $user, Problem $problem): bool
    {
        return $this->memberships->isSectionInstructor($user, $problem->section_id)
            || $this->memberships->administersSection($user, $problem->section);
    }

    public function delete(User $user, Problem $problem): bool
    {
        return $this->update($user, $problem);
    }

    /**
     * Full test cases, including hidden ones. Staff only — a student who could
     * read these would know every expected output.
     */
    public function viewTestCases(User $user, Problem $problem): bool
    {
        return $this->memberships->isSectionStaff($user, $problem->section_id)
            || $this->memberships->administersSection($user, $problem->section);
    }

    public function updateTestCases(User $user, Problem $problem): bool
    {
        return $this->update($user, $problem);
    }
}
