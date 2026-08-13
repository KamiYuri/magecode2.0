<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Organization` schema in openapi.yml. `avatar_url` is a pre-signed MinIO
 * URL and stays null until the storage integration lands (C1).
 *
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'email' => $this->email,
            'avatar_url' => null,
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            'my_role' => $this->my_role,
            'members_count' => $this->whenCounted('members'),
            'courses_count' => $this->whenCounted('courses'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
