<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BankProblemStatus;
use App\Enums\Difficulty;
use App\Models\BankProblem;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankProblem> */
class BankProblemFactory extends Factory
{
    protected $model = BankProblem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'author_id' => User::factory(),
            'original_id' => null,
            'name' => fake()->words(3, true),
            'description' => fake()->paragraph(),
            'difficulty' => Difficulty::Medium,
            'time_limit' => 1000,
            'memory_limit' => 65536,
            'version' => 1,
            'status' => BankProblemStatus::Approved,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => BankProblemStatus::Pending]);
    }

    /** A later version in an existing chain. */
    public function versionOf(BankProblem $original, int $version = 2): static
    {
        return $this->state(fn (): array => [
            'course_id' => $original->course_id,
            'original_id' => $original->id,
            'version' => $version,
        ]);
    }
}
