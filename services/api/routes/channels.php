<?php

declare(strict_types=1);

use App\Models\Submission;
use App\Models\User;
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
