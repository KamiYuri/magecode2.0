<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExecutionStatus;
use App\Models\Problem;
use App\Models\ProgrammingLanguage;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Submission> */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'problem_id' => Problem::factory(),
            'creator_id' => User::factory()->student(),
            'programming_language_id' => ProgrammingLanguage::factory(),
            'file_path' => 'submissions/1/1/main.py',
            'file_name' => 'main.py',
            'execution_status' => ExecutionStatus::InQueue,
            'testcases_passed' => 0,
            'testcases_total' => 0,
            'is_outdated' => false,
        ];
    }

    public function accepted(int $total = 10): static
    {
        return $this->state(fn (): array => [
            'execution_status' => ExecutionStatus::Accepted,
            'testcases_passed' => $total,
            'testcases_total' => $total,
        ]);
    }

    /** Test cases changed after this submission was graded (D-41). */
    public function outdated(): static
    {
        return $this->state(fn (): array => ['is_outdated' => true]);
    }
}
