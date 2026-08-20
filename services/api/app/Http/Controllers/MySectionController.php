<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\MySectionResource;
use App\Models\Section;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /me/sections` — the dashboard shortcut (D-09). One call tells F4's
 * shell every section the caller belongs to and how to group it.
 *
 * Deliberately not cursor-paginated: openapi declares `data` with no `meta`,
 * because a person's sections are bounded by how much they can teach or take.
 */
class MySectionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $sections = Section::query()
            ->whereHas('members', fn ($member) => $member->where('user_id', $user->id))
            ->with(['semester.course.organization', 'creator'])
            ->withCount('problems')
            ->withMyRole($user)
            ->withMyProgress($user)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => MySectionResource::collection($sections)->resolve($request),
        ]);
    }
}
