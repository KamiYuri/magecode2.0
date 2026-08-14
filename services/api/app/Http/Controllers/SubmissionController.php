<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Submission\StoreSubmissionRequest;
use App\Http\Requests\Submission\UploadSubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Models\Problem;
use App\Models\ProgrammingLanguage;
use App\Models\Submission;
use App\Services\SubmissionService;
use Illuminate\Http\JsonResponse;

class SubmissionController extends Controller
{
    public function __construct(private readonly SubmissionService $submissions) {}

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
