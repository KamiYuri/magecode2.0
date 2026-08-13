<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Organization;
use App\Models\ProgrammingLanguage;
use App\Models\Section;
use App\Models\SectionMember;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_programming_languages_are_seeded_from_the_schema_doc(): void
    {
        $this->seed();

        // Schema doc §3.2 — judge0_id values come from Judge0 CE and must not drift.
        $expected = [
            ['name' => 'Python', 'version' => '3.11', 'judge0_id' => 71, 'monaco_language' => 'python', 'dolos_language' => 'python', 'codeql_language' => 'python'],
            ['name' => 'Java', 'version' => '17', 'judge0_id' => 62, 'monaco_language' => 'java', 'dolos_language' => 'java', 'codeql_language' => 'java'],
            ['name' => 'C', 'version' => '11', 'judge0_id' => 50, 'monaco_language' => 'c', 'dolos_language' => 'c', 'codeql_language' => 'cpp'],
            ['name' => 'C++', 'version' => '17', 'judge0_id' => 54, 'monaco_language' => 'cpp', 'dolos_language' => 'cpp', 'codeql_language' => 'cpp'],
        ];

        $this->assertSame(4, ProgrammingLanguage::count());
        foreach ($expected as $language) {
            $this->assertDatabaseHas('programming_languages', $language);
        }

        // Asserted through the cast, not assertDatabaseHas: an array value does
        // not bind against a jsonb column.
        $extensions = [71 => ['py'], 62 => ['java'], 50 => ['c'], 54 => ['cpp', 'cc', 'cxx']];
        foreach ($extensions as $judge0Id => $expectedExtensions) {
            $this->assertSame(
                $expectedExtensions,
                ProgrammingLanguage::where('judge0_id', $judge0Id)->sole()->file_extensions
            );
        }
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        $this->seed();
        $counts = $this->tableCounts();

        $this->seed();

        $this->assertSame($counts, $this->tableCounts(), 'Re-seeding must not duplicate rows');
    }

    public function test_demo_data_forms_a_complete_hierarchy(): void
    {
        $this->seed();

        $organization = Organization::firstOrFail();
        $course = Course::firstOrFail();
        $semester = Semester::firstOrFail();
        $section = Section::firstOrFail();

        $this->assertTrue($course->organization->is($organization));
        $this->assertTrue($semester->course->is($course));
        $this->assertTrue($section->semester->is($semester));
        $this->assertGreaterThan(0, SectionMember::count());
    }

    public function test_demo_users_can_authenticate_with_the_documented_password(): void
    {
        $this->seed();

        $admin = User::where('username', 'admin')->firstOrFail();

        $this->assertTrue(password_verify('password', $admin->password));
        $this->assertNotNull($admin->email_verified_at);
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        return [
            'users' => User::count(),
            'organizations' => Organization::count(),
            'courses' => Course::count(),
            'semesters' => Semester::count(),
            'sections' => Section::count(),
            'section_members' => SectionMember::count(),
            'programming_languages' => ProgrammingLanguage::count(),
        ];
    }
}
