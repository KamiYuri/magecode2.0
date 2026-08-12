<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MatchType;
use App\Models\AnalysisProblem;
use App\Models\SimilarityResult;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SimilarityResult> */
class SimilarityResultFactory extends Factory
{
    protected $model = SimilarityResult::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // chk_similarity_pair_order requires submission_a_id < submission_b_id,
        // so both rows are created up front and then ordered.
        $first = Submission::factory()->create();
        $second = Submission::factory()->create();
        $pair = [$first->id, $second->id];
        sort($pair);

        return [
            'analysis_problem_id' => AnalysisProblem::factory(),
            'submission_a_id' => $pair[0],
            'submission_b_id' => $pair[1],
            'similarity' => 0.8500,
            'longest_fragment' => 40,
            'total_overlap' => 120,
            'match_type' => MatchType::WithinSection,
            'a_regions' => '1,0,5,10',
            'b_regions' => '2,0,6,10',
            'created_at' => now(),
        ];
    }

    /** @param array{0: int, 1: int} $submissionIds */
    public function betweenSubmissions(array $submissionIds): static
    {
        sort($submissionIds);

        return $this->state(fn (): array => [
            'submission_a_id' => $submissionIds[0],
            'submission_b_id' => $submissionIds[1],
        ]);
    }

    public function crossSection(): static
    {
        return $this->state(fn (): array => ['match_type' => MatchType::CrossSection]);
    }
}
