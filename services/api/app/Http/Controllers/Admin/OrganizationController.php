<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Http\Responses\CursorPage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The unscoped organization listing. `GET /organizations` deliberately shows
 * only what the caller belongs to, so platform bootstrap needs its own door.
 */
class OrganizationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAll', Organization::class);

        /** @var User $user */
        $user = $request->user();

        $page = Organization::query()
            ->withMyRole($user)
            ->with('creator')
            ->withCount(['members', 'courses'])
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::json($page, OrganizationResource::class);
    }
}
