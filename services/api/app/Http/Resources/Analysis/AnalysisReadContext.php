<?php

declare(strict_types=1);

namespace App\Http\Resources\Analysis;

use App\Models\AnalysisProblem;
use App\Models\User;
use App\Services\Analysis\AnalysisVisibilityService;

/**
 * What every analysis result resource needs to know and cannot work out for
 * itself: who is reading, which batch this is, and the semester's alert
 * thresholds (D-62).
 *
 * Passing it explicitly keeps the redaction decision out of the resources —
 * they ask, they do not compute — so `AnalysisVisibilityService` stays the one
 * place the two-tier rule lives, and the controller uses the same answer to
 * decide which objects are worth fetching at all.
 */
final readonly class AnalysisReadContext
{
    public function __construct(
        public User $viewer,
        public AnalysisProblem $batch,
        private AnalysisVisibilityService $visibility,
        public float $similarityThreshold,
        public float $aiDetectionThreshold,
    ) {}

    public static function for(
        User $viewer,
        AnalysisProblem $batch,
        AnalysisVisibilityService $visibility,
    ): self {
        $semester = $batch->semester;

        return new self(
            $viewer,
            $batch,
            $visibility,
            (float) $semester->similarity_threshold,
            (float) $semester->ai_detection_threshold,
        );
    }

    /** D-05/D-06, asked once per side of a pair. */
    public function maySeeSourceOf(?int $sectionId): bool
    {
        return $sectionId !== null
            && $this->visibility->mayReadSourceOf($this->viewer, $sectionId, $this->batch);
    }

    /** D-62: the semester's own bar, not a constant. */
    public function flagsSimilarity(float $similarity): bool
    {
        return $similarity >= $this->similarityThreshold;
    }

    public function flagsAiDetection(float $probability): bool
    {
        return $probability >= $this->aiDetectionThreshold;
    }
}
