<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Models\AnalysisProblem;
use App\Models\User;
use App\Services\MembershipService;

/**
 * The two-tier rule of D-05/D-06, in one place.
 *
 * A batch is semester-wide, so `AnalysisProblemPolicy::view` admits every
 * instructor teaching in that semester — which means a similarity pair can be
 * read by someone who teaches neither of its two sections. The decisions
 * define the tiers for a viewer who owns one side and say nothing about that
 * case, so it is settled here (v3 §7, 2026-08-18): **rows stay visible, source
 * code does not**.
 *
 * | Viewer                                  | May read source of |
 * |-----------------------------------------|--------------------|
 * | Org Admin of the batch's organization   | every side         |
 * | Instructor of the side's section        | that side          |
 * | Instructor teaching elsewhere in scope  | neither side       |
 *
 * TAs and students never reach this — the policy refuses them the batch.
 *
 * Every surface that can emit source asks this class rather than deciding for
 * itself, so there is exactly one place for G3's security pass to audit.
 */
class AnalysisVisibilityService
{
    /** @var array<string, bool> viewer+section decisions already made this request */
    private array $decisions = [];

    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * May this viewer read the source of a submission written in this section?
     *
     * The batch decides which organization "admin" means: an Org Admin of some
     * other organization is an ordinary stranger here.
     */
    public function mayReadSourceOf(User $viewer, int $sectionId, AnalysisProblem $batch): bool
    {
        // A listing asks this once per row over the same handful of sections.
        $key = $viewer->id.':'.$sectionId.':'.$batch->id;

        return $this->decisions[$key] ??= $this->decide($viewer, $sectionId, $batch);
    }

    /** The organization an Org Admin must administer to see everything in this batch. */
    public function administersBatch(User $viewer, AnalysisProblem $batch): bool
    {
        return $this->memberships->isOrganizationAdmin(
            $viewer,
            $batch->semester->course->organization_id,
        );
    }

    private function decide(User $viewer, int $sectionId, AnalysisProblem $batch): bool
    {
        if ($this->administersBatch($viewer, $batch)) {
            return true;
        }

        return $this->memberships->isSectionInstructor($viewer, $sectionId);
    }
}
