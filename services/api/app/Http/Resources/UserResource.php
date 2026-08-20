<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `User` schema in openapi.yml. `avatar_url` points at
 * `GET /users/{user_id}/avatar`, which the api streams — the bucket is never
 * browser-reachable (v3 §7, 2026-08-14) — and is null when nothing is set, so
 * the client draws initials instead.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** Null when unset: the route would 404, and the client already knows how to draw initials. */
    public static function avatarUrl(User $user): ?string
    {
        return $user->avatar_path === null
            ? null
            : route('users.avatar', ['user' => $user->id]);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'student_id' => $this->student_id,
            'avatar_url' => self::avatarUrl($this->resource),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'is_first_time_register' => $this->is_first_time_register,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
