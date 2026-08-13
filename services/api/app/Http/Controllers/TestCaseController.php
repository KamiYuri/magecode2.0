<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Problem\BatchUpdateTestCasesRequest;
use App\Http\Resources\TestCaseResource;
use App\Models\Problem;
use App\Services\TestCaseService;
use Illuminate\Http\JsonResponse;

class TestCaseController extends Controller
{
    public function __construct(private readonly TestCaseService $testCases) {}

    /** Staff only, and unpaginated — a problem holds at most 50 (D-45). */
    public function index(Problem $problem): JsonResponse
    {
        $this->authorize('viewTestCases', $problem);

        $testCases = $problem->testCases()->orderBy('order')->orderBy('id')->get();

        return response()->json(['data' => TestCaseResource::collection($testCases)]);
    }

    public function update(BatchUpdateTestCasesRequest $request, Problem $problem): JsonResponse
    {
        $this->authorize('updateTestCases', $problem);

        $result = $this->testCases->sync($problem, $request->entries());

        return response()->json([
            'data' => TestCaseResource::collection($result['test_cases']),
            'meta' => [
                'outdated_submissions_count' => $result['outdated'],
                'notifications_sent' => $result['notified'],
            ],
        ]);
    }
}
