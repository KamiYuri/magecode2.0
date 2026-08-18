<?php

declare(strict_types=1);

use App\Models\Section;
use App\Models\Submission;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Support\Facades\Broadcast;

/*
| Private channel authorisation (U-8). Returning false is a 403 on
| /api/broadcasting/auth, which is what a client sees when it subscribes to a
| channel it may not hear.
*/

/**
 * The submission's creator and nobody else (v3 §7, 2026-08-14).
 *
 * `openapi.yml` says "Submission creator" for both events on this channel and
 * outranks `roadmap.md`'s "ownership/staff" (session-guide §1). Deliberately
 * narrower than `SubmissionPolicy::view`: staff read the same submission,
 * source included, through `GET /submissions/{id}`, so this decides who gets a
 * live push rather than who may see the work.
 */
Broadcast::channel('submission.{submissionId}', function (User $user, int $submissionId): bool {
    return Submission::query()
        ->whereKey($submissionId)
        ->where('creator_id', $user->id)
        ->exists();
});

/**
 * Analysis progress and completion for one section (U-8).
 *
 * Instructors of the section, plus the Org Admin above it. **TAs are
 * excluded**: `openapi.yml` says "Section instructors", and the 2026-08-12
 * amendment already removed a TA's read access to analysis results — a live
 * push must never be wider than the HTTP surface it mirrors.
 */
Broadcast::channel('section.{sectionId}', function (User $user, int $sectionId): bool {
    $section = Section::find($sectionId);

    if ($section === null) {
        return false;
    }

    $memberships = app(MembershipService::class);

    return $memberships->isSectionInstructor($user, $section)
        || $memberships->administersSection($user, $section);
});
