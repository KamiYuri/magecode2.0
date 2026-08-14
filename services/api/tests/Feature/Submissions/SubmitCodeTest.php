<?php

declare(strict_types=1);

namespace Tests\Feature\Submissions;

use App\Enums\ExecutionStatus;
use App\Enums\PublishMode;
use App\Enums\SectionRole;
use App\Models\Problem;
use App\Models\ProgrammingLanguage;
use App\Models\Section;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * C2: the two ways to submit and every reason a submission is refused.
 *
 * Both entry points funnel into one writer (C1), so the stored object must not
 * reveal which one the student used — the assertions below check the bytes and
 * the key, not just the status code.
 */
class SubmitCodeTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Section $section;

    private User $student;

    private Problem $problem;

    private ProgrammingLanguage $python;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');

        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()), [
            'publish_mode' => PublishMode::Auto,
            'lock_mode' => PublishMode::Auto,
        ]);
        $this->section = $this->sectionIn($semester);
        $this->student = $this->sectionMember($this->section, SectionRole::Student);
        $this->python = ProgrammingLanguage::factory()->create();

        $this->problem = Problem::factory()->for($this->section)->create([
            'activation_time' => now()->subDay(),
            'lock_time' => now()->addDay(),
            'max_submissions' => null,
        ]);
        $this->problem->programmingLanguages()->attach($this->python);
    }

    public function test_a_student_submits_from_the_editor(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => "print('xin chao')\n",
        ])->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'problem_id', 'creator', 'programming_language', 'file_name',
                    'execution_status', 'testcases_passed', 'testcases_total', 'is_outdated', 'created_at'],
            ]);

        $submission = Submission::findOrFail($response->json('data.id'));

        $this->assertSame($this->student->id, $submission->creator_id);
        $this->assertSame(ExecutionStatus::InQueue, $submission->execution_status);
        $this->assertSame('main.py', $submission->file_name);
        $this->assertSame(
            "submissions/{$this->problem->id}/{$submission->id}/main.py",
            $submission->file_path,
        );
        Storage::disk('minio')->assertExists($submission->file_path);
        $this->assertSame("print('xin chao')\n", Storage::disk('minio')->get($submission->file_path));
    }

    public function test_a_student_submits_a_file(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->post("/api/v1/problems/{$this->problem->id}/submissions/upload", [
            'programming_language_id' => $this->python->id,
            'file' => UploadedFile::fake()->createWithContent('Giải Thuật.py', "print(1)\n"),
        ])->assertCreated();

        $submission = Submission::findOrFail($response->json('data.id'));

        // The filename round-trips: sanitising is deliberately not a slug (C1).
        $this->assertSame('Giải Thuật.py', $submission->file_name);
        Storage::disk('minio')->assertExists($submission->file_path);
    }

    public function test_a_locked_problem_refuses_the_submission(): void
    {
        $this->problem->update(['lock_time' => now()->subHour()]);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertStatus(422)->assertJsonPath('code', 'DEADLINE_PASSED');

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_an_unpublished_problem_refuses_the_submission(): void
    {
        $this->problem->update(['activation_time' => now()->addDay()]);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertStatus(422)->assertJsonPath('code', 'DEADLINE_PASSED');
    }

    public function test_a_language_the_problem_does_not_allow_is_refused(): void
    {
        $cpp = ProgrammingLanguage::factory()->create(['name' => 'C++', 'file_extensions' => ['cpp', 'cc']]);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $cpp->id,
            'source_code' => 'int main() {}',
        ])->assertStatus(422)->assertJsonPath('code', 'LANGUAGE_NOT_ALLOWED');

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_the_quota_refuses_the_submission_past_the_limit(): void
    {
        $this->problem->update(['max_submissions' => 2]);
        Submission::factory()->count(2)->create([
            'problem_id' => $this->problem->id,
            'creator_id' => $this->student->id,
            'execution_status' => ExecutionStatus::Accepted,
        ]);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertStatus(422)->assertJsonPath('code', 'MAX_SUBMISSIONS');

        $this->assertSame(2, Submission::count());
    }

    /** Quota counts rows, not verdicts: a failed attempt still spends a try. */
    public function test_a_failed_attempt_counts_against_the_quota(): void
    {
        $this->problem->update(['max_submissions' => 1]);
        Submission::factory()->create([
            'problem_id' => $this->problem->id,
            'creator_id' => $this->student->id,
            'execution_status' => ExecutionStatus::Error,
        ]);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertStatus(422)->assertJsonPath('code', 'MAX_SUBMISSIONS');
    }

    /** v3 §7: one unfinished submission per student per problem. */
    public function test_an_unfinished_submission_blocks_the_next_one(): void
    {
        Submission::factory()->create([
            'problem_id' => $this->problem->id,
            'creator_id' => $this->student->id,
            'execution_status' => ExecutionStatus::Processing,
        ]);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertStatus(422)->assertJsonPath('code', 'SUBMISSION_PROCESSING');
    }

    /** The gate is per problem — two problems at once is ordinary work. */
    public function test_an_unfinished_submission_on_another_problem_does_not_block(): void
    {
        $other = Problem::factory()->for($this->section)->create([
            'activation_time' => now()->subDay(),
            'lock_time' => now()->addDay(),
        ]);
        Submission::factory()->create([
            'problem_id' => $other->id,
            'creator_id' => $this->student->id,
            'execution_status' => ExecutionStatus::InQueue,
        ]);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertCreated();
    }

    public function test_another_students_unfinished_submission_does_not_block(): void
    {
        $classmate = $this->sectionMember($this->section, SectionRole::Student);
        Submission::factory()->create([
            'problem_id' => $this->problem->id,
            'creator_id' => $classmate->id,
            'execution_status' => ExecutionStatus::InQueue,
        ]);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertCreated();
    }

    public function test_a_file_whose_extension_the_language_rejects_is_refused(): void
    {
        Sanctum::actingAs($this->student);

        $this->post("/api/v1/problems/{$this->problem->id}/submissions/upload", [
            'programming_language_id' => $this->python->id,
            'file' => UploadedFile::fake()->createWithContent('main.txt', 'print(1)'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_source_code_over_fifty_kilobytes_is_refused(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => str_repeat('x', 51201),
        ])->assertStatus(422);

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_staff_may_not_submit_to_their_own_section(): void
    {
        $instructor = $this->sectionMember($this->section, SectionRole::Instructor);
        Sanctum::actingAs($instructor);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertForbidden();
    }

    public function test_a_student_from_another_section_may_not_submit(): void
    {
        $outsider = $this->sectionMember($this->sectionIn($this->section->semester), SectionRole::Student);
        Sanctum::actingAs($outsider);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertForbidden();
    }

    public function test_a_guest_may_not_submit(): void
    {
        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertUnauthorized();
    }
}
