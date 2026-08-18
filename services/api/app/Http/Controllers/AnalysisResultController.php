<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Analysis\ListAiDetectionResultsRequest;
use App\Http\Requests\Analysis\ListAnalysisSubmissionsRequest;
use App\Http\Requests\Analysis\ListSimilarityResultsRequest;
use App\Http\Resources\Analysis\AiDetectionResultResource;
use App\Http\Resources\Analysis\AnalysisReadContext;
use App\Http\Resources\Analysis\AnalysisSubmissionResource;
use App\Http\Resources\Analysis\SimilarityResultDetailResource;
use App\Http\Resources\Analysis\SimilarityResultResource;
use App\Http\Resources\Analysis\VulnerabilityResultResource;
use App\Http\Responses\CursorPage;
use App\Models\AiDetectionResult;
use App\Models\AnalysisProblem;
use App\Models\AnalysisSubmission;
use App\Models\Semester;
use App\Models\SimilarityResult;
use App\Models\Submission;
use App\Models\User;
use App\Models\VulnerabilityResult;
use App\Services\Analysis\AnalysisOverviewService;
use App\Services\Analysis\AnalysisVisibilityService;
use App\Services\SubmissionStorageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading a batch's results.
 *
 * Every action here is gated by `AnalysisProblemPolicy::view` — instructors
 * teaching in the semester and Org Admins, never TAs or students — and every
 * field that could carry one student's code to another section's instructor
 * goes through `AnalysisVisibilityService` (D-05/D-06).
 */
class AnalysisResultController extends Controller
{
    public function __construct(
        private readonly AnalysisVisibilityService $visibility,
        private readonly SubmissionStorageService $storage,
        private readonly AnalysisOverviewService $overview,
    ) {}

    public function submissions(
        ListAnalysisSubmissionsRequest $request,
        AnalysisProblem $analysisProblem,
    ): JsonResponse {
        $this->authorize('view', $analysisProblem);

        $context = $this->contextFor($request, $analysisProblem);
        $withSubmission = $request->wants('submission');
        $withSource = $request->wants('source_code');

        $page = $this->sectionFilter(
            AnalysisSubmission::query()
                ->where('analysis_problem_id', $analysisProblem->id)
                ->with(['submission.creator', 'submission.problem.section', 'aiDetectionResult'])
                ->withCount('vulnerabilityResults'),
            $request,
            'submission.problem',
        )
            ->orderBy('id')
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::mapped($page, fn (AnalysisSubmission $entry): AnalysisSubmissionResource => new AnalysisSubmissionResource(
            $entry,
            $context,
            $withSubmission,
            $withSource,
            // Read only what this viewer may see: a redacted side is never
            // fetched from storage in the first place.
            $withSource && $context->maySeeSourceOf($entry->submission->problem->section_id)
                ? $this->storage->read($entry->submission->file_path)
                : null,
        ));
    }

    public function similarity(
        ListSimilarityResultsRequest $request,
        AnalysisProblem $analysisProblem,
    ): JsonResponse {
        $this->authorize('view', $analysisProblem);

        $context = $this->contextFor($request, $analysisProblem);

        $query = SimilarityResult::query()
            ->where('analysis_problem_id', $analysisProblem->id)
            ->with([
                'submissionA.creator', 'submissionA.problem.section',
                'submissionB.creator', 'submissionB.problem.section',
            ]);

        if ($request->filled('match_type')) {
            $query->where('match_type', $request->string('match_type')->value());
        }

        if ($request->filled('min_similarity')) {
            $query->where('similarity', '>=', $request->float('min_similarity'));
        }

        if ($request->filled('section_id')) {
            // Either side of the pair puts the row in that section's view.
            $sectionId = $request->integer('section_id');
            $query->where(fn (Builder $pair) => $pair
                ->whereHas('submissionA.problem', fn (Builder $p) => $p->where('section_id', $sectionId))
                ->orWhereHas('submissionB.problem', fn (Builder $p) => $p->where('section_id', $sectionId)));
        }

        $page = $query
            ->orderByDesc('similarity')
            ->orderBy('id')
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::mapped($page, fn (SimilarityResult $row): SimilarityResultResource => new SimilarityResultResource($row, $context));
    }

