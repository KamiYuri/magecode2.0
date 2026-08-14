<?php

declare(strict_types=1);

namespace Tests\Feature\Submissions;

use App\Enums\ExecutionStatus;
use App\Enums\PublishMode;
use App\Enums\SectionRole;
use App\Enums\TestCaseStatus;
use App\Models\CodeExecutionResult;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Submission;
use App\Models\TestCase as TestCaseModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * C2 reads. The rule underneath every case here is the one B5 fixed: a
 * classmate is a section member and still sees nothing of another student's
 * work, while staff of that section see all of it.
 */
class ListSubmissionsTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Section $section;

    private User $instructor;

    private User $student;

    private User $classmate;

    private Problem $problem;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');

        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()), [
            'publish_mode' => PublishMode::Auto,
            'lock_mode' => PublishMode::Auto,
        ]);
        $this->section = $this->sectionIn($semester);
        $this->instructor = $this->sectionMember($this->section, SectionRole::Instructor);
        $this->student = $this->sectionMember($this->section, SectionRole::Student);
        $this->classmate = $this->sectionMember($this->section, SectionRole::Student);

        $this->problem = Problem::factory()->for($this->section)->create([
            'activation_time' => now()->subDay(),
            'lock_time' => now()->addDay(),
        ]);
    }

    public function test_a_student_lists_only_their_own_submissions(): void
    {
        $this->submissionBy($this->student);
        $this->submissionBy($this->classmate);
        Sanctum::actingAs($this->student);

        $response = $this->getJson("/api/v1/problems/{$this->problem->id}/submissions")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'problem_id', 'creator', 'programming_language', 'file_name',
                    'execution_status', 'testcases_passed', 'testcases_total', 'is_outdated', 'created_at']],
                'meta' => ['next_cursor', 'prev_cursor', 'per_page', 'has_more'],
            ]);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($this->student->id, $response->json('data.0.creator.id'));
    }

    public function test_staff_list_every_submission_of_the_problem(): void
    {
        $this->submissionBy($this->student);
        $this->submissionBy($this->classmate);
        Sanctum::actingAs($this->instructor);

        $response = $this->getJson("/api/v1/problems/{$this->problem->id}/submissions")->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_staff_filter_by_student(): void
    {
        $this->submissionBy($this->student);
        $this->submissionBy($this->classmate);
        Sanctum::actingAs($this->instructor);

        $response = $this->getJson(
            "/api/v1/problems/{$this->problem->id}/submissions?student_id={$this->classmate->id}"
        )->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($this->classmate->id, $response->json('data.0.creator.id'));
    }

    /** A student cannot widen their own view by naming someone else. */
    public function test_a_student_naming_a_classmate_still_sees_only_their_own(): void
    {
        $this->submissionBy($this->student);
        $this->submissionBy($this->classmate);
        Sanctum::actingAs($this->student);

        $response = $this->getJson(
            "/api/v1/problems/{$this->problem->id}/submissions?student_id={$this->classmate->id}"
        )->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($this->student->id, $response->json('data.0.creator.id'));
    }

    public function test_the_status_filter_narrows_the_listing(): void
    {
        $this->submissionBy($this->student, ExecutionStatus::Accepted);
        $this->submissionBy($this->student, ExecutionStatus::Error);
        Sanctum::actingAs($this->student);

        $response = $this->getJson(
            "/api/v1/problems/{$this->problem->id}/submissions?status=accepted"
        )->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('accepted', $response->json('data.0.execution_status'));
    }

    /** `latest` is one row per student — the newest attempt. */
    public function test_latest_mode_returns_one_row_per_student(): void
    {
        $this->submissionBy($this->student, ExecutionStatus::Error, minutesAgo: 30);
        $newest = $this->submissionBy($this->student, ExecutionStatus::Accepted, minutesAgo: 5);
        $this->submissionBy($this->classmate, ExecutionStatus::Accepted, minutesAgo: 10);
        Sanctum::actingAs($this->instructor);

        $response = $this->getJson("/api/v1/problems/{$this->problem->id}/submissions?mode=latest")->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertContains($newest->id, array_column($response->json('data'), 'id'));
    }

    /** `best` is one row per student — most test cases passed, ties to the newest. */
    public function test_best_mode_returns_the_strongest_attempt_per_student(): void
    {
        $this->submissionBy($this->student, ExecutionStatus::PartiallyAccepted, minutesAgo: 30, passed: 7);
        $this->submissionBy($this->student, ExecutionStatus::Error, minutesAgo: 5, passed: 1);
        Sanctum::actingAs($this->instructor);

        $response = $this->getJson("/api/v1/problems/{$this->problem->id}/submissions?mode=best")->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(7, $response->json('data.0.testcases_passed'));
    }

    public function test_a_student_of_another_section_gets_nothing(): void
    {
        $outsider = $this->sectionMember($this->sectionIn($this->section->semester), SectionRole::Student);
        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/problems/{$this->problem->id}/submissions")->assertForbidden();
    }

    public function test_a_student_reads_their_own_submission_with_the_source(): void
    {
        $submission = $this->submissionBy($this->student);
        Storage::disk('minio')->put($submission->file_path, "print('mine')\n");
        Sanctum::actingAs($this->student);

        $this->getJson("/api/v1/submissions/{$submission->id}?include_source=1")
            ->assertOk()
            ->assertJsonPath('data.source_code', "print('mine')\n")
            ->assertJsonStructure(['data' => ['id', 'source_code', 'execution_results']]);
    }

    /** Source costs a storage round-trip, so it is opt-in per the contract. */
    public function test_the_source_is_absent_unless_asked_for(): void
    {
        $submission = $this->submissionBy($this->student);
        Sanctum::actingAs($this->student);

        $this->getJson("/api/v1/submissions/{$submission->id}")
            ->assertOk()
            ->assertJsonPath('data.source_code', null);
    }

    public function test_a_classmate_may_not_read_another_students_submission(): void
    {
        $submission = $this->submissionBy($this->classmate);
        Sanctum::actingAs($this->student);

        $this->getJson("/api/v1/submissions/{$submission->id}")->assertForbidden();
    }

    public function test_staff_read_a_students_submission(): void
    {
        $submission = $this->submissionBy($this->student);
        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/submissions/{$submission->id}")->assertOk();
    }

    /** A missing object is not a 500: the row is the record, the bytes are storage. */
    public function test_a_submission_whose_object_is_gone_still_reads(): void
    {
        $submission = $this->submissionBy($this->student);
        Sanctum::actingAs($this->student);

        $this->getJson("/api/v1/submissions/{$submission->id}?include_source=1")
            ->assertOk()
            ->assertJsonPath('data.source_code', null);
    }

    /**
     * A hidden test case tells a student that it ran and how it went, and
     * nothing more — a failing run must not hand out the answer key.
     */
    public function test_a_student_sees_a_hidden_test_cases_verdict_but_not_its_data(): void
    {
        $submission = $this->submissionBy($this->student);
        $hidden = TestCaseModel::factory()->for($this->problem)->create([
            'is_visible' => false,
            'input' => '42',
            'expected_output' => '84',
            'order' => 3,
        ]);
        CodeExecutionResult::factory()->create([
            'submission_id' => $submission->id,
            'test_case_id' => $hidden->id,
            'status' => TestCaseStatus::WrongAnswer,
        ]);
        Sanctum::actingAs($this->student);

        $this->getJson("/api/v1/submissions/{$submission->id}")
            ->assertOk()
            ->assertJsonPath('data.execution_results.0.test_case_order', 3)
            ->assertJsonPath('data.execution_results.0.is_visible', false)
            ->assertJsonPath('data.execution_results.0.status', 'wrong_answer')
            ->assertJsonPath('data.execution_results.0.test_case_input', null)
            ->assertJsonPath('data.execution_results.0.test_case_expected_output', null);
    }

    public function test_a_student_sees_the_data_of_a_visible_test_case(): void
    {
        $submission = $this->submissionBy($this->student);
        $sample = TestCaseModel::factory()->for($this->problem)->create([
            'is_visible' => true,
            'input' => '42',
            'expected_output' => '84',
        ]);
        CodeExecutionResult::factory()->create([
            'submission_id' => $submission->id,
            'test_case_id' => $sample->id,
        ]);
        Sanctum::actingAs($this->student);

        $this->getJson("/api/v1/submissions/{$submission->id}")
            ->assertOk()
            ->assertJsonPath('data.execution_results.0.test_case_input', '42')
            ->assertJsonPath('data.execution_results.0.test_case_expected_output', '84');
    }

    public function test_staff_see_a_hidden_test_cases_data(): void
    {
        $submission = $this->submissionBy($this->student);
        $hidden = TestCaseModel::factory()->for($this->problem)->create([
            'is_visible' => false,
            'input' => '42',
            'expected_output' => '84',
        ]);
        CodeExecutionResult::factory()->create([
            'submission_id' => $submission->id,
            'test_case_id' => $hidden->id,
        ]);
        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/submissions/{$submission->id}")
            ->assertOk()
            ->assertJsonPath('data.execution_results.0.test_case_input', '42')
            ->assertJsonPath('data.execution_results.0.test_case_expected_output', '84');
    }

    public function test_staff_list_the_whole_section(): void
    {
        $other = Problem::factory()->for($this->section)->create();
        $this->submissionBy($this->student);
        Submission::factory()->create(['problem_id' => $other->id, 'creator_id' => $this->classmate->id]);
        Sanctum::actingAs($this->instructor);

        $response = $this->getJson("/api/v1/sections/{$this->section->id}/submissions")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['next_cursor', 'prev_cursor', 'per_page', 'has_more']]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_the_section_listing_filters_by_problem(): void
    {
        $other = Problem::factory()->for($this->section)->create();
        $this->submissionBy($this->student);
        Submission::factory()->create(['problem_id' => $other->id, 'creator_id' => $this->classmate->id]);
        Sanctum::actingAs($this->instructor);

        $response = $this->getJson(
            "/api/v1/sections/{$this->section->id}/submissions?problem_id={$other->id}"
        )->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    /** The section listing is a staff dashboard: a student has no view of it. */
    public function test_a_student_may_not_list_the_section(): void
    {
        Sanctum::actingAs($this->student);

        $this->getJson("/api/v1/sections/{$this->section->id}/submissions")->assertForbidden();
    }

    private function submissionBy(
        User $student,
        ExecutionStatus $status = ExecutionStatus::Accepted,
        int $minutesAgo = 0,
        int $passed = 0,
    ): Submission {
        return Submission::factory()->create([
            'problem_id' => $this->problem->id,
            'creator_id' => $student->id,
            'execution_status' => $status,
            'testcases_passed' => $passed,
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}
