<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Problem;
use App\Models\ProblemEditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProblemEditLog> */
class ProblemEditLogFactory extends Factory
{
    protected $model = ProblemEditLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'problem_id' => Problem::factory(),
            'edited_by' => User::factory(),
            'field_changed' => 'description',
            'old_value' => 'before',
            'new_value' => 'after',
            'edited_at' => now(),
        ];
    }
}
