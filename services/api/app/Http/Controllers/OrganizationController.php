<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Http\Responses\CursorPage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    /** Membership, not the policy, decides what a caller sees here. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);

        /** @var User $user */
        $user = $request->user();

        $page = Organization::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
            ->withMyRole($user)
            ->with('creator')
            ->withCount(['members', 'courses'])
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::json($page, OrganizationResource::class);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $this->authorize('create', Organization::class);

        /** @var User $user */
        $user = $request->user();

        $organization = DB::transaction(function () use ($request, $user): Organization {
            $organization = Organization::create($request->validated() + ['creator_id' => $user->id]);

            // Creation is System-Admin-only while management needs an Org
            // Admin row, so without this the organization would be born
            // unmanageable (decisions-v3 §7, 2026-08-13).
            $organization->members()->create([
                'user_id' => $user->id,
                'role' => OrganizationRole::Admin,
                'added_by' => $user->id,
                'created_at' => now(),
            ]);

            return $organization;
        });

        return response()->json(
            ['data' => new OrganizationResource($this->hydrate($organization, $user))],
            JsonResponse::HTTP_CREATED
        );
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => new OrganizationResource($this->hydrate($organization, $user))]);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('update', $organization);

        $organization->update($request->validated());

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => new OrganizationResource($this->hydrate($organization, $user))]);
    }

    /** Single-resource responses carry the same counts and role as list rows. */
    private function hydrate(Organization $organization, User $user): Organization
    {
        return Organization::query()
            ->whereKey($organization->id)
            ->withMyRole($user)
            ->with('creator')
            ->withCount(['members', 'courses'])
            ->firstOrFail();
    }
}
