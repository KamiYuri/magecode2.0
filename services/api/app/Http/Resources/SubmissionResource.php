<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Submission` schema in openapi.yml.
 *
 * `file_path` is deliberately absent: the object key is an internal detail and
 * a client that knew it would still have no way to fetch the object (D-85).
 *
 * @mixin Submission
 */
class SubmissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'problem_id' => $this->problem_id,
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'programming_language' => new ProgrammingLanguageResource($this->whenLoaded('programmingLanguage')),
            'file_name' => $this->file_name,
            'execution_status' => $this->execution_status,
            'testcases_passed' => $this->testcases_passed,
            'testcases_total' => $this->testcases_total,
            'is_outdated' => $this->is_outdated,
            'created_at' => $this->created_at,
        ];
    }
}
