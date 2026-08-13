<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\UnprocessableException;
use App\Http\Requests\Problem\ListProblemsRequest;
use App\Http\Requests\Problem\ReorderProblemsRequest;
use App\Http\Requests\Problem\ShowProblemRequest;
use App\Http\Requests\Problem\StoreProblemRequest;
use App\Http\Requests\Problem\ToggleLockRequest;
use App\Http\Requests\Problem\TogglePublishRequest;
use App\Http\Requests\Problem\UpdateProblemRequest;
use App\Http\Resources\ProblemDetailResource;
use App\Http\Resources\ProblemResource;
use App\Models\Problem;
use App\Models\Section;
use App\Models\User;
use App\Services\MembershipService;
use App\Services\ProblemService;
use App\Services\ProblemVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProblemController extends Controller
{
    /** API include names → the relations they eager-load. */
    private const INCLUDE_RELATIONS = [
        'programming_languages' => 'programmingLanguages',
        'tags' => 'tags',
        'creator' => 'creator',
        'test_cases' => 'testCases',
    ];

    public function __construct(
        private readonly ProblemService $problems,
        private readonly MembershipService $memberships,
        private readonly ProblemVisibilityService $visibility,
    ) {}

    /**
     * Staff see the whole section; a student sees only what is open right now
     * (D-16). Returned as a bare collection — openapi declares no pagination
     * for this operation.
     */
    public function index(ListProblemsRequest $request, Section $section): JsonResponse
    {
        $this->authorize('viewAny', [Problem::class, $section]);

        /** @var User $user */
        $user = $request->user();
        $semester = $section->semester;

        $problems = $section->problems()
            ->unless($this->isStaff($user, $section), fn (Builder $query) => $query->visibleIn($semester))
            ->when($request->filled('group_label'), fn (Builder $query) => $query
                ->where('group_label', $request->string('group_label')->value()))
            ->when($request->filled('difficulty'), fn (Builder $query) => $query
                ->where('difficulty', $request->string('difficulty')->value()))
            ->with($this->relationsFor($request->includes()))
            ->with(['sampleTestCases', 'section.semester'])
            ->withCount('submissions')
            ->withMyProgress($user)
            ->orderByRaw('"order" is null, "order"')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => ProblemResource::collection($problems)]);
    }

    public function store(StoreProblemRequest $request, Section $section): JsonResponse
    {
        $this->authorize('create', [Problem::class, $section]);

        /** @var User $user */
        $user = $request->user();
        $problem = $this->problems->create($section, $request->validated(), $user);

        return response()->json(
            ['data' => $this->detail($problem, $user)],
            JsonResponse::HTTP_CREATED
        );
    }

    /**
     * One URL for both audiences: staff get ProblemDetail with every test
     * case, a student gets Problem with only the samples — asking for
     * `include=test_cases` as a student is ignored, not refused.
     */
    public function show(ShowProblemRequest $request, Problem $problem): JsonResponse
    {
        $this->authorize('view', $problem);

        /** @var User $user */
        $user = $request->user();

        if (! $user->can('viewTestCases', $problem)) {
            $problem = $this->hydrate($problem, $user, $this->relationsFor(
                array_diff($request->includes(), ['test_cases'])
            ));

            return response()->json(['data' => new ProblemResource($problem)]);
        }

        return response()->json([
            'data' => $this->detail($problem, $user, $this->relationsFor($request->includes())),
        ]);
    }

    public function update(UpdateProblemRequest $request, Problem $problem): JsonResponse
    {
        $this->authorize('update', $problem);

        /** @var User $user */
        $user = $request->user();
        $outdated = $this->problems->update($problem, $request->validated(), $request->touchesCoreFields());

        return response()->json([
            'data' => $this->detail($problem, $user),
            'meta' => ['outdated_submissions_count' => $outdated],
        ]);
    }

    /**
     * Manual publish. Denied when the semester pins the mode for everyone
     * below the Org Admin (D-16) — a final exam that must open at one time
     * across every section.
     */
    public function publish(TogglePublishRequest $request, Problem $problem): JsonResponse
    {
        return $this->toggle($request, $problem, 'is_published', $request->boolean('is_published'));
    }

    public function lock(ToggleLockRequest $request, Problem $problem): JsonResponse
    {
        return $this->toggle($request, $problem, 'is_locked', $request->boolean('is_locked'));
    }

    /** Bulk reorder within one section (D-44). */
    public function reorder(ReorderProblemsRequest $request, Section $section): JsonResponse
    {
        $this->authorize('create', [Problem::class, $section]);

        /** @var array<int, array{problem_id: int, order: int, group_label?: string|null}> $entries */
        $entries = $request->validated('order');
        $this->problems->reorder($section, $entries);

        return response()->json(['data' => ['message' => __('Order updated.')]]);
    }

    /** Soft delete (D-43): the problem leaves the UI, submissions stay (D-52). */
    public function destroy(Problem $problem): Response
    {
        $this->authorize('delete', $problem);

        $problem->delete();

        return response()->noContent();
    }

    private function toggle(Request $request, Problem $problem, string $flag, bool $value): JsonResponse
    {
        $this->authorize('update', $problem);

        /** @var User $user */
        $user = $request->user();
        $administers = $this->memberships->administersSection($user, $problem->section);
        $semester = $problem->section->semester;

        $permitted = $flag === 'is_published'
            ? $this->visibility->mayOverridePublish($semester, $administers)
            : $this->visibility->mayOverrideLock($semester, $administers);

        if (! $permitted) {
            throw UnprocessableException::policyOverrideDenied();
        }

        $problem->forceFill([$flag => $value])->save();

        return response()->json(['data' => $this->detail($problem, $user)]);
    }

    /** @param  array<int, string>  $relations */
    private function detail(Problem $problem, User $user, array $relations = []): ProblemDetailResource
    {
        $administers = $this->memberships->administersSection($user, $problem->section);

        return new ProblemDetailResource(
            $this->hydrate($problem, $user, array_unique([...$relations, 'testCases'])),
            $administers
        );
    }

    /** @param  array<int, string>  $relations */
    private function hydrate(Problem $problem, User $user, array $relations = []): Problem
    {
        return Problem::query()
            ->whereKey($problem->id)
            ->with([...$relations, 'sampleTestCases', 'section.semester'])
            ->withCount('submissions')
            ->withMyProgress($user)
            ->firstOrFail();
    }

    /**
     * @param  array<int, string>  $includes
     * @return array<int, string>
     */
    private function relationsFor(array $includes): array
    {
        return array_values(array_intersect_key(
            self::INCLUDE_RELATIONS,
            array_flip($includes)
        ));
    }

    private function isStaff(User $user, Section $section): bool
    {
        return $this->memberships->isSectionStaff($user, $section)
            || $this->memberships->administersSection($user, $section);
    }
}
