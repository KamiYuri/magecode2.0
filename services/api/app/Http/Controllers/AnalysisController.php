<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnalysisStatus;
use App\Exceptions\UnprocessableException;
use App\Http\Requests\Analysis\SetMatchGroupRequest;
use App\Http\Requests\Analysis\TriggerAnalysisRequest;
use App\Http\Resources\AnalysisProblemResource;
use App\Models\AnalysisProblem;
use App\Models\Problem;
use App\Models\Semester;
use App\Services\Analysis\AnalysisCompletionService;
use App\Services\Analysis\AnalysisScope;
use App\Services\Analysis\AnalysisScopeResolver;
use App\Services\Analysis\AnalysisTriggerService;
use App\Services\Analysis\MatchGroupService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AnalysisController extends Controller
{
    public function __construct(
        private readonly AnalysisTriggerService $trigger,
        private readonly AnalysisScopeResolver $scopes,
        private readonly AnalysisCompletionService $completion,
        private readonly MatchGroupService $matchGroups,
    ) {}

    /**
     * Start a semester-scoped batch, or hand back the one that already covers
     * this scope.
     *
     * 201 when a batch was created, 200 when an existing completed batch was
     * returned unchanged — the caller can tell the difference without reading
     * the body (v3 §7, 2026-08-14).
     */
    public function store(TriggerAnalysisRequest $request, Problem $problem): JsonResponse
    {
        $this->authorize('create', [AnalysisProblem::class, $problem]);

        $result = $this->trigger->trigger(
            $problem,
            $request->user(),
            $request->services(),
            $request->boolean('force'),
        );

        $batch = $result['batch']->loadCount('analysisSubmissions')->load('analyst');

        return response()->json(
            ['data' => new AnalysisProblemResource($batch)],
            $result['created'] ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK,
        );
    }

    /**
     * The latest batch covering this problem's scope — which is semester-wide,
     * so it may well have been triggered from a sibling section's problem.
     */
    public function latest(Problem $problem): JsonResponse
    {
        $batch = $this->latestFor($problem);

        $this->authorize('view', $batch);

        return response()->json(['data' => new AnalysisProblemResource($this->withCounts($batch))]);
    }

    /** Every run of this scope, newest first. No `meta`: the contract declares none. */
    public function history(Problem $problem): JsonResponse
    {
        $scope = $this->scopes->resolve($problem);

        if ($scope === null) {
            // No scope means no batch can ever have existed — an empty history
            // rather than a 404, since the problem itself is real. Authorised
            // as a would-be trigger, which is the nearest ability that exists
            // without a batch to point at.
            $this->authorize('create', [AnalysisProblem::class, $problem]);

            return response()->json(['data' => []]);
        }

        $batches = $this->scoped($scope)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $this->authorize('view', $batches->first() ?? $this->latestFor($problem));

        return response()->json(['data' => AnalysisProblemResource::collection($batches)]);
    }

    /**
     * Stop a run in flight. openapi says "marks batch as timeout", and §10's
     * enum offers no `cancelled` — the batch ended without answers, which is
     * what `timeout` already means here.
     *
     * Cancelling goes through the same conditional close D6 and D7 use, so a
     * batch that finished a moment ago keeps its completion and the caller is
     * told there was nothing left to cancel.
     */
    public function cancel(AnalysisProblem $analysisProblem): JsonResponse
    {
        $this->authorize('cancel', $analysisProblem);

        if (! $this->completion->close($analysisProblem, AnalysisStatus::Timeout)) {
            throw UnprocessableException::analysisNotProcessing();
        }

        $fresh = $analysisProblem->fresh() ?? $analysisProblem;

        return response()->json(['data' => new AnalysisProblemResource($this->withCounts($fresh))]);
    }

    /** D-58: declare problems from different sections equivalent. */
    public function storeMatchGroup(SetMatchGroupRequest $request, Semester $semester): JsonResponse
    {
        $this->authorize('manageMatchGroups', [AnalysisProblem::class, $semester]);

        return response()->json(['data' => $this->matchGroups->link($semester, $request->problemIds())]);
    }

    public function matchGroups(Semester $semester): JsonResponse
    {
        $this->authorize('manageMatchGroups', [AnalysisProblem::class, $semester]);

        return response()->json(['data' => $this->matchGroups->listFor($semester)]);
    }

    private function latestFor(Problem $problem): AnalysisProblem
    {
        $scope = $this->scopes->resolve($problem);
        $batch = $scope === null ? null : $this->scoped($scope)->where('is_latest', true)->first();

        abort_if($batch === null, JsonResponse::HTTP_NOT_FOUND, 'No analysis exists for this problem.');

        return $batch;
    }

    /**
     * The batches of one scope, carrying the two counts the resource needs —
     * without them it falls back to a query per row.
     *
     * @return Builder<AnalysisProblem>
     */
    private function scoped(AnalysisScope $scope): Builder
    {
        $filter = array_filter($scope->columns(), static fn (mixed $value): bool => $value !== null);

        return AnalysisProblem::query()
            ->where($filter)
            ->with('analyst')
            ->withCount('analysisSubmissions as submissions_count')
            ->withCount(['analysisSubmissions as completed_count' => fn ($query) => $query->whereNotNull('completed_at')]);
    }

    private function withCounts(AnalysisProblem $batch): AnalysisProblem
    {
        return $batch->loadCount([
            'analysisSubmissions as submissions_count',
            'analysisSubmissions as completed_count' => fn ($query) => $query->whereNotNull('completed_at'),
        ])->load('analyst');
    }
}
