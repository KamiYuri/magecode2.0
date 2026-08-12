<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SectionRole;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use App\Services\MembershipService;

class SubmissionPolicy
{
    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * Own work, or staff of the section it was written in. A classmate is a
     * section member but still gets nothing — membership alone is not a
     * licence to read other students' code.
     */
    public function view(User $user, Submission $submission): bool
    {
        if ($submission->creator_id === $user->id) {
            return true;
        }

        $section = $submission->problem->section;

        return $this->memberships->isSectionStaff($user, $section)
            || $this->memberships->administersSection($user, $section);
    }

    /** Only enrolled students submit; staff do not submit to their own class. */
    public function create(User $user, Problem $problem): bool
    {
        return $this->memberships->sectionRole($user, $problem->section_id) === SectionRole::Student;
    }
}
