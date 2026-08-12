<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Problem;
use App\Models\TestCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TestCase> */
class TestCaseFactory extends Factory
{
    protected $model = TestCase::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'problem_id' => Problem::factory(),
            'input' => "1 2\n",
            'expected_output' => "3\n",
            'is_active' => true,
            'is_visible' => false,
            'order' => 0,
        ];
    }

    /** Sample test case shown to students. */
    public function visible(): static
    {
        return $this->state(fn (): array => ['is_visible' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
