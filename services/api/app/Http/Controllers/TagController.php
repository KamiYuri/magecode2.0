<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Http\Requests\Tag\StoreTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Course;
use App\Models\Tag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * B9: course-scoped tags (D-15).
 *
 * The scope is the feature. `tags` carries `unique(course_id, name)`, so two
 * courses may each have a "Đệ quy" and one course may not have two -- checked
 * ahead of the insert for the message, and caught after it for the race.
 *
 * Not cursor-paginated: openapi declares `data` with no `meta`, because a
 * course's tag vocabulary is small and the frontend renders it as a filter
 * list rather than a page.
 */
class TagController extends Controller
{
    public function index(Request $request, Course $course): JsonResponse
    {
        $this->authorize('viewAny', [Tag::class, $course]);

        $tags = $course->tags()->orderBy('name')->get();

        return response()->json(['data' => TagResource::collection($tags)->resolve($request)]);
    }

    public function store(StoreTagRequest $request, Course $course): JsonResponse
    {
        $this->authorize('create', [Tag::class, $course]);

        $attributes = $request->validated();

        if ($course->tags()->where('name', $attributes['name'])->exists()) {
            throw ConflictException::duplicateTagName();
        }

        try {
            $tag = $course->tags()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            throw ConflictException::duplicateTagName();
        }

        return response()->json(['data' => new TagResource($tag)], JsonResponse::HTTP_CREATED);
    }

    public function update(StoreTagRequest $request, Tag $tag): JsonResponse
    {
        $this->authorize('update', $tag);

        $attributes = $request->validated();

        // `whereKeyNot` so a tag may keep its own name while changing colour.
        $taken = Tag::query()
            ->where('course_id', $tag->course_id)
            ->where('name', $attributes['name'])
            ->whereKeyNot($tag->id)
            ->exists();

        if ($taken) {
            throw ConflictException::duplicateTagName();
        }

        try {
            $tag->update($attributes);
        } catch (UniqueConstraintViolationException) {
            throw ConflictException::duplicateTagName();
        }

        return response()->json(['data' => new TagResource($tag->refresh())]);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $this->authorize('delete', $tag);

        // The pivots go first and in the same transaction: `problem_tags` and
        // `bank_problem_tags` are the attachment, not the problem, so a tag
        // someone used is still deletable and takes nothing with it.
        DB::transaction(function () use ($tag): void {
            $tag->problems()->detach();
            $tag->bankProblems()->detach();
            $tag->delete();
        });

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
