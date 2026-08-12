<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BankProblem;
use App\Models\Course;
use App\Models\User;
use App\Services\MembershipService;

/**
 * The bank belongs to the Course and is staff-only: TAs and students have no
 * access at all (technical-design §3.3).
 */
class BankProblemPolicy
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function view(User $user, BankProblem $bankProblem): bool
    {
        return $this->memberships->isOrganizationMember($user, $bankProblem->course->organization_id);
    }

    public function viewAny(User $user, Course $course): bool
    {
        return $this->memberships->isOrganizationMember($user, $course->organization_id);
    }

    public function create(User $user, Course $course): bool
    {
        return $this->memberships->isOrganizationMember($user, $course->organization_id);
    }

    /** Author or Org Admin (decisions-v1 §4.3) — not every instructor. */
    public function update(User $user, BankProblem $bankProblem): bool
    {
        return $bankProblem->author_id === $user->id
            || $this->memberships->isOrganizationAdmin($user, $bankProblem->course->organization_id);
    }

    public function delete(User $user, BankProblem $bankProblem): bool
    {
        return $this->update($user, $bankProblem);
    }

    /** The approval gate exists to check other people's work (D-25). */
    public function approve(User $user, BankProblem $bankProblem): bool
    {
        return $this->memberships->isOrganizationAdmin($user, $bankProblem->course->organization_id);
    }
}
