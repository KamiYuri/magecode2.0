<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrganizationMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `OrganizationMember` schema in openapi.yml. `added_by` is emitted as
 * null for rows the database allows to have none — the founding admin and
 * anything seeded — even though the contract types it as a plain UserSummary.
 *
 * @mixin OrganizationMember
 */
class OrganizationMemberResource extends JsonResource
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
