<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProgrammingLanguage;
use Illuminate\Database\Seeder;

/**
 * Reference data from database-schema.md §3.2. The judge0_id values come from
 * Judge0 CE and must be re-verified whenever Judge0 is upgraded — a stale id
 * silently routes submissions to the wrong compiler.
 */
class ProgrammingLanguageSeeder extends Seeder
{
    private const LANGUAGES = [
        ['name' => 'Python', 'version' => '3.11', 'judge0_id' => 71, 'monaco_language' => 'python', 'dolos_language' => 'python', 'codeql_language' => 'python', 'file_extensions' => ['py']],
        ['name' => 'Java', 'version' => '17', 'judge0_id' => 62, 'monaco_language' => 'java', 'dolos_language' => 'java', 'codeql_language' => 'java', 'file_extensions' => ['java']],
        ['name' => 'C', 'version' => '11', 'judge0_id' => 50, 'monaco_language' => 'c', 'dolos_language' => 'c', 'codeql_language' => 'cpp', 'file_extensions' => ['c']],
        ['name' => 'C++', 'version' => '17', 'judge0_id' => 54, 'monaco_language' => 'cpp', 'dolos_language' => 'cpp', 'codeql_language' => 'cpp', 'file_extensions' => ['cpp', 'cc', 'cxx']],
    ];

    public function run(): void
    {
        foreach (self::LANGUAGES as $language) {
            ProgrammingLanguage::updateOrCreate(
                ['judge0_id' => $language['judge0_id']],
                $language
            );
        }
    }
}
