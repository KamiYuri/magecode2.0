<?php

declare(strict_types=1);

namespace App\Http\Resources\Analysis;

use App\Http\Resources\UserSummaryResource;
use App\Models\SimilarityResult;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `SimilarityResult` schema: who matched whom, how closely, and whether
 * the pair crosses a section boundary.
 *
 * No source code here — every field is one an instructor teaching anywhere in
 * the semester may read (v3 §7, 2026-08-18). The code lives on the detail
 * resource, where it is redacted per side.
 *
 * @mixin SimilarityResult
 */
class SimilarityResultResource extends JsonResource
{
    public function __construct(SimilarityResult $resource, protected readonly AnalysisReadContext $context)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'submission_a' => $this->side($this->submissionA),
            'submission_b' => $this->side($this->submissionB),
            'similarity' => (float) $this->similarity,
            'longest_fragment' => $this->longest_fragment,
            'total_overlap' => $this->total_overlap,
            'match_type' => $this->match_type,
            'flagged' => $this->context->flagsSimilarity((float) $this->similarity),
        ];
    }

    /** @return array<string, mixed> */
    protected function side(?Submission $submission): array
    {
        $section = $submission?->problem->section;

        return [
            'id' => $submission?->id,
            'student' => new UserSummaryResource($submission?->creator),
            'section_id' => $section?->id,
            'section_name' => $section?->name,
        ];
    }
}
