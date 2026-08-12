<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProgrammingLanguage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProgrammingLanguage> */
class ProgrammingLanguageFactory extends Factory
{
    protected $model = ProgrammingLanguage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Python',
            'version' => '3.11',
            'judge0_id' => 71,
            'monaco_language' => 'python',
            'dolos_language' => 'python',
            'codeql_language' => 'python',
        ];
    }
}
