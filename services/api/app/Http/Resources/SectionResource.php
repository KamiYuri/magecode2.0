<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Section` schema in openapi.yml.
 *
 * @mixin Section
 */
class SectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'semester_id' => $this->semester_id,
            'name' => $this->name,
            'description' => $this->description,
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'my_role' => $this->my_role,
            'members_count' => $this->whenCounted('members'),
            'problems_count' => $this->whenCounted('problems'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
