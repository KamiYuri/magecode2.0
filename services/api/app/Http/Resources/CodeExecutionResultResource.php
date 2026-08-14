<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CodeExecutionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `CodeExecutionResult` schema in openapi.yml.
 *
 * A hidden test case shows a student its order and verdict but never its input
 * or expected output — otherwise the grader would hand out the answer key one
 * failed run at a time. Staff see everything.
 *
 * @mixin CodeExecutionResult
 */
class CodeExecutionResultResource extends JsonResource
{
    public function __construct(CodeExecutionResult $resource, private readonly bool $revealHidden = false)
    {
        parent::__construct($resource);
    }

    /**
     * @param  iterable<int, CodeExecutionResult>  $results
     * @return list<self>
     */
    public static function forViewer(iterable $results, bool $revealHidden): array
    {
        $collection = [];

        foreach ($results as $result) {
            $collection[] = new self($result, $revealHidden);
        }

        return $collection;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // The FK is NOT NULL and test cases cascade with their problem, so a
        // result always has one.
        $testCase = $this->testCase;
        $visible = $testCase->is_visible;
        $reveal = $this->revealHidden || $visible;

        return [
            'id' => $this->id,
            'test_case_order' => $testCase->order,
            'is_visible' => $visible,
            'test_case_input' => $reveal ? $testCase->input : null,
            'test_case_expected_output' => $reveal ? $testCase->expected_output : null,
            'status' => $this->status,
            'actual_output' => $this->actual_output,
            'consumed_time_ms' => $this->consumed_time_ms,
            'consumed_memory_kb' => $this->consumed_memory_kb,
            'error_content' => $this->error_content,
        ];
    }
}
