<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisService;
use App\Enums\AnalysisStatus;
use App\Enums\MatchType;
use App\Enums\OrganizationRole;
use App\Enums\SectionRole;
use App\Enums\ServiceStatus;
use App\Enums\Severity;
use App\Models\AiDetectionResult;
use App\Models\AnalysisProblem;
use App\Models\AnalysisSubmission;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Semester;
use App\Models\SimilarityResult;
use App\Models\Submission;
use App\Models\User;
use App\Models\VulnerabilityResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * The filters, the ordering and the `flagged` bar.
 *
 * `flagged` is the field the UI colours a row from, and D-62 makes the bar a
 * per-semester setting — so the tests move the threshold rather than asserting
 * a constant, which is the only way to prove the column is read at all.
 */
class AnalysisResultListTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Organization $organization;

    private Semester $semester;

    private Section $sectionA;

    private Section $sectionB;

    private User $instructor;

    private AnalysisProblem $batch;

    private Problem $problemA;

    private Problem $problemB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');

        $this->organization = $this->organizationWithAdmin();
        $this->semester = $this->semesterIn($this->courseIn($this->organization), [
            'similarity_threshold' => 0.70,
            'ai_detection_threshold' => 0.80,
        ]);
        $this->sectionA = $this->sectionIn($this->semester);
        $this->sectionB = $this->sectionIn($this->semester);
        $this->instructor = $this->sectionMember($this->sectionA, SectionRole::Instructor);

        $groupId = (string) Str::uuid();
        $this->problemA = Problem::factory()->for($this->sectionA)->create(['manual_match_group_id' => $groupId]);
        $this->problemB = Problem::factory()->for($this->sectionB)->create(['manual_match_group_id' => $groupId]);

        $this->batch = AnalysisProblem::factory()->create([
            'semester_id' => $this->semester->id,
            'bank_problem_id' => null,
            'manual_match_group_id' => $groupId,
            'triggered_by_problem_id' => $this->problemA->id,
            'analyst_id' => $this->instructor->id,
            'services' => [AnalysisService::PlagiarismChecker->value, AnalysisService::AiDetector->value],
            'status' => AnalysisStatus::Completed,
        ]);
    }

    // ── Similarity ──

    public function test_pairs_come_back_most_similar_first(): void
    {
        $this->pair(0.40, MatchType::WithinSection);
        $this->pair(0.95, MatchType::CrossSection);
        $this->pair(0.72, MatchType::WithinSection);

        Sanctum::actingAs($this->instructor);

        $this->similarity()
            ->assertOk()
            ->assertJsonPath('data.0.similarity', 0.95)
            ->assertJsonPath('data.1.similarity', 0.72)
            ->assertJsonPath('data.2.similarity', 0.4)
            ->assertJsonStructure(['meta' => ['next_cursor', 'prev_cursor', 'per_page', 'has_more']]);
    }

    /** D-62: the bar belongs to the semester, so moving it moves the flag. */
    public function test_flagged_follows_the_semesters_similarity_threshold(): void
    {
        $this->pair(0.75, MatchType::CrossSection);
        Sanctum::actingAs($this->instructor);

        $this->similarity()->assertOk()->assertJsonPath('data.0.flagged', true);

        $this->semester->update(['similarity_threshold' => 0.80]);

        $this->similarity()->assertOk()->assertJsonPath('data.0.flagged', false);
    }

    public function test_pairs_can_be_filtered_by_match_type(): void
    {
        $this->pair(0.9, MatchType::CrossSection);
        $this->pair(0.8, MatchType::WithinSection);

        Sanctum::actingAs($this->instructor);

        $this->similarity('?match_type=CROSS_SECTION')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.match_type', MatchType::CrossSection->value);
    }

    public function test_pairs_can_be_filtered_by_minimum_similarity(): void
    {
        $this->pair(0.95, MatchType::CrossSection);
        $this->pair(0.30, MatchType::CrossSection);

        Sanctum::actingAs($this->instructor);

        $this->similarity('?min_similarity=0.5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.similarity', 0.95);
    }

    /** Either side of a pair puts it in that section's view. */
    public function test_a_section_filter_keeps_pairs_that_touch_that_section(): void
    {
        $this->pair(0.9, MatchType::CrossSection);
        $this->pairWithin($this->problemB, 0.6);

        Sanctum::actingAs($this->instructor);

        $this->similarity("?section_id={$this->sectionA->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->similarity("?section_id={$this->sectionB->id}")->assertOk()->assertJsonCount(2, 'data');
    }

    // ── AI detection ──

    public function test_ai_results_come_back_most_probable_first(): void
    {
        $this->aiResult($this->problemA, 0.42);
        $this->aiResult($this->problemA, 0.91);

        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/analysis/{$this->batch->id}/ai-detection")
            ->assertOk()
            ->assertJsonPath('data.0.probability', 0.91)
            ->assertJsonPath('data.0.flagged', true)
            ->assertJsonPath('data.1.flagged', false)
            ->assertJsonStructure(['data' => [['id', 'analysis_submission_id', 'student', 'section_name', 'probability', 'flagged']]]);
    }

    public function test_flagged_only_uses_the_semesters_ai_threshold(): void
    {
        $this->aiResult($this->problemA, 0.85);
        $this->aiResult($this->problemA, 0.50);

        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/analysis/{$this->batch->id}/ai-detection?flagged_only=1")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->semester->update(['ai_detection_threshold' => 0.40]);

        $this->getJson("/api/v1/analysis/{$this->batch->id}/ai-detection?flagged_only=1")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_ai_results_can_be_filtered_by_section(): void
    {
        $this->aiResult($this->problemA, 0.9);
        $this->aiResult($this->problemB, 0.9);

        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/analysis/{$this->batch->id}/ai-detection?section_id={$this->sectionB->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.section_name', $this->sectionB->name);
    }

    // ── Vulnerabilities ──

    public function test_findings_are_listed_with_their_location(): void
    {
        $entry = $this->enrol($this->problemA);
        VulnerabilityResult::factory()->create([
            'analysis_submission_id' => $entry->id,
            'name' => 'py/sql-injection',
            'severity' => Severity::Error,
            'file_path' => 'main.py',
            'start_line' => 12,
        ]);

        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/analysis/{$this->batch->id}/vulnerabilities")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'py/sql-injection')
            ->assertJsonPath('data.0.severity', Severity::Error->value)
            ->assertJsonPath('data.0.start_line', 12);
    }

    /** Findings of another batch stay in that batch. */
    public function test_findings_are_scoped_to_their_batch(): void
    {
        $other = AnalysisProblem::factory()->create([
            'semester_id' => $this->semester->id,
            'bank_problem_id' => null,
            'manual_match_group_id' => (string) Str::uuid(),
            'triggered_by_problem_id' => $this->problemA->id,
            'analyst_id' => $this->instructor->id,
            'services' => [AnalysisService::VulnScanner->value],
        ]);

        $entry = AnalysisSubmission::factory()->create([
            'analysis_problem_id' => $other->id,
            'submission_id' => $this->submissionIn($this->problemA)->id,
        ]);
        VulnerabilityResult::factory()->create(['analysis_submission_id' => $entry->id]);

        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/analysis/{$this->batch->id}/vulnerabilities")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── Submissions listing ──

    public function test_the_submission_listing_reports_each_service_and_its_counts(): void
    {
        $entry = $this->enrol($this->problemA);
        $entry->update(['ai_detection_status' => ServiceStatus::Completed]);
        AiDetectionResult::factory()->create(['analysis_submission_id' => $entry->id, 'probability' => 0.91]);
        VulnerabilityResult::factory()->count(2)->create(['analysis_submission_id' => $entry->id]);

        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/analysis/{$this->batch->id}/submissions")
            ->assertOk()
            ->assertJsonPath('data.0.plagiarism_status', ServiceStatus::Completed->value)
            ->assertJsonPath('data.0.ai_detection_probability', 0.91)
            ->assertJsonPath('data.0.ai_detection_flagged', true)
            ->assertJsonPath('data.0.vulnerability_count', 2)
            ->assertJsonPath('data.0.section_name', $this->sectionA->name);
    }

    public function test_a_submission_with_no_ai_verdict_reports_null_rather_than_false(): void
    {
        $this->enrol($this->problemA);
        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/analysis/{$this->batch->id}/submissions")
            ->assertOk()
            ->assertJsonPath('data.0.ai_detection_probability', null)
            ->assertJsonPath('data.0.ai_detection_flagged', null);
    }

    // ── Overview ──

    public function test_the_overview_counts_the_semester(): void
    {
        $this->pair(0.95, MatchType::CrossSection);
        $this->pair(0.10, MatchType::WithinSection);
        $entry = $this->enrol($this->problemA);
        AiDetectionResult::factory()->create(['analysis_submission_id' => $entry->id, 'probability' => 0.9]);
        VulnerabilityResult::factory()->create(['analysis_submission_id' => $entry->id]);

        Sanctum::actingAs($this->orgAdmin());

        $this->getJson("/api/v1/semesters/{$this->semester->id}/analysis-overview")
            ->assertOk()
            ->assertJsonPath('data.semester_id', $this->semester->id)
            ->assertJsonPath('data.similarity_flagged_count', 1)
            ->assertJsonPath('data.ai_detection_flagged_count', 1)
            ->assertJsonPath('data.vulnerability_count', 1)
            ->assertJsonStructure([
                'data' => ['total_problems', 'analyzed_problems', 'total_submissions_analyzed', 'per_section'],
            ]);
    }

    /**
     * The dashboard aggregates every section, which is the cross-section
     * picture D-05/D-06 keep from an individual instructor.
     */
    public function test_only_an_organization_admin_may_read_the_overview(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/semesters/{$this->semester->id}/analysis-overview")->assertForbidden();
    }

    // ── Fixtures ──

    /** @return TestResponse<Response> */
    private function similarity(string $query = ''): TestResponse
    {
        return $this->getJson("/api/v1/analysis/{$this->batch->id}/similarity{$query}");
    }

    private function orgAdmin(): User
    {
        $admin = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $admin->id,
            'role' => OrganizationRole::Admin,
        ]);

        return $admin;
    }

    /** A cross-section pair between the two problems. */
    private function pair(float $similarity, MatchType $matchType): SimilarityResult
    {
        $a = $this->submissionIn($this->problemA);
        $b = $this->submissionIn($this->problemB);

        return SimilarityResult::factory()->create([
            'analysis_problem_id' => $this->batch->id,
            'submission_a_id' => min($a->id, $b->id),
            'submission_b_id' => max($a->id, $b->id),
            'similarity' => $similarity,
            'match_type' => $matchType,
        ]);
    }

    private function pairWithin(Problem $problem, float $similarity): SimilarityResult
    {
        $a = $this->submissionIn($problem);
        $b = $this->submissionIn($problem);

        return SimilarityResult::factory()->create([
            'analysis_problem_id' => $this->batch->id,
            'submission_a_id' => min($a->id, $b->id),
            'submission_b_id' => max($a->id, $b->id),
            'similarity' => $similarity,
            'match_type' => MatchType::WithinSection,
        ]);
    }

    private function aiResult(Problem $problem, float $probability): AiDetectionResult
    {
        return AiDetectionResult::factory()->create([
            'analysis_submission_id' => $this->enrol($problem)->id,
            'probability' => $probability,
        ]);
    }

    private function enrol(Problem $problem): AnalysisSubmission
    {
        return AnalysisSubmission::factory()->create([
            'analysis_problem_id' => $this->batch->id,
            'submission_id' => $this->submissionIn($problem)->id,
            'plagiarism_status' => ServiceStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    private function submissionIn(Problem $problem): Submission
    {
        return Submission::factory()->create([
            'problem_id' => $problem->id,
            'creator_id' => $this->sectionMember($problem->section, SectionRole::Student)->id,
        ]);
    }
}
