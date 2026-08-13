<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Problem;
use App\Services\ProblemVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Problem` schema in openapi.yml — the shape everyone may read.
 *
 * `is_visible` / `is_submittable` are the same verdict the listing filter
 * applies (D-16 §6.3), computed here so a staff reader sees why a student
 * cannot yet open a problem. `sample_test_cases` carries only `is_visible`
 * rows; the full set lives in ProblemDetailResource, behind a staff check.
 *
 * @mixin Problem
 */
class ProblemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $visibility = app(ProblemVisibilityService::class);

        return [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'bank_problem_id' => $this->bank_problem_id,
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'name' => $this->name,
            'description' => $this->description,
            'difficulty' => $this->difficulty->value,
            'group_label' => $this->group_label,
            'order' => $this->order,
            'max_submissions' => $this->max_submissions,
            'time_limit' => $this->time_limit,
            'memory_limit' => $this->memory_limit,
            'activation_time' => $this->activation_time?->toIso8601String(),
            'lock_time' => $this->lock_time?->toIso8601String(),
            'publish_mode_override' => $this->publish_mode_override?->value,
            'lock_mode_override' => $this->lock_mode_override?->value,
            'is_published' => $this->is_published,
            'is_locked' => $this->is_locked,
            'is_visible' => $visibility->isVisible($this->resource),
            'is_submittable' => $visibility->isSubmittable($this->resource),
            'has_submissions' => ($this->submissions_count ?? 0) > 0,
            'submissions_count' => $this->whenCounted('submissions'),
            'my_submissions_count' => $this->whenCounted('mySubmissions'),
            'my_best_status' => $this->my_best_status,
            'programming_languages' => ProgrammingLanguageResource::collection(
                $this->whenLoaded('programmingLanguages')
            ),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'sample_test_cases' => TestCaseResource::collection($this->whenLoaded('sampleTestCases')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
