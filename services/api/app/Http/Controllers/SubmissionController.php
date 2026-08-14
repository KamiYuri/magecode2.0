<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SectionRole;
use App\Http\Requests\Submission\ListSubmissionsRequest;
use App\Http\Requests\Submission\StoreSubmissionRequest;
use App\Http\Requests\Submission\UploadSubmissionRequest;
use App\Http\Resources\SubmissionDetailResource;
use App\Http\Resources\SubmissionResource;
use App\Http\Responses\CursorPage;
use App\Models\Problem;
use App\Models\ProgrammingLanguage;
use App\Models\Section;
use App\Models\Submission;
use App\Services\MembershipService;
use App\Services\SubmissionQueryService;
use App\Services\SubmissionService;
use App\Services\SubmissionStorageService;
use Illuminate\Http\JsonResponse;

class SubmissionController extends Controller
{
    public function __construct(
        private readonly SubmissionService $submissions,
        private readonly SubmissionQueryService $queries,
        private readonly SubmissionStorageService $storage,
        private readonly MembershipService $memberships,
    ) {}

    /** A student's own history; staff see the whole problem. */
    public function index(ListSubmissionsRequest $request, Problem $problem): JsonResponse
    {
        $this->authorize('viewAny', [Submission::class, $problem]);

        $page = $this->queries
            ->forProblem($problem, $request->user(), $request->filters())
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::json($page, SubmissionResource::class);
    }

    /** Staff dashboard across every problem of the section. */
    public function section(ListSubmissionsRequest $request, Section $section): JsonResponse
    {
        $this->authorize('viewSection', [Submission::class, $section]);

        $page = $this->queries
            ->forSection($section, $request->filters())
            ->cursorPaginate(CursorPage::perPage($request));

        return CursorPage::json($page, SubmissionResource::class);
    }

    public function show(ListSubmissionsRequest $request, Submission $submission): JsonResponse
    {
        $this->authorize('view', $submission);

        $submission->load(['creator', 'programmingLanguage', 'executionResults.testCase']);

        $isStudent = $this->memberships
            ->sectionRole($request->user(), $submission->problem->section_id) === SectionRole::Student;

        return response()->json(['data' => new SubmissionDetailResource(
            $submission,
            $request->boolean('include_source') ? $this->storage->read($submission->file_path) : null,
            revealHiddenTestCases: ! $isStudent,
        )]);
    }

    /** Editor path: JSON body carrying the source as text. */
    public function store(StoreSubmissionRequest $request, Problem $problem): JsonResponse
    {
        $this->authorize('create', [Submission::class, $problem]);

        $submission = $this->submissions->submitSource(
            $problem,
            $request->user(),
            $this->language($request->integer('programming_language_id')),
            (string) $request->input('source_code'),
        );

        return $this->created($submission);
    }

    /** Upload path: multipart, the student's own filename kept (C1). */
    public function upload(UploadSubmissionRequest $request, Problem $problem): JsonResponse
    {
        $this->authorize('create', [Submission::class, $problem]);

        $submission = $this->submissions->submitFile(
            $problem,
            $request->user(),
            $this->language($request->integer('programming_language_id')),
            $request->file('file'),
        );

        return $this->created($submission);
    }

    private function language(int $id): ProgrammingLanguage
    {
        return ProgrammingLanguage::findOrFail($id);
    }

    private function created(Submission $submission): JsonResponse
    {
        $submission->load(['creator', 'programmingLanguage']);

        return response()->json(
            ['data' => new SubmissionResource($submission)],
            JsonResponse::HTTP_CREATED,
        );
    }
}
