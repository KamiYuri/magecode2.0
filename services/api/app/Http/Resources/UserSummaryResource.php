<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `UserSummary` schema in openapi.yml — the shape every embedded person
 * takes (creator, added_by, a roster row). Deliberately narrower than
 * UserResource: it carries no email and no account state, so embedding a user
 * never widens what the reader learns about them.
 *
 * @mixin User
 */
class UserSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'student_id' => $this->student_id,
            'avatar_url' => null,
        ];
    }
}
