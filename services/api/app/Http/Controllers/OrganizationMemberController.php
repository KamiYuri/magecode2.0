<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrganizationRole;
use App\Http\Requests\Organization\AddOrganizationMembersRequest;
use App\Http\Requests\Organization\UpdateOrganizationMemberRequest;
use App\Http\Resources\OrganizationMemberResource;
use App\Http\Responses\CursorPage;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\OrganizationMemberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OrganizationMemberController extends Controller
{
    public function __construct(private readonly OrganizationMemberService $members) {}

    /** The roster is readable by anyone inside the organization. */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        $page = $organization->members()
            ->with(['user', 'addedBy'])
            ->when(
                $request->filled('role'),
                fn (Builder $query) => $query->where('role', $request->string('role')->value())
            )
            ->when(
                $request->filled('search'),
                fn (Builder $query) => $query->whereHas(
                    'user',
                    fn (Builder $user) => $this->matchSearch($user, $request->string('search')->value())
                )
            )
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::json($page, OrganizationMemberResource::class);
    }

    public function store(AddOrganizationMembersRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('manageMembers', $organization);

        /** @var User $actor */
        $actor = $request->user();

        /** @var array<int, array{email: string, role: string}> $entries */
        $entries = $request->validated('members');

        return response()->json(['data' => $this->members->addMany($organization, $entries, $actor)]);
    }

    public function update(
        UpdateOrganizationMemberRequest $request,
        Organization $organization,
        OrganizationMember $member,
    ): JsonResponse {
        $this->authorize('manageMembers', $organization);

        $role = OrganizationRole::from((string) $request->validated('role'));
        $updated = $this->members->changeRole($member, $role);

        return response()->json([
            'data' => new OrganizationMemberResource($updated->load(['user', 'addedBy'])),
        ]);
    }

    public function destroy(Organization $organization, OrganizationMember $member): Response
    {
        $this->authorize('manageMembers', $organization);

        $this->members->remove($member);

        return response()->noContent();
    }

    /**
     * Staff are found by the identifiers a human would type; ILIKE keeps the
     * match case-insensitive on Postgres.
     *
     * @param  Builder<Model>  $query
     */
    private function matchSearch(Builder $query, string $term): void
    {
        $pattern = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $user) use ($pattern): void {
            foreach (['username', 'first_name', 'last_name', 'email', 'student_id'] as $column) {
                $user->orWhere($column, 'ILIKE', $pattern);
            }
        });
    }
}
