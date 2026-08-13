<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Http\Requests\Section\StoreSectionRequest;
use App\Http\Requests\Section\UpdateSectionRequest;
use App\Http\Resources\SectionResource;
use App\Models\Section;
use App\Models\Semester;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * The D-04 boundary in list form: only the Org Admin sees the whole
     * semester; everyone else sees the sections they belong to. Returned as a
     * bare collection — openapi declares no pagination for this operation.
     */
    public function index(Request $request, Semester $semester): JsonResponse
    {
        $this->authorize('viewAny', [Section::class, $semester]);

        /** @var User $user */
        $user = $request->user();
        $administers = $this->memberships->isOrganizationAdmin($user, $semester->course->organization_id);

        $sections = $semester->sections()
            ->unless($administers, fn (Builder $query) => $query->whereHas(
                'members',
                fn (Builder $members) => $members->where('user_id', $user->id)
            ))
            ->withMyRole($user)
            ->with('creator')
            ->withCount(['members', 'problems'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => SectionResource::collection($sections)]);
    }

    public function store(StoreSectionRequest $request, Semester $semester): JsonResponse
    {
        $this->authorize('create', [Section::class, $semester]);

        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated() + ['creator_id' => $user->id];

        if ($semester->sections()->where('name', $attributes['name'])->exists()) {
            throw ConflictException::duplicateSectionName();
        }

        try {
            $section = $semester->sections()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            throw ConflictException::duplicateSectionName();
        }

        return response()->json(
            ['data' => new SectionResource($this->hydrate($section, $user))],
            JsonResponse::HTTP_CREATED
        );
    }

    public function show(Request $request, Section $section): JsonResponse
    {
        $this->authorize('view', $section);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => new SectionResource($this->hydrate($section, $user))]);
    }

    public function update(UpdateSectionRequest $request, Section $section): JsonResponse
    {
        $this->authorize('update', $section);

        $section->update($request->validated());

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => new SectionResource($this->hydrate($section, $user))]);
    }

    private function hydrate(Section $section, User $user): Section
    {
        return Section::query()
            ->whereKey($section->id)
            ->withMyRole($user)
            ->with('creator')
            ->withCount(['members', 'problems'])
            ->firstOrFail();
    }
}
