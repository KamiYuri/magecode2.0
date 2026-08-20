<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Profile\ChangePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Requests\Profile\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * B12: the caller's own account. Every operation here is scoped to
 * `$request->user()`, so there is nothing to authorise beyond being signed in.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AvatarStorageService $avatars) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($this->user($request))]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $user->update($request->validated());

        return response()->json(['data' => new UserResource($user->refresh())]);
    }

    public function updatePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $user->forceFill(['password' => Hash::make($request->string('password')->value())])->save();

        // Every other session is signed out, the way a reset does (B4). A
        // password change is what you do when you think someone else has it,
        // and leaving their token working would defeat the point. The current
        // token survives so the person changing it is not logged out of the
        // tab they are looking at -- unless there is no real token behind the
        // request, in which case there is nothing to spare and everything
        // goes (`currentAccessToken()` is a TransientToken under session auth,
        // and it has no key).
        $current = $request->user()?->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $user->tokens()
            ->when($currentId !== null, fn ($tokens) => $tokens->where('id', '!=', $currentId))
            ->delete();

        return response()->json(['message' => 'Password changed.']);
    }

    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $previous = $user->avatar_path;

        $path = $this->avatars->store('users', $user->id, $request->file('avatar'));
        $user->forceFill(['avatar_path' => $path])->save();

        // After the row points at the new object, so a failure here leaves an
        // orphan rather than a row pointing at nothing.
        $this->avatars->delete($previous);

        return response()->json(['data' => ['avatar_url' => UserResource::avatarUrl($user)]]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $path = $user->avatar_path;

        $user->forceFill(['avatar_path' => null])->save();
        $this->avatars->delete($path);

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
