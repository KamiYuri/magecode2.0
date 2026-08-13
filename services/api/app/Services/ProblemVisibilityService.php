<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PublishMode;
use App\Models\Problem;
use App\Models\Semester;

/**
 * D-16 §6.3: whether a problem is open, and whether it still accepts work.
 *
 * The semester owns the mode; a problem may override it only while the
 * semester permits (`allow_*_override`). In `auto` mode the clock decides, in
 * `manual` mode the flag does — and the other one is ignored entirely, so a
 * stale `is_published` cannot leak a problem whose semester runs on schedule.
 *
 * A NULL time in auto mode means the gate was never set: not yet open for
 * activation, never closing for lock (recorded 2026-08-13).
 */
class ProblemVisibilityService
{
    public function effectivePublishMode(Problem $problem): PublishMode
    {
        $semester = $this->semesterOf($problem);

        return $semester->allow_publish_override
            ? ($problem->publish_mode_override ?? $semester->publish_mode)
            : $semester->publish_mode;
    }

    public function effectiveLockMode(Problem $problem): PublishMode
    {
        $semester = $this->semesterOf($problem);

        return $semester->allow_lock_override
            ? ($problem->lock_mode_override ?? $semester->lock_mode)
            : $semester->lock_mode;
    }

    public function isVisible(Problem $problem): bool
    {
        return $this->effectivePublishMode($problem) === PublishMode::Auto
            ? $problem->activation_time !== null && $problem->activation_time->isPast()
            : $problem->is_published;
    }

    public function isLocked(Problem $problem): bool
    {
        return $this->effectiveLockMode($problem) === PublishMode::Auto
            ? $problem->lock_time !== null && $problem->lock_time->isPast()
            : $problem->is_locked;
    }

    /** What a student is really asking: may I work on this right now? */
    public function isSubmittable(Problem $problem): bool
    {
        return $this->isVisible($problem) && ! $this->isLocked($problem);
    }

    /**
     * Whether this user may flip the manual flag. The semester can pin the
     * mode for everyone below the Org Admin — a final exam that must open or
     * close at one time across every section (D-16).
     */
    public function mayOverridePublish(Semester $semester, bool $isOrganizationAdmin): bool
    {
        return $isOrganizationAdmin || $semester->allow_publish_override;
    }

    public function mayOverrideLock(Semester $semester, bool $isOrganizationAdmin): bool
    {
        return $isOrganizationAdmin || $semester->allow_lock_override;
    }

    private function semesterOf(Problem $problem): Semester
    {
        return $problem->section->semester;
    }
}
