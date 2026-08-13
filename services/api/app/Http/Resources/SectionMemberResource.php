<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SectionMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `SectionMember` schema in openapi.yml. `added_by` is null for rows the
 * database allows to have none — seeded fixtures and demo data.
 *
 * @mixin SectionMember
 */
class SectionMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'role' => $this->role->value,
            'added_by' => $this->addedBy === null ? null : new UserSummaryResource($this->addedBy),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
