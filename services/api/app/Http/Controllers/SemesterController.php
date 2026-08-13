<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Http\Requests\Semester\StoreSemesterRequest;
use App\Http\Requests\Semester\UpdateSemesterRequest;
use App\Http\Resources\SemesterResource;
use App\Http\Responses\CursorPage;
use App\Models\Course;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SemesterController extends Controller
{
    public function index(Request $request, Course $course): JsonResponse
    {
        $this->authorize('viewAny', [Semester::class, $course]);

        $page = $course->semesters()
            ->with('creator')
            ->withCount('sections')
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::json($page, SemesterResource::class);
    }

    public function store(StoreSemesterRequest $request, Course $course): JsonResponse
    {
        $this->authorize('create', [Semester::class, $course]);

        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated() + ['creator_id' => $user->id];

        if ($course->semesters()->where('name', $attributes['name'])->exists()) {
            throw ConflictException::duplicateSemesterName();
        }

        try {
            $semester = $course->semesters()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            throw ConflictException::duplicateSemesterName();
        }

        return response()->json(
            ['data' => new SemesterResource($this->hydrate($semester))],
            JsonResponse::HTTP_CREATED
        );
    }

    public function show(Semester $semester): JsonResponse
    {
        $this->authorize('view', $semester);

        return response()->json(['data' => new SemesterResource($this->hydrate($semester))]);
    }

    public function update(UpdateSemesterRequest $request, Semester $semester): JsonResponse
    {
        $this->authorize('update', $semester);

        $semester->update($request->validated());

        return response()->json(['data' => new SemesterResource($this->hydrate($semester))]);
    }

    /** Refreshed, not just loaded: the D-16 defaults only exist in the row. */
    private function hydrate(Semester $semester): Semester
    {
        return $semester->refresh()->load('creator')->loadCount('sections');
    }
}
