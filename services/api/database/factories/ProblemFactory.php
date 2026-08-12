<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Difficulty;
use App\Models\Problem;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Problem> */
class ProblemFactory extends Factory
{
    protected $model = Problem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
            'bank_problem_id' => null,
            'creator_id' => User::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->paragraph(),
            'difficulty' => Difficulty::Medium,
            'group_label' => null,
            'order' => null,
            'max_submissions' => null,
            'time_limit' => 1000,
            'memory_limit' => 65536,
            'activation_time' => null,
            'lock_time' => null,
            'publish_mode_override' => null,
            'lock_mode_override' => null,
            'is_published' => false,
            'is_locked' => false,
            'manual_match_group_id' => null,
            'testcases_updated_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['is_published' => true]);
    }

    public function locked(): static
    {
        return $this->state(fn (): array => ['is_locked' => true]);
    }

    /** Scheduled visibility: open now, closing later (auto mode). */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'activation_time' => now()->subDay(),
            'lock_time' => now()->addWeek(),
        ]);
    }
}
