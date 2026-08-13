<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Problem;
use App\Services\ProblemVisibilityService;
use Illuminate\Http\Request;

/**
 * The `ProblemDetail` schema in openapi.yml: everything a staff reader may
 * see, including the hidden test cases. Never returned to a student.
 *
 * `edit_rules` tells the client which fields are still editable, so the form
 * can disable them instead of discovering PROBLEM_LOCKED on submit.
 *
 * @mixin Problem
 */
class ProblemDetailResource extends ProblemResource
{
    public function __construct(Problem $resource, private readonly bool $isOrganizationAdmin = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $visibility = app(ProblemVisibilityService::class);
        $semester = $this->section->semester;
        $locked = $visibility->isLocked($this->resource);

        return parent::toArray($request) + [
            'test_cases' => TestCaseResource::collection($this->whenLoaded('testCases')),
            'manual_match_group_id' => $this->manual_match_group_id,
            'testcases_updated_at' => $this->testcases_updated_at?->toIso8601String(),
            'edit_rules' => [
                'can_edit_description' => true,
                'can_edit_test_cases' => ! $locked,
                'can_edit_limits' => ! $locked,
                'can_edit_languages' => ! $locked,
                'can_publish' => $visibility->mayOverridePublish($semester, $this->isOrganizationAdmin),
                'can_lock' => $visibility->mayOverrideLock($semester, $this->isOrganizationAdmin),
            ],
        ];
    }
}
