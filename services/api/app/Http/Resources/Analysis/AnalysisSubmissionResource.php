<?php

declare(strict_types=1);

namespace App\Http\Resources\Analysis;

use App\Http\Resources\SubmissionResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\AnalysisSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One submission's place in a batch: the three service statuses, the AI verdict
 * if one arrived, and how many vulnerabilities were found.
 *
 * `source_code` is opt-in through `?include=source_code` **and** subject to the
 * same redaction as a similarity pair — openapi says so in as many words: the
 * include never bypasses D-05/D-06.
 *
 * @mixin AnalysisSubmission
 */
class AnalysisSubmissionResource extends JsonResource
{
    public function __construct(
        AnalysisSubmission $resource,
        private readonly AnalysisReadContext $context,
        private readonly bool $withSubmission,
        private readonly bool $withSource,
        private readonly ?string $sourceCode,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $section = $this->submission->problem->section;
        $probability = $this->aiDetectionResult?->probability;

        $payload = [
            'id' => $this->id,
            'student' => new UserSummaryResource($this->submission->creator),
            'section_id' => $section->id,
            'section_name' => $section->name,
            'plagiarism_status' => $this->plagiarism_status,
            'ai_detection_status' => $this->ai_detection_status,
            'vuln_scan_status' => $this->vuln_scan_status,
            'ai_detection_probability' => $probability === null ? null : (float) $probability,
            'ai_detection_flagged' => $probability === null
                ? null
                : $this->context->flagsAiDetection((float) $probability),
            'vulnerability_count' => $this->vulnerability_results_count,
        ];

        if ($this->withSubmission) {
            $payload['submission'] = new SubmissionResource($this->submission);
        }

        if ($this->withSource) {
            // Fetched by the controller only when the context allows it; the
            // null here is what holds if that ever stops being true.
            $payload['source_code'] = $this->context->maySeeSourceOf($section->id) ? $this->sourceCode : null;
        }

        return $payload;
    }
}
