<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tag> */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'name' => fake()->unique()->word(),
            'color' => '#'.fake()->numerify('######'),
        ];
    }
}
