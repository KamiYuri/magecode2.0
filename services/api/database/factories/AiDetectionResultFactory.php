<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiDetectionResult;
use App\Models\AnalysisSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiDetectionResult> */
class AiDetectionResultFactory extends Factory
{
    protected $model = AiDetectionResult::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'analysis_submission_id' => AnalysisSubmission::factory(),
            'probability' => 0.1200,
            'created_at' => now(),
        ];
    }

    /** Above the default ai_detection_threshold of 0.80. */
    public function flagged(): static
    {
        return $this->state(fn (): array => ['probability' => 0.9500]);
    }
}
