<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisService;
use App\Enums\AnalysisStatus;
use App\Enums\OrganizationRole;
use App\Enums\SectionRole;
use App\Enums\ServiceStatus;
use App\Models\AnalysisProblem;
use App\Models\AnalysisSubmission;
use App\Models\BankProblem;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\Support\FakesAnalysisBroadcasts;
use Tests\TestCase;

/**
 * D8's plain reads: which batch is current, what ran before, stopping one, and
 * D-58's manual grouping. Nothing here carries source code — that surface is
 * `SimilarityVisibilityTest`.
 */
class AnalysisReadTest extends TestCase
{
    use CreatesAcademicFixtures;
    use FakesAnalysisBroadcasts;
    use RefreshDatabase;

    private Organization $organization;

    private Semester $semester;

    private Section $section;

    private User $instructor;

    private Problem $problem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAnalysisBroadcasts();

        $this->organization = $this->organizationWithAdmin();
        $this->semester = $this->semesterIn($this->courseIn($this->organization));
        $this->section = $this->sectionIn($this->semester);
        $this->instructor = $this->sectionMember($this->section, SectionRole::Instructor);
        $this->problem = Problem::factory()->for($this->section)->create([
            'manual_match_group_id' => (string) Str::uuid(),
        ]);
    }

    // ── GET /problems/{id}/analysis ──

    public function test_the_latest_batch_of_the_scope_is_returned(): void
    {
        $old = $this->batch(['is_latest' => false, 'status' => AnalysisStatus::Completed]);
        $current = $this->batch();

        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/problems/{$this->problem->id}/analysis")
            ->assertOk()
            ->assertJsonPath('data.id', $current->id)
            ->assertJsonStructure(['data' => ['id', 'status', 'submissions_count', 'completed_count', 'progress_percent']]);

        $this->assertNotSame($old->id, $current->id);
    }

    /** The batch is semester-wide, so it may have been triggered next door. */
    public function test_a_batch_triggered_from_a_sibling_problem_is_still_the_latest_here(): void
    {
        $sibling = Problem::factory()->for($this->sectionIn($this->semester))->create([
            'manual_match_group_id' => $this->problem->manual_match_group_id,
        ]);
        $batch = $this->batch(['triggered_by_problem_id' => $sibling->id]);

        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/problems/{$this->problem->id}/analysis")
            ->assertOk()
            ->assertJsonPath('data.id', $batch->id);
    }

    public function test_a_problem_never_analysed_answers_404(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/problems/{$this->problem->id}/analysis")->assertNotFound();
    }

    /** `completed_count` is the column D6 writes, so the two must agree. */
    public function test_the_progress_counters_come_from_the_stamped_rows(): void
    {
        $batch = $this->batch();
        $this->enrol($batch, completed: true);
        $this->enrol($batch, completed: false);

        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/problems/{$this->problem->id}/analysis")
            ->assertOk()
            ->assertJsonPath('data.submissions_count', 2)
            ->assertJsonPath('data.completed_count', 1)
            ->assertJsonPath('data.progress_percent', 50);
    }

    public function test_a_student_may_not_read_the_batch(): void
    {
        $this->batch();
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Student));

        $this->getJson("/api/v1/problems/{$this->problem->id}/analysis")->assertForbidden();
    }

    /** Analysis names students; the TA exclusion is absolute (v3 §7, 2026-08-12). */
    public function test_a_teaching_assistant_may_not_read_the_batch(): void
    {
        $this->batch();
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Ta));

        $this->getJson("/api/v1/problems/{$this->problem->id}/analysis")->assertForbidden();
    }

    // ── GET /problems/{id}/analysis/history ──

    public function test_the_history_lists_every_run_newest_first(): void
    {
        $older = $this->batch(['is_latest' => false, 'status' => AnalysisStatus::Completed, 'created_at' => now()->subDay()]);
        $newer = $this->batch();

        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/problems/{$this->problem->id}/analysis/history")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonMissingPath('meta');
    }

    /** A problem with no scope yet has no history — that is not an error. */
    public function test_a_problem_with_no_scope_has_an_empty_history(): void
    {
        $this->problem->update(['manual_match_group_id' => null]);
        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/problems/{$this->problem->id}/analysis/history")
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_a_student_may_not_read_the_history(): void
    {
        $this->batch();
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Student));

        $this->getJson("/api/v1/problems/{$this->problem->id}/analysis/history")->assertForbidden();
    }

    // ── POST /analysis/{id}/cancel ──

    public function test_cancelling_ends_the_batch_as_a_timeout(): void
    {
        $batch = $this->batch();
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/analysis/{$batch->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', AnalysisStatus::Timeout->value);

        $this->assertNotNull($batch->fresh()->completed_at);
    }

    /** D-59: what already arrived is kept. */
    public function test_cancelling_keeps_the_results_that_arrived(): void
    {
        $batch = $this->batch();
        $entry = $this->enrol($batch, completed: true);

        Sanctum::actingAs($this->instructor);
        $this->postJson("/api/v1/analysis/{$batch->id}/cancel")->assertOk();

        $this->assertSame(ServiceStatus::Completed, $entry->fresh()->plagiarism_status);
        $this->assertNotNull($entry->fresh()->completed_at);
    }

    public function test_cancelling_a_finished_batch_is_refused(): void
    {
        $batch = $this->batch(['status' => AnalysisStatus::Completed, 'completed_at' => now()]);
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/analysis/{$batch->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ANALYSIS_NOT_PROCESSING');
    }

    public function test_a_student_may_not_cancel(): void
    {
        $batch = $this->batch();
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Student));

        $this->postJson("/api/v1/analysis/{$batch->id}/cancel")->assertForbidden();
        $this->assertSame(AnalysisStatus::Processing, $batch->fresh()->status);
    }

    // ── Match groups ──

    public function test_problems_are_linked_under_one_group(): void
    {
        [$a, $b] = [$this->ungroupedProblem(), $this->ungroupedProblem()];
        Sanctum::actingAs($this->instructor);

        $response = $this->postJson("/api/v1/semesters/{$this->semester->id}/match-groups", [
            'problem_ids' => [$a->id, $b->id],
        ])->assertOk()->assertJsonPath('data.problems_linked', 2);

        $groupId = $response->json('data.manual_match_group_id');

        $this->assertTrue(Str::isUuid($groupId));
        $this->assertSame($groupId, $a->fresh()->manual_match_group_id);
        $this->assertSame($groupId, $b->fresh()->manual_match_group_id);
    }

    public function test_a_problem_from_another_semester_is_refused(): void
    {
        $outsider = Problem::factory()
            ->for($this->sectionIn($this->semesterIn($this->courseIn($this->organization))))
            ->create(['manual_match_group_id' => null]);

        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/semesters/{$this->semester->id}/match-groups", [
            'problem_ids' => [$this->ungroupedProblem()->id, $outsider->id],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MATCH_GROUP_OUTSIDE_SEMESTER');
    }

    /** `chk_analysis_scope` is XOR: a bank problem is already matched. */
    public function test_a_bank_problem_may_not_be_grouped_manually(): void
    {
        $bank = BankProblem::factory()->create([
            'course_id' => $this->semester->course_id,
            'author_id' => $this->instructor->id,
        ]);
        $cloned = Problem::factory()->for($this->section)->create([
            'bank_problem_id' => $bank->id,
            'manual_match_group_id' => null,
        ]);

        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/semesters/{$this->semester->id}/match-groups", [
            'problem_ids' => [$this->ungroupedProblem()->id, $cloned->id],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MATCH_GROUP_BANK_PROBLEM');
    }

    /** Regrouping mid-run would move the ground under a batch already reading it. */
    public function test_a_problem_being_analysed_may_not_be_regrouped(): void
    {
        $this->batch();
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/semesters/{$this->semester->id}/match-groups", [
            'problem_ids' => [$this->problem->id, $this->ungroupedProblem()->id],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MATCH_GROUP_ANALYSIS_IN_PROGRESS');
    }

    public function test_one_problem_is_not_a_group(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/semesters/{$this->semester->id}/match-groups", [
            'problem_ids' => [$this->ungroupedProblem()->id],
        ])->assertStatus(422);
    }

    public function test_the_listing_groups_problems_by_their_shared_id(): void
    {
        [$a, $b] = [$this->ungroupedProblem(), $this->ungroupedProblem()];
        Sanctum::actingAs($this->instructor);

        $groupId = $this->postJson("/api/v1/semesters/{$this->semester->id}/match-groups", [
            'problem_ids' => [$a->id, $b->id],
        ])->json('data.manual_match_group_id');

        $response = $this->getJson("/api/v1/semesters/{$this->semester->id}/match-groups")->assertOk();

        /** @var list<array{manual_match_group_id: string, problems: list<array<string, mixed>>}> $groups */
        $groups = $response->json('data');
        $linked = array_values(array_filter(
            $groups,
            static fn (array $group): bool => $group['manual_match_group_id'] === $groupId,
        ));

        $this->assertCount(2, $groups, 'the setUp problem carries a group of its own');
        $this->assertCount(1, $linked);
        $this->assertCount(2, $linked[0]['problems']);
        $this->assertSame(
            ['problem_id', 'problem_name', 'section_id', 'section_name'],
            array_keys($linked[0]['problems'][0]),
        );
    }

    public function test_an_organization_admin_may_manage_groups(): void
    {
        $admin = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $admin->id,
            'role' => OrganizationRole::Admin,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/semesters/{$this->semester->id}/match-groups")->assertOk();
    }

    public function test_a_student_may_not_manage_groups(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Student));

        $this->getJson("/api/v1/semesters/{$this->semester->id}/match-groups")->assertForbidden();
        $this->postJson("/api/v1/semesters/{$this->semester->id}/match-groups", [
            'problem_ids' => [$this->ungroupedProblem()->id, $this->ungroupedProblem()->id],
        ])->assertForbidden();
    }

    // ── Fixtures ──

    /** @param array<string, mixed> $overrides */
    private function batch(array $overrides = []): AnalysisProblem
    {
        return AnalysisProblem::factory()->create($overrides + [
            'semester_id' => $this->semester->id,
            'bank_problem_id' => null,
            'manual_match_group_id' => $this->problem->manual_match_group_id,
            'triggered_by_problem_id' => $this->problem->id,
            'analyst_id' => $this->instructor->id,
            'services' => [AnalysisService::PlagiarismChecker->value],
            'status' => AnalysisStatus::Processing,
            'is_latest' => ! array_key_exists('is_latest', $overrides),
            'started_at' => now()->subMinute(),
            'completed_at' => null,
        ]);
    }

    private function enrol(AnalysisProblem $batch, bool $completed): AnalysisSubmission
    {
        $submission = Submission::factory()->create([
            'problem_id' => $this->problem->id,
            'creator_id' => $this->sectionMember($this->section, SectionRole::Student)->id,
        ]);

        return AnalysisSubmission::factory()->create([
            'analysis_problem_id' => $batch->id,
            'submission_id' => $submission->id,
            'plagiarism_status' => $completed ? ServiceStatus::Completed : ServiceStatus::InQueue,
            'completed_at' => $completed ? now() : null,
        ]);
    }

    private function ungroupedProblem(): Problem
    {
        return Problem::factory()->for($this->section)->create([
            'bank_problem_id' => null,
            'manual_match_group_id' => null,
        ]);
    }
}
