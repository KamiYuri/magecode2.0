<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnalysisStatus;
use App\Models\AnalysisProblem;
use App\Models\BankProblem;
use App\Models\Problem;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnalysisProblem> */
class AnalysisProblemFactory extends Factory
{
    protected $model = AnalysisProblem::class;

    /**
     * Defaults to a bank-matched scope because chk_analysis_scope requires at
     * least one of bank_problem_id / manual_match_group_id.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'semester_id' => Semester::factory(),
            'bank_problem_id' => BankProblem::factory(),
            'manual_match_group_id' => null,
            'triggered_by_problem_id' => Problem::factory(),
            'analyst_id' => User::factory(),
            'services' => ['plagiarism-checker'],
            'status' => AnalysisStatus::Processing,
            'is_latest' => true,
            'is_partial' => false,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    /** Manual cross-section match group instead of a bank problem (D-58). */
    public function manuallyMatched(?string $groupId = null): static
    {
        return $this->state(fn (): array => [
            'bank_problem_id' => null,
            'manual_match_group_id' => $groupId ?? fake()->uuid(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => AnalysisStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /** Superseded by a newer batch for the same scope (D-53). */
    public function superseded(): static
    {
        return $this->state(fn (): array => ['is_latest' => false]);
    }
}
