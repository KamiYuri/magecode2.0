<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Section;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Section> */
class SectionFactory extends Factory
{
    protected $model = Section::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'semester_id' => Semester::factory(),
            'name' => 'L'.str_pad((string) fake()->unique()->numberBetween(1, 999), 2, '0', STR_PAD_LEFT),
            'description' => fake()->sentence(),
            'creator_id' => User::factory(),
        ];
    }
}
