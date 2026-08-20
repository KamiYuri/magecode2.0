<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BankProblemStatus;
use App\Http\Requests\Bank\StoreBankProblemRequest;
use App\Http\Resources\BankProblemResource;
use App\Http\Responses\CursorPage;
use App\Models\BankProblem;
use App\Models\Course;
use App\Models\Problem;
use App\Models\User;
use App\Services\BankProblemService;
use App\Services\MembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * B10: the course-scoped problem bank (D-25, D-63–D-66).
 *
 * The staff-only rule lives in `BankProblemPolicy` (TAs and students have no
 * access at all). What lives here is the *approval* visibility: an entry that
 * is pending or rejected is visible to its author and to Org Admins and to
 * nobody else, because the point of D-25's gate is that a colleague's
 * unreviewed draft is not yet part of the shared vocabulary.
 */
class BankProblemController extends Controller
{
    public function __construct(
        private readonly BankProblemService $bank,
        private readonly MembershipService $memberships,
    ) {}

    public function index(Request $request, Course $course): JsonResponse
    {
        $this->authorize('viewAny', [BankProblem::class, $course]);

        $user = $this->user($request);

        $query = BankProblem::query()
            ->where('course_id', $course->id)
            ->with(['author', 'programmingLanguages', 'tags'])
            ->tap(fn (Builder $entries) => $this->hideOtherPeoplesDrafts($entries, $user, $course));

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('name', 'ilike', '%'.$search.'%');
        }

        if ($difficulty = $request->string('difficulty')->value()) {
            $query->where('difficulty', $difficulty);
        }

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        if ($tagIds = $this->tagIds($request)) {
            // All of them, not any: a filter by two tags means the entries
            // carrying both, which is how a person narrowing a list expects
            // it to behave.
            foreach ($tagIds as $tagId) {
                $query->whereHas('tags', fn ($tags) => $tags->where('tags.id', $tagId));
            }
        }

        $page = $query->orderByDesc('id')->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::json($page, BankProblemResource::class);
    }

    public function store(StoreBankProblemRequest $request, Course $course): JsonResponse
    {
        $this->authorize('create', [BankProblem::class, $course]);

        $validated = $request->validated();
        $user = $this->user($request);

        $bankProblem = $this->bank->create(
            $course,
            $user,
            $this->metadata($validated),
            $validated['programming_language_ids'],
            $validated['tag_ids'] ?? [],
            $validated['test_cases'] ?? [],
        );

        $this->bank->requestApproval($bankProblem);

        return response()->json(
            ['data' => new BankProblemResource($this->hydrate($bankProblem))],
            Response::HTTP_CREATED
        );
    }

    /** D-66: publishing a section's problem into the bank makes a new version. */
    public function publish(Request $request, Course $course): JsonResponse
    {
        $this->authorize('create', [BankProblem::class, $course]);

        $validated = $request->validate([
            'problem_id' => ['required', 'integer', 'exists:problems,id'],
        ]);

        /** @var Problem $problem */
        $problem = Problem::query()
            ->with(['section.semester', 'bankProblem', 'testCases', 'programmingLanguages', 'tags'])
            ->findOrFail($validated['problem_id']);

        // The problem has to belong to this course, or "publish to the bank"
        // would be a way to copy another course's material into yours.
        if ($problem->section->semester->course_id !== $course->id) {
            throw ValidationException::withMessages([
                'problem_id' => 'The problem belongs to another course.',
            ]);
        }

        $this->authorize('update', $problem);

        $bankProblem = $this->bank->publishFromProblem($problem, $this->user($request));
        $this->bank->requestApproval($bankProblem);

        return response()->json(
            ['data' => new BankProblemResource($this->hydrate($bankProblem))],
            Response::HTTP_CREATED
        );
    }

    public function show(Request $request, BankProblem $bankProblem): JsonResponse
    {
        $this->authorize('view', $bankProblem);
        $this->refuseOtherPeoplesDraft($request, $bankProblem);

        return response()->json(['data' => new BankProblemResource($this->hydrate($bankProblem))]);
    }

    public function update(StoreBankProblemRequest $request, BankProblem $bankProblem): JsonResponse
    {
        $this->authorize('update', $bankProblem);

        $validated = $request->validated();

        $bankProblem->update($this->metadata($validated));
        $bankProblem->programmingLanguages()->sync($validated['programming_language_ids']);
        $bankProblem->tags()->sync($validated['tag_ids'] ?? []);

        return response()->json(['data' => new BankProblemResource($this->hydrate($bankProblem->refresh()))]);
    }

    public function destroy(BankProblem $bankProblem): JsonResponse
    {
        $this->authorize('delete', $bankProblem);

        // Soft delete: `problems.bank_problem_id` is a RESTRICT foreign key,
        // and a section still teaching a clone must keep the row its origin
        // points at.
        $bankProblem->delete();

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /** D-25: the Org Admin's verdict. */
    public function approve(Request $request, BankProblem $bankProblem): JsonResponse
    {
        $this->authorize('approve', $bankProblem);

        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject'],
        ]);

        $bankProblem->update([
            'status' => $validated['action'] === 'approve'
                ? BankProblemStatus::Approved->value
                : BankProblemStatus::Rejected->value,
        ]);

        return response()->json(['data' => new BankProblemResource($this->hydrate($bankProblem->refresh()))]);
    }

    /** D-63: the whole chain, oldest first, whichever member was asked for. */
    public function versions(Request $request, BankProblem $bankProblem): JsonResponse
    {
        $this->authorize('view', $bankProblem);

        $root = $this->bank->rootOf($bankProblem);

        $versions = BankProblem::query()
            ->where(fn (Builder $chain) => $chain->whereKey($root->id)->orWhere('original_id', $root->id))
            ->with(['author', 'programmingLanguages', 'tags'])
            ->orderBy('version')
            ->get();

        return response()->json([
            'data' => BankProblemResource::collection($versions)->resolve($request),
        ]);
    }

    /**
     * D-25: a draft awaiting review is its author's and the Org Admin's until
     * it is approved. Rejected entries follow the same rule -- a colleague has
     * no use for a problem the admin turned down.
     *
     * @param  Builder<BankProblem>  $query
     */
    private function hideOtherPeoplesDrafts(Builder $query, User $user, Course $course): void
    {
        if ($this->administers($user, $course)) {
            return;
        }

        $query->where(fn (Builder $visible) => $visible
            ->where('status', BankProblemStatus::Approved->value)
            ->orWhere('author_id', $user->id));
    }

    private function refuseOtherPeoplesDraft(Request $request, BankProblem $bankProblem): void
    {
        if ($bankProblem->status === BankProblemStatus::Approved) {
            return;
        }

        $user = $this->user($request);

        abort_unless(
            $bankProblem->author_id === $user->id || $this->administers($user, $bankProblem->course),
            Response::HTTP_FORBIDDEN,
        );
    }

    /** The same bar `BankProblemPolicy::approve` uses. */
    private function administers(User $user, Course $course): bool
    {
        return $this->memberships->isOrganizationAdmin($user, $course->organization_id);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function metadata(array $validated): array
    {
        return array_intersect_key($validated, array_flip([
            'name', 'description', 'difficulty', 'time_limit', 'memory_limit',
        ]));
    }

    private function hydrate(BankProblem $bankProblem): BankProblem
    {
        return $bankProblem->load(['author', 'programmingLanguages', 'tags', 'testCases']);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @return list<int> */
    private function tagIds(Request $request): array
    {
        $raw = $request->string('tag_ids')->value();

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }
}
