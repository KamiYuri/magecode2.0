<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServiceStatus;
use App\Models\AnalysisProblem;
use App\Models\AnalysisSubmission;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnalysisSubmission> */
class AnalysisSubmissionFactory extends Factory
{
    protected $model = AnalysisSubmission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'analysis_problem_id' => AnalysisProblem::factory(),
            'plagiarism_status' => ServiceStatus::InQueue,
            'ai_detection_status' => ServiceStatus::NotApplicable,
            'vuln_scan_status' => ServiceStatus::NotApplicable,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'plagiarism_status' => ServiceStatus::Completed,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }
}
