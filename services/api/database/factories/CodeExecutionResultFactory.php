<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TestCaseStatus;
use App\Models\CodeExecutionResult;
use App\Models\Submission;
use App\Models\TestCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CodeExecutionResult> */
class CodeExecutionResultFactory extends Factory
{
    protected $model = CodeExecutionResult::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'test_case_id' => TestCase::factory(),
            'status' => TestCaseStatus::Accepted,
            'actual_output' => "3\n",
            'consumed_time_ms' => 12.345,
            'consumed_memory_kb' => 3072,
            'error_content' => null,
            'created_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => TestCaseStatus::WrongAnswer,
            'actual_output' => "4\n",
        ]);
    }
}
