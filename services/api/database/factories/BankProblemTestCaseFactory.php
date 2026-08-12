<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BankProblem;
use App\Models\BankProblemTestCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankProblemTestCase> */
class BankProblemTestCaseFactory extends Factory
{
    protected $model = BankProblemTestCase::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bank_problem_id' => BankProblem::factory(),
            'input' => "1 2\n",
            'expected_output' => "3\n",
            'is_active' => true,
            'is_visible' => false,
            'order' => 0,
        ];
    }
}
