<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ExecutionStatus;
use App\Enums\SectionRole;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * `GET /me/sections` — the one call F4's sidebar makes to know what to draw.
 *
 * It is a dashboard shortcut, so it answers with the course, semester and
 * organization names alongside each section: without them the frontend would
 * walk four endpoints per row to render a nav tree.
 */
class MySectionsTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Section $section;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = $this->organizationWithAdmin();
        $course = $this->courseIn($organization, ['code' => 'IT3080', 'name' => 'Mạng máy tính']);
        $semester = $this->semesterIn($course, ['name' => '20252']);
        $this->section = $this->sectionIn($semester, ['name' => 'L01']);
        $this->student = $this->sectionMember($this->section, SectionRole::Student);
    }

    public function test_it_names_the_course_semester_and_organization_of_each_section(): void
    {
        $response = $this->actingAs($this->student)->getJson('/api/v1/me/sections');

        $response->assertOk()->assertJsonPath('data.0.section.id', $this->section->id);

        $row = $response->json('data.0');
        $this->assertSame('IT3080', $row['course_code']);
        $this->assertSame('Mạng máy tính', $row['course_name']);
        $this->assertSame('20252', $row['semester_name']);
        $this->assertSame($this->section->semester->course->organization->name, $row['organization_name']);
    }

    public function test_a_student_sees_their_own_progress(): void
    {
        $solved = Problem::factory()->for($this->section)->create();
        $attempted = Problem::factory()->for($this->section)->create();
        Problem::factory()->for($this->section)->create();

        $this->submission($solved, ExecutionStatus::Accepted);
        $this->submission($attempted, ExecutionStatus::Processing);

        $response = $this->actingAs($this->student)->getJson('/api/v1/me/sections');

        $response->assertOk()
            ->assertJsonPath('data.0.problems_count', 3)
            ->assertJsonPath('data.0.my_solved_count', 1)
            ->assertJsonPath('data.0.pending_submissions', 1);
    }

    /**
     * The two counters are described as a student's in openapi and are null
     * for anyone else — an instructor has no "solved" of their own, and a
     * zero would read as one.
     */
    public function test_staff_get_no_student_counters(): void
    {
        $instructor = $this->sectionMember($this->section, SectionRole::Instructor);

        $this->actingAs($instructor)->getJson('/api/v1/me/sections')
            ->assertOk()
            ->assertJsonPath('data.0.my_solved_count', null)
            ->assertJsonPath('data.0.pending_submissions', null);
    }

    public function test_it_lists_only_the_sections_the_caller_belongs_to(): void
    {
        $elsewhere = $this->sectionIn($this->semesterIn($this->courseIn($this->organizationWithAdmin())));
        $this->sectionMember($elsewhere, SectionRole::Student);

        $this->actingAs($this->student)->getJson('/api/v1/me/sections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.section.id', $this->section->id);
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson('/api/v1/me/sections')->assertUnauthorized();
    }

    private function submission(Problem $problem, ExecutionStatus $status): void
    {
        Submission::factory()->create([
            'problem_id' => $problem->id,
            'creator_id' => $this->student->id,
            'execution_status' => $status->value,
        ]);
    }
}
