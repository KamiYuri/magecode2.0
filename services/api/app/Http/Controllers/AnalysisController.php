<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Analysis\TriggerAnalysisRequest;
use App\Http\Resources\AnalysisProblemResource;
use App\Models\AnalysisProblem;
use App\Models\Problem;
use App\Services\Analysis\AnalysisTriggerService;
use Illuminate\Http\JsonResponse;

class AnalysisController extends Controller
{
    public function __construct(private readonly AnalysisTriggerService $trigger) {}

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
}
