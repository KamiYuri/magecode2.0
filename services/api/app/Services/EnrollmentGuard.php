<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SectionRole;
use App\Models\Section;
use App\Models\User;

/**
 * D-51: a student belongs to at most one section of a course in a semester.
 *
 * Application-level by design — the constraint spans section_members →
 * sections → semesters, which no database check can express — so every path
 * that enrolls or promotes someone has to ask here: bulk add, roster import,
 * role change and transfer.
 *
 * Only students are constrained. An instructor commonly teaches L01 and L02,
 * and a TA may assist in both.
 */
class EnrollmentGuard
{
    /** The conflicting section, or null when the enrolment is allowed. */
    public function conflictFor(User $user, Section $section, SectionRole $role): ?Section
    {
        if ($role !== SectionRole::Student) {
            return null;
        }

        // A semester belongs to exactly one course, so "same course, same
        // semester" is just the sibling sections of this one.
        return Section::query()
            ->where('semester_id', $section->semester_id)
            ->whereKeyNot($section->id)
            ->whereHas(
                'members',
                fn ($members) => $members->where('user_id', $user->id)->where('role', SectionRole::Student)
            )
            ->first();
    }
}
