<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UploadAvatarRequest;
use App\Models\Organization;
use App\Services\AvatarStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An organization's avatar. B6 deliberately left these out because C1 had not
 * yet landed the MinIO layer.
 *
 * Writing one is administering the organization, so it goes through
 * `OrganizationPolicy::update`; reading is in `AvatarStreamController` with
 * the user avatars, since the two streams are the same code.
 */
class OrganizationAvatarController extends Controller
{
    public function __construct(private readonly AvatarStorageService $avatars) {}

    public function store(UploadAvatarRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('update', $organization);

        $previous = $organization->avatar_path;

        $path = $this->avatars->store('organizations', $organization->id, $request->file('avatar'));
        $organization->forceFill(['avatar_path' => $path])->save();

        $this->avatars->delete($previous);

        return response()->json(['data' => [
            'avatar_url' => route('organizations.avatar', ['organization' => $organization->id]),
        ]]);
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('update', $organization);

        $path = $organization->avatar_path;

        $organization->forceFill(['avatar_path' => null])->save();
        $this->avatars->delete($path);

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