    public function similarityDetail(
        Request $request,
        AnalysisProblem $analysisProblem,
        SimilarityResult $similarityResult,
    ): JsonResponse {
        $this->authorize('view', $analysisProblem);

        abort_if(
            $similarityResult->analysis_problem_id !== $analysisProblem->id,
            JsonResponse::HTTP_NOT_FOUND,
            'This similarity result does not belong to that analysis.',
        );

        $similarityResult->load([
            'submissionA.creator', 'submissionA.problem.section',
            'submissionB.creator', 'submissionB.problem.section',
        ]);

        $context = $this->contextFor($request, $analysisProblem);

        return response()->json([
            'data' => new SimilarityResultDetailResource(
                $similarityResult,
                $context,
                $this->sourceFor($similarityResult->submissionA, $context),
                $this->sourceFor($similarityResult->submissionB, $context),
            ),
        ]);
    }

    public function aiDetection(
        ListAiDetectionResultsRequest $request,
        AnalysisProblem $analysisProblem,
    ): JsonResponse {
        $this->authorize('view', $analysisProblem);

        $context = $this->contextFor($request, $analysisProblem);

        $query = AiDetectionResult::query()
            ->whereHas('analysisSubmission', fn (Builder $entry) => $entry->where('analysis_problem_id', $analysisProblem->id))
            ->with(['analysisSubmission.submission.creator', 'analysisSubmission.submission.problem.section']);

        if ($request->boolean('flagged_only')) {
            $query->where('probability', '>=', $context->aiDetectionThreshold);
        }

        if ($request->filled('section_id')) {
            $sectionId = $request->integer('section_id');
            $query->whereHas(
                'analysisSubmission.submission.problem',
                fn (Builder $problem) => $problem->where('section_id', $sectionId),
            );
        }

        $page = $query
            ->orderByDesc('probability')
            ->orderBy('id')
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::mapped($page, fn (AiDetectionResult $row): AiDetectionResultResource => new AiDetectionResultResource($row, $context));
    }

    public function vulnerabilities(Request $request, AnalysisProblem $analysisProblem): JsonResponse
    {
        $this->authorize('view', $analysisProblem);

        $page = VulnerabilityResult::query()
            ->whereHas('analysisSubmission', fn (Builder $entry) => $entry->where('analysis_problem_id', $analysisProblem->id))
            ->orderBy('analysis_submission_id')
            ->orderBy('id')
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::mapped($page, fn (VulnerabilityResult $row): VulnerabilityResultResource => new VulnerabilityResultResource($row));
    }

    /**
     * The semester dashboard. Org-Admin-only per openapi's summary: it
     * aggregates across every section, which is precisely the view a single
     * section's instructor is not entitled to.
     */
    public function overview(Semester $semester): JsonResponse
    {
        $this->authorize('viewOverview', [AnalysisProblem::class, $semester]);

        return response()->json(['data' => $this->overview->build($semester)]);
    }

    /**
     * A submission's stored bytes, or null when this viewer may not read them.
     *
     * The check is the same one the resource applies; doing it here as well is
     * what keeps a redacted object from ever being fetched.
     */
    private function sourceFor(?Submission $submission, AnalysisReadContext $context): ?string
    {
        if ($submission === null || ! $context->maySeeSourceOf($submission->problem->section_id)) {
            return null;
        }

        return $this->storage->read($submission->file_path);
    }

    private function contextFor(Request $request, AnalysisProblem $analysisProblem): AnalysisReadContext
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return AnalysisReadContext::for($viewer, $analysisProblem->loadMissing('semester.course'), $this->visibility);
    }

    /**
     * `?section_id=` narrows which rows come back. It never widens what may be
     * read: a viewer asking for a section they do not teach still gets the
     * rows, with the source redacted.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function sectionFilter(Builder $query, Request $request, string $relation): Builder
    {
        if (! $request->filled('section_id')) {
            return $query;
        }

        $sectionId = $request->integer('section_id');

        return $query->whereHas($relation, fn (Builder $problem) => $problem->where('section_id', $sectionId));
    }
}
