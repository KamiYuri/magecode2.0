<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Course> */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => 'IT'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'require_bank_approval' => false,
            'creator_id' => User::factory(),
        ];
    }

    public function requiringBankApproval(): static
    {
        return $this->state(fn (): array => ['require_bank_approval' => true]);
    }
}
