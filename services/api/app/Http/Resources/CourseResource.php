<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Course` schema in openapi.yml.
 *
 * @mixin Course
 */
class CourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'require_bank_approval' => $this->require_bank_approval,
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'semesters_count' => $this->whenCounted('semesters'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
