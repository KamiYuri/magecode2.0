<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Semester;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Semester` schema in openapi.yml, including the D-16 publish/lock policy
 * and the D-62 thresholds.
 *
 * @mixin Semester
 */
class SemesterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'name' => $this->name,
            'description' => $this->description,
            'publish_mode' => $this->publish_mode->value,
            'lock_mode' => $this->lock_mode->value,
            'allow_publish_override' => $this->allow_publish_override,
            'allow_lock_override' => $this->allow_lock_override,
            // The decimal:2 cast hands back a string; the contract says number.
            'similarity_threshold' => (float) $this->similarity_threshold,
            'ai_detection_threshold' => (float) $this->ai_detection_threshold,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'sections_count' => $this->whenCounted('sections'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
