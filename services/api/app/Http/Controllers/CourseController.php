<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Http\Requests\Course\StoreCourseRequest;
use App\Http\Requests\Course\UpdateCourseRequest;
use App\Http\Resources\CourseResource;
use App\Http\Responses\CursorPage;
use App\Models\Course;
use App\Models\Organization;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * Organization staff see every course; anyone else reaches a course only
     * through a section they belong to (D-04).
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('viewAny', [Course::class, $organization]);

        /** @var User $user */
        $user = $request->user();
        $search = $request->string('search')->value();

        $page = $organization->courses()
            ->unless(
                $this->memberships->isOrganizationMember($user, $organization),
                fn (Builder $query) => $query->whereHas(
                    'semesters.sections.members',
                    fn (Builder $members) => $members->where('user_id', $user->id)
                )
            )
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match
                    ->where('code', 'ILIKE', '%'.$search.'%')
                    ->orWhere('name', 'ILIKE', '%'.$search.'%')
            ))
            ->with('creator')
            ->withCount('semesters')
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::json($page, CourseResource::class);
    }

    public function store(StoreCourseRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('create', [Course::class, $organization]);

        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated() + ['creator_id' => $user->id];

        if ($organization->courses()->where('code', $attributes['code'])->exists()) {
            throw ConflictException::duplicateCourseCode();
        }

        try {
            $course = $organization->courses()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            // Lost the race between the check above and the insert.
            throw ConflictException::duplicateCourseCode();
        }

        return response()->json(
            ['data' => new CourseResource($this->hydrate($course))],
            JsonResponse::HTTP_CREATED
        );
    }

    public function show(Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        return response()->json(['data' => new CourseResource($this->hydrate($course))]);
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        $this->authorize('update', $course);

        $course->update($request->validated());

        return response()->json(['data' => new CourseResource($this->hydrate($course))]);
    }

    /** Refreshed, not just loaded: column defaults only exist in the row. */
    private function hydrate(Course $course): Course
    {
        return $course->refresh()->load('creator')->loadCount('semesters');
    }
}
