<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\PublishMode;
use App\Enums\SectionRole;
use App\Enums\ServiceStatus;
use App\Models\AnalysisProblem;
use App\Models\AnalysisSubmission;
use App\Models\BankProblem;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * D1: the door into Plan D. Everything downstream reads the rows this endpoint
 * writes, so the cases here are about *which* rows exist afterwards, not about
 * the response shape alone.
 */
class TriggerAnalysisTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Semester $semester;

    private Section $section;

    private User $instructor;

    private Problem $problem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()), [
            'publish_mode' => PublishMode::Auto,
            'lock_mode' => PublishMode::Auto,
        ]);
        $this->section = $this->sectionIn($this->semester);
        $this->instructor = $this->sectionMember($this->section, SectionRole::Instructor);
        $this->problem = $this->lockedProblem($this->section);
    }

    public function test_an_instructor_triggers_a_batch(): void
    {
        $this->submissionsFor($this->problem, 2);
        Sanctum::actingAs($this->instructor);

        $response = $this->trigger()
            ->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'semester_id', 'triggered_by_problem_id', 'analyst', 'services',
                    'status', 'is_latest', 'is_partial', 'submissions_count', 'completed_count',
                    'progress_percent', 'started_at', 'completed_at', 'created_at'],
            ]);

        $batch = AnalysisProblem::findOrFail($response->json('data.id'));

        $this->assertSame($this->semester->id, $batch->semester_id);
        $this->assertSame($this->problem->id, $batch->triggered_by_problem_id);
        $this->assertSame($this->instructor->id, $batch->analyst_id);
        $this->assertSame(AnalysisStatus::Processing, $batch->status);
        $this->assertTrue($batch->is_latest);
        $this->assertSame(2, $batch->analysisSubmissions()->count());
    }

    /**
     * The point of Phương án B: a batch spans every equivalent problem in the
     * semester, not just the one that was clicked.
     */
    public function test_the_scope_covers_equivalent_problems_across_sections(): void
    {
        $bankProblem = BankProblem::factory()->create([
            'course_id' => $this->semester->course_id,
            'author_id' => $this->instructor->id,
        ]);
        $this->problem->update(['bank_problem_id' => $bankProblem->id]);
        $this->submissionsFor($this->problem, 2);

        $sibling = $this->lockedProblem($this->sectionIn($this->semester), $bankProblem->id);
        $this->submissionsFor($sibling, 3);

        Sanctum::actingAs($this->instructor);

        $response = $this->trigger()->assertCreated();
        $batch = AnalysisProblem::findOrFail($response->json('data.id'));

        $this->assertSame($bankProblem->id, $batch->bank_problem_id);
        $this->assertNull($batch->manual_match_group_id, 'scope is exactly one identifier');
        $this->assertSame(5, $batch->analysisSubmissions()->count());
    }

    /** D-49: one submission per student, the newest. */
    public function test_only_the_latest_submission_of_each_student_enters(): void
    {
        $student = $this->sectionMember($this->section, SectionRole::Student);
        $this->submissionFrom($this->problem, $student, minutesAgo: 30);
        $newest = $this->submissionFrom($this->problem, $student, minutesAgo: 1);

        Sanctum::actingAs($this->instructor);
        $response = $this->trigger()->assertCreated();

        $entries = AnalysisSubmission::where('analysis_problem_id', $response->json('data.id'))->get();

        $this->assertCount(1, $entries);
        $this->assertSame($newest->id, $entries->first()->submission_id);
    }

    /**
     * v3 §7: a problem with neither identifier gets a one-problem match group,
     * and the *same* one on a second trigger — a fresh UUID each time would
     * make every trigger a different scope and `is_latest` meaningless.
     */
    public function test_a_problem_with_no_scope_gets_a_match_group_that_is_then_reused(): void
    {
        $this->submissionsFor($this->problem, 1);
        Sanctum::actingAs($this->instructor);

        $first = $this->trigger()->assertCreated()->json('data.id');
        $generated = $this->problem->fresh()->manual_match_group_id;

        $this->assertNotNull($generated, 'the trigger must give the problem a scope');

        // A batch still processing is a 409 whatever `force` says, so it has
        // to finish before the second trigger can test scope reuse.
        AnalysisProblem::whereKey($first)->update(['status' => AnalysisStatus::Completed]);

        $this->trigger(['force' => true])->assertCreated();

        $this->assertSame($generated, $this->problem->fresh()->manual_match_group_id);
        $this->assertSame(2, AnalysisProblem::where('manual_match_group_id', $generated)->count());
    }

    /** v3 §7: results already exist, so the compute is not spent again. */
    public function test_a_completed_batch_is_returned_instead_of_rerun(): void
    {
        $this->submissionsFor($this->problem, 1);
        Sanctum::actingAs($this->instructor);

        $first = $this->trigger()->assertCreated()->json('data.id');
        AnalysisProblem::whereKey($first)->update([
            'status' => AnalysisStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->trigger()
            ->assertOk()
            ->assertJsonPath('data.id', $first);

        $this->assertSame(1, AnalysisProblem::count(), 'no second batch may be created');
    }

    public function test_force_reruns_a_completed_batch(): void
    {
        $this->submissionsFor($this->problem, 1);
        Sanctum::actingAs($this->instructor);

        $first = $this->trigger()->assertCreated()->json('data.id');
        AnalysisProblem::whereKey($first)->update(['status' => AnalysisStatus::Completed]);

        $second = $this->trigger(['force' => true])->assertCreated()->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertFalse(AnalysisProblem::findOrFail($first)->is_latest, 'D-53: the old batch steps down');
        $this->assertTrue(AnalysisProblem::findOrFail($second)->is_latest);
    }

    /** Asking for a service the completed batch never ran is a different request. */
    public function test_a_different_service_set_starts_a_new_batch(): void
    {
        $this->submissionsFor($this->problem, 1);
        Sanctum::actingAs($this->instructor);

        $first = $this->trigger(['services' => ['plagiarism-checker']])->assertCreated()->json('data.id');
        AnalysisProblem::whereKey($first)->update(['status' => AnalysisStatus::Completed]);

        $this->trigger(['services' => ['plagiarism-checker', 'ai-detector']])->assertCreated();

        $this->assertSame(2, AnalysisProblem::count());
    }

    public function test_a_batch_already_processing_is_a_conflict(): void
    {
        $this->submissionsFor($this->problem, 1);
        Sanctum::actingAs($this->instructor);

        $first = $this->trigger()->assertCreated()->json('data.id');

        $this->trigger()
            ->assertStatus(409)
            ->assertJsonPath('code', 'ANALYSIS_IN_PROGRESS')
            ->assertJsonPath('analysis_problem_id', $first);

        $this->assertSame(1, AnalysisProblem::count());
    }

    public function test_a_scope_with_no_submissions_is_refused(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->trigger()
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_SUBMISSIONS');

        $this->assertSame(0, AnalysisProblem::count());
        $this->assertNull(
            $this->problem->fresh()->manual_match_group_id,
            'a refused trigger must not leave the problem carrying a group with no batch',
        );
    }

    /** D-57: students can still submit, so the results may be incomplete. */
    public function test_an_unlocked_problem_in_scope_marks_the_batch_partial(): void
    {
        $this->problem->update(['lock_time' => now()->addDay()]);
        $this->submissionsFor($this->problem, 1);
        Sanctum::actingAs($this->instructor);

        $response = $this->trigger()->assertCreated();

        $this->assertTrue(AnalysisProblem::findOrFail($response->json('data.id'))->is_partial);
    }

    public function test_a_fully_locked_scope_is_not_partial(): void
    {
        $this->submissionsFor($this->problem, 1);
        Sanctum::actingAs($this->instructor);

        $response = $this->trigger()->assertCreated();

        $this->assertFalse(AnalysisProblem::findOrFail($response->json('data.id'))->is_partial);
    }

    /**
     * The lock that matters is the effective one (amendment 2026-08-13): a
     * sibling still open makes the whole batch partial even when the clicked
     * problem is closed.
     */
    public function test_an_unlocked_sibling_makes_the_batch_partial(): void
    {
        $bankProblem = BankProblem::factory()->create([
            'course_id' => $this->semester->course_id,
            'author_id' => $this->instructor->id,
        ]);
        $this->problem->update(['bank_problem_id' => $bankProblem->id]);
        $this->submissionsFor($this->problem, 1);

        $open = $this->lockedProblem($this->sectionIn($this->semester), $bankProblem->id);
        $open->update(['lock_time' => now()->addDay()]);

        Sanctum::actingAs($this->instructor);
        $response = $this->trigger()->assertCreated();

        $this->assertTrue(AnalysisProblem::findOrFail($response->json('data.id'))->is_partial);
    }

    /** D-54: a service that was not selected starts as not_applicable. */
    public function test_unselected_services_are_marked_not_applicable(): void
    {
        $this->submissionsFor($this->problem, 1);
        Sanctum::actingAs($this->instructor);

        $response = $this->trigger(['services' => ['plagiarism-checker']])->assertCreated();
        $entry = AnalysisSubmission::where('analysis_problem_id', $response->json('data.id'))->firstOrFail();

        $this->assertSame(ServiceStatus::InQueue, $entry->plagiarism_status);
        $this->assertSame(ServiceStatus::NotApplicable, $entry->ai_detection_status);
        $this->assertSame(ServiceStatus::NotApplicable, $entry->vuln_scan_status);
    }

    public function test_a_student_may_not_trigger(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Student));

        $this->trigger()->assertForbidden();
    }

    public function test_a_teaching_assistant_may_not_trigger(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Ta));

        $this->trigger()->assertForbidden();
    }

    public function test_the_service_list_is_validated(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->trigger(['services' => []])->assertStatus(422);
        $this->trigger(['services' => ['nonsense']])->assertStatus(422);
    }

    /** @param array<string, mixed> $overrides */
    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function trigger(array $overrides = []): TestResponse
    {
        return $this->postJson(
            "/api/v1/problems/{$this->problem->id}/analysis",
            $overrides + ['services' => ['plagiarism-checker', 'ai-detector']],
        );
    }

    private function lockedProblem(Section $section, ?int $bankProblemId = null): Problem
    {
        return Problem::factory()->for($section)->create([
            'bank_problem_id' => $bankProblemId,
            'activation_time' => now()->subMonth(),
            'lock_time' => now()->subDay(),
        ]);
    }

    private function submissionsFor(Problem $problem, int $students): void
    {
        for ($i = 0; $i < $students; $i++) {
            $this->submissionFrom($problem, $this->sectionMember($problem->section, SectionRole::Student));
        }
    }

    private function submissionFrom(Problem $problem, User $student, int $minutesAgo = 0): Submission
    {
        return Submission::factory()->create([
            'problem_id' => $problem->id,
            'creator_id' => $student->id,
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}
