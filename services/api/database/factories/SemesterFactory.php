<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublishMode;
use App\Models\Course;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Semester> */
class SemesterFactory extends Factory
{
    protected $model = Semester::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'name' => (string) fake()->unique()->numberBetween(20200, 20299),
            'description' => fake()->sentence(),
            'publish_mode' => PublishMode::Auto,
            'lock_mode' => PublishMode::Auto,
            'allow_publish_override' => true,
            'allow_lock_override' => true,
            'similarity_threshold' => 0.70,
            'ai_detection_threshold' => 0.80,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'creator_id' => User::factory(),
        ];
    }

    public function manualPolicy(): static
    {
        return $this->state(fn (): array => [
            'publish_mode' => PublishMode::Manual,
            'lock_mode' => PublishMode::Manual,
        ]);
    }

    public function withoutOverrides(): static
    {
        return $this->state(fn (): array => [
            'allow_publish_override' => false,
            'allow_lock_override' => false,
        ]);
    }
}
