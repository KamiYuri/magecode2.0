<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\ProgrammingLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /programming-languages` — the reference list behind F6's language
 * selector and the Monaco mode it opens with.
 */
class ProgrammingLanguagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * openapi gives this operation `security: []`. It is a reference list with
     * nothing of anyone's in it, and the register and first-time-setup screens
     * are drawn before a token exists.
     */
    public function test_it_answers_without_a_token(): void
    {
        ProgrammingLanguage::factory()->create(['name' => 'Python', 'monaco_language' => 'python']);

        $this->getJson('/api/v1/programming-languages')
            ->assertOk()
            ->assertJsonPath('data.0.monaco_language', 'python');
    }

    public function test_it_carries_the_client_facing_fields_only(): void
    {
        ProgrammingLanguage::factory()->create([
            'name' => 'C++',
            'version' => '17',
            'monaco_language' => 'cpp',
            'file_extensions' => ['cpp', 'cc', 'cxx'],
        ]);

        $row = $this->getJson('/api/v1/programming-languages')->assertOk()->json('data.0');

        $this->assertSame(
            ['id', 'name', 'version', 'monaco_language', 'file_extensions'],
            array_keys($row),
        );
        // Judge0, Dolos and CodeQL identifiers stay server-side.
        $this->assertSame(['cpp', 'cc', 'cxx'], $row['file_extensions']);
    }
}
