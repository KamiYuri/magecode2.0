<?php

declare(strict_types=1);

namespace App\Http\Resources\Analysis;

use App\Http\Resources\UserSummaryResource;
use App\Models\AiDetectionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI-detection verdict. `flagged` is measured against the semester's own
 * `ai_detection_threshold` (D-62), not a constant — two courses may draw the
 * line differently and the UI colours the row from this field.
 *
 * @mixin AiDetectionResult
 */
class AiDetectionResultResource extends JsonResource
{
    public function __construct(AiDetectionResult $resource, private readonly AnalysisReadContext $context)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $submission = $this->analysisSubmission->submission;

        return [
            'id' => $this->id,
            'analysis_submission_id' => $this->analysis_submission_id,
            'student' => new UserSummaryResource($submission->creator),
            'section_name' => $submission->problem->section->name,
            'probability' => (float) $this->probability,
            'flagged' => $this->context->flagsAiDetection((float) $this->probability),
        ];
    }
}
