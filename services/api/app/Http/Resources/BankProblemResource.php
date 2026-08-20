<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BankProblem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `BankProblem` schema in openapi.yml. `test_cases` is included only when
 * the relation was loaded, which is the detail view -- a listing of fifty
 * entries has no business carrying every expected output.
 *
 * @mixin BankProblem
 */
class BankProblemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'author' => new UserSummaryResource($this->whenLoaded('author')),
            'original_id' => $this->original_id,
            'name' => $this->name,
            'description' => $this->description,
            'difficulty' => $this->difficulty->value,
            'time_limit' => $this->time_limit,
            'memory_limit' => $this->memory_limit,
            'version' => $this->version,
            'status' => $this->status->value,
            'programming_languages' => ProgrammingLanguageResource::collection(
                $this->whenLoaded('programmingLanguages')
            ),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'test_cases' => TestCaseResource::collection($this->whenLoaded('testCases')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
