<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SectionRole;
use App\Http\Requests\Section\AddSectionMembersRequest;
use App\Http\Requests\Section\ImportSectionMembersRequest;
use App\Http\Requests\Section\TransferSectionMemberRequest;
use App\Http\Requests\Section\UpdateSectionMemberRequest;
use App\Http\Resources\SectionMemberResource;
use App\Http\Responses\CursorPage;
use App\Models\Section;
use App\Models\SectionMember;
use App\Models\User;
use App\Services\RosterImportService;
use App\Services\SectionMemberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

class SectionMemberController extends Controller
{
    public function __construct(
        private readonly SectionMemberService $members,
        private readonly RosterImportService $roster,
    ) {}

    /**
     * Staff territory: the roster names classmates, so a student reading it
     * would learn who else is in the section (D-04).
     */
    public function index(Request $request, Section $section): JsonResponse
    {
        $this->authorize('manageMembers', $section);

        $page = $section->members()
            ->with(['user', 'addedBy'])
            ->when(
                $request->filled('role'),
                fn (Builder $query) => $query->where('role', $request->string('role')->value())
            )
            ->when($request->filled('search'), fn (Builder $query) => $query->whereHas(
                'user',
                fn (Builder $user) => $this->matchSearch($user, $request->string('search')->value())
            ))
            ->orderByDesc('id')
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::json($page, SectionMemberResource::class);
    }

    public function store(AddSectionMembersRequest $request, Section $section): JsonResponse
    {
        $this->authorize('manageMembers', $section);

        /** @var User $actor */
        $actor = $request->user();

        /** @var array<int, array{email: string, role: string}> $entries */
        $entries = $request->validated('members');

        return response()->json(['data' => $this->members->addMany($section, $entries, $actor)]);
    }

    /**
     * Partial success: the response is a report, not a verdict. Rows that
     * failed come back with the line number the instructor sees in Excel.
     */
    public function import(ImportSectionMembersRequest $request, Section $section): JsonResponse
    {
        $this->authorize('manageMembers', $section);

        /** @var User $actor */
        $actor = $request->user();

        /** @var UploadedFile $file */
        $file = $request->file('file');

        return response()->json(['data' => $this->roster->import($section, $file, $actor)]);
    }

    /**
     * D-50. Sits with the Org Admin because the move crosses a section
     * boundary and no instructor owns both sides of it.
     */
    public function transfer(
        TransferSectionMemberRequest $request,
        Section $section,
        SectionMember $member,
    ): JsonResponse {
        $this->authorize('transfer', $member);

        /** @var User $actor */
        $actor = $request->user();
        $target = Section::findOrFail((int) $request->validated('to_section_id'));
        $moved = $this->members->transfer($member, $target, $actor);

        return response()->json([
            'data' => new SectionMemberResource($moved->load(['user', 'addedBy'])),
        ]);
    }

    public function update(
        UpdateSectionMemberRequest $request,
        Section $section,
        SectionMember $member,
    ): JsonResponse {
        $this->authorize('manageMembers', $section);

        $role = SectionRole::from((string) $request->validated('role'));
        $updated = $this->members->changeRole($member, $role);

        return response()->json([
            'data' => new SectionMemberResource($updated->load(['user', 'addedBy'])),
        ]);
    }

    public function destroy(Section $section, SectionMember $member): Response
    {
        $this->authorize('manageMembers', $section);

        $member->delete();

        return response()->noContent();
    }

    /** @param  Builder<Model>  $query */
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
