<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SectionRole;
use App\Models\AnalysisProblem;
use App\Models\Problem;
use App\Models\User;
use App\Services\MembershipService;

/**
 * Analysis results carry plagiarism and AI-detection verdicts about named
 * students. TAs are excluded from this surface entirely (openapi.yml roles
 * table); they see submissions and CES results only.
 */
class AnalysisProblemPolicy
{
    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * A batch spans every equivalent problem in the semester, so any
     * instructor teaching in that semester may read it — that is the point of
     * running SIM semester-wide. Redaction (D-05/D-06), not this check, keeps
     * one instructor from reading another section's code.
     */
    public function view(User $user, AnalysisProblem $analysisProblem): bool
    {
        if ($this->memberships->isOrganizationAdmin($user, $analysisProblem->semester->course->organization_id)) {
            return true;
        }

        return $user->sectionMemberships()
            ->where('role', SectionRole::Instructor)
            ->whereHas('section', fn ($query) => $query->where('semester_id', $analysisProblem->semester_id))
            ->exists();
    }

    /** Triggering costs real compute, so it stays with instructors and Org Admins. */
    public function create(User $user, Problem $problem): bool
    {
        return $this->memberships->isSectionInstructor($user, $problem->section_id)
            || $this->memberships->administersSection($user, $problem->section);
    }

    public function cancel(User $user, AnalysisProblem $analysisProblem): bool
    {
        return $this->view($user, $analysisProblem);
    }
}
