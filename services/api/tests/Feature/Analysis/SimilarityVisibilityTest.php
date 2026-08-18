<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisService;
use App\Enums\AnalysisStatus;
use App\Enums\MatchType;
use App\Enums\OrganizationRole;
use App\Enums\SectionRole;
use App\Enums\ServiceStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * D-05/D-06, enforced.
 *
 * The batch is semester-wide, so any instructor teaching in the semester may
 * open it — which makes "who may read this side's code" a per-viewer, per-side
 * question, not a property of the row. This suite is the specification of that
 * answer, and it is the one place a mistake cannot be walked back: source code
 * handed to the wrong instructor cannot be un-shown.
 *
 * | Viewer                     | a_source_code | b_source_code |
 * |----------------------------|---------------|---------------|
 * | Instructor of L01 (side A) | yes           | null          |
 * | Instructor of L02 (side B) | null          | yes           |
 * | Instructor of L03          | null          | null          |
 * | Org Admin                  | yes           | yes           |
 */
class SimilarityVisibilityTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private const SOURCE_A = "print('written in L01')\n";

    private const SOURCE_B = "print('written in L02')\n";

    private Organization $organization;

    private Semester $semester;

    private Section $sectionA;

    private Section $sectionB;

    private Section $sectionC;

    private User $instructorA;

    private User $instructorB;

    private User $instructorC;

    private User $orgAdmin;

    private AnalysisProblem $batch;

    private SimilarityResult $crossSectionPair;

    private Submission $submissionA;

    private Submission $submissionB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');

        $this->organization = $this->organizationWithAdmin();
        $this->semester = $this->semesterIn($this->courseIn($this->organization));

        $this->sectionA = $this->sectionIn($this->semester);
        $this->sectionB = $this->sectionIn($this->semester);
        $this->sectionC = $this->sectionIn($this->semester);

        $this->instructorA = $this->sectionMember($this->sectionA, SectionRole::Instructor);
        $this->instructorB = $this->sectionMember($this->sectionB, SectionRole::Instructor);
        $this->instructorC = $this->sectionMember($this->sectionC, SectionRole::Instructor);
        $this->orgAdmin = $this->organizationAdmin();

        $groupId = (string) Str::uuid();
        $problemA = $this->problemIn($this->sectionA, $groupId);
        $problemB = $this->problemIn($this->sectionB, $groupId);

        $this->batch = AnalysisProblem::factory()->create([
            'semester_id' => $this->semester->id,
            'bank_problem_id' => null,
            'manual_match_group_id' => $groupId,
            'triggered_by_problem_id' => $problemA->id,
            'analyst_id' => $this->instructorA->id,
            'services' => [AnalysisService::PlagiarismChecker->value],
            'status' => AnalysisStatus::Completed,
        ]);

        $this->submissionA = $this->submissionIn($problemA, self::SOURCE_A);
        $this->submissionB = $this->submissionIn($problemB, self::SOURCE_B);

        $this->crossSectionPair = $this->pair($this->submissionA, $this->submissionB, MatchType::CrossSection);
    }

    // ── The detail surface: the only place code is shown ──

    public function test_the_instructor_of_side_a_reads_their_own_code_only(): void
    {
        Sanctum::actingAs($this->instructorA);

        $this->detail()
            ->assertOk()
            ->assertJsonPath('data.a_source_code', self::SOURCE_A)
            ->assertJsonPath('data.b_source_code', null);
    }

    public function test_the_instructor_of_side_b_reads_their_own_code_only(): void
    {
        Sanctum::actingAs($this->instructorB);

        $this->detail()
            ->assertOk()
            ->assertJsonPath('data.a_source_code', null)
            ->assertJsonPath('data.b_source_code', self::SOURCE_B);
    }

    /**
     * The case no decision covered (v3 §7, 2026-08-18): an instructor who
     * teaches in the semester but owns neither side. The row is theirs to see;
     * neither student's code is.
     */
    public function test_an_instructor_of_neither_section_reads_no_code(): void
    {
        Sanctum::actingAs($this->instructorC);

        $this->detail()
            ->assertOk()
            ->assertJsonPath('data.a_source_code', null)
            ->assertJsonPath('data.b_source_code', null)
            ->assertJsonPath('data.similarity', 0.9)
            ->assertJsonPath('data.match_type', MatchType::CrossSection->value);
    }

    /** The Full tier: an Org Admin is the one reader who sees both sides. */
    public function test_the_organization_admin_reads_both_sides(): void
    {
        Sanctum::actingAs($this->orgAdmin);

        $this->detail()
            ->assertOk()
            ->assertJsonPath('data.a_source_code', self::SOURCE_A)
            ->assertJsonPath('data.b_source_code', self::SOURCE_B);
    }

    /**
     * A highlight without its code points into something the reader may not
     * see, so the regions follow their own side.
     */
    public function test_the_regions_follow_the_code_they_describe(): void
    {
        Sanctum::actingAs($this->instructorA);

        $this->detail()
            ->assertOk()
            ->assertJsonPath('data.a_regions', '1,0,4,10')
            ->assertJsonPath('data.b_regions', null);
    }

    /** A within-section pair is the Within tier: both sides, to that section's instructor. */
    public function test_a_within_section_pair_shows_both_sides_to_its_own_instructor(): void
    {
        $second = $this->submissionIn($this->submissionA->problem, "print('also L01')\n");
        $pair = $this->pair($this->submissionA, $second, MatchType::WithinSection);

        Sanctum::actingAs($this->instructorA);

        $this->detail($pair)
            ->assertOk()
            ->assertJsonPath('data.a_source_code', self::SOURCE_A)
            ->assertJsonPath('data.b_source_code', "print('also L01')\n");
    }

    public function test_a_within_section_pair_of_another_section_shows_no_code(): void
    {
        $second = $this->submissionIn($this->submissionB->problem, "print('also L02')\n");
        $pair = $this->pair($this->submissionB, $second, MatchType::WithinSection);

        Sanctum::actingAs($this->instructorA);

        $this->detail($pair)
            ->assertOk()
            ->assertJsonPath('data.a_source_code', null)
            ->assertJsonPath('data.b_source_code', null);
    }

    // ── The list surface: rows yes, code never ──

    public function test_the_listing_carries_no_source_for_anybody(): void
    {
        Sanctum::actingAs($this->orgAdmin);

        $response = $this->getJson("/api/v1/analysis/{$this->batch->id}/similarity")->assertOk();

        $this->assertArrayNotHasKey('a_source_code', $response->json('data.0'));
        $this->assertArrayNotHasKey('b_source_code', $response->json('data.0'));
    }

    /** Both students are named to every instructor in the semester (decision above). */
    public function test_the_listing_names_both_students_to_an_uninvolved_instructor(): void
    {
        Sanctum::actingAs($this->instructorC);

        $this->getJson("/api/v1/analysis/{$this->batch->id}/similarity")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.submission_a.id', $this->submissionA->id)
            ->assertJsonPath('data.0.submission_b.id', $this->submissionB->id)
            ->assertJsonPath('data.0.submission_a.section_name', $this->sectionA->name)
            ->assertJsonPath('data.0.submission_b.section_name', $this->sectionB->name);
    }

    // ── The include that must not widen anything ──

    /**
     * openapi states it in as many words: `?include=source_code` never
     * bypasses two-tier redaction. This is the negative that matters most —
     * an opt-in flag is exactly how a redaction rule gets lost.
     */
    public function test_include_source_code_does_not_widen_the_submission_listing(): void
    {
        $this->enrol($this->submissionA);
        $this->enrol($this->submissionB);

        Sanctum::actingAs($this->instructorA);

        $response = $this->getJson(
            "/api/v1/analysis/{$this->batch->id}/submissions?include=source_code"
        )->assertOk();

        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json('data');
        $bySection = [];

        foreach ($rows as $row) {
            $bySection[(int) $row['section_id']] = $row;
        }

        $this->assertSame(self::SOURCE_A, $bySection[$this->sectionA->id]['source_code']);
        $this->assertNull($bySection[$this->sectionB->id]['source_code'], 'the other section stays redacted');
    }

    public function test_the_submission_listing_omits_source_unless_asked(): void
    {
        $this->enrol($this->submissionA);

        Sanctum::actingAs($this->instructorA);

        $row = $this->getJson("/api/v1/analysis/{$this->batch->id}/submissions")->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('source_code', $row);
    }

    // ── Who may not be here at all ──

    public function test_a_teaching_assistant_is_refused_the_whole_surface(): void
    {
        Sanctum::actingAs($this->sectionMember($this->sectionA, SectionRole::Ta));

        $this->detail()->assertForbidden();
        $this->getJson("/api/v1/analysis/{$this->batch->id}/similarity")->assertForbidden();
        $this->getJson("/api/v1/analysis/{$this->batch->id}/submissions")->assertForbidden();
    }

    public function test_a_student_is_refused_the_whole_surface(): void
    {
        Sanctum::actingAs($this->sectionMember($this->sectionA, SectionRole::Student));

        $this->detail()->assertForbidden();
        $this->getJson("/api/v1/analysis/{$this->batch->id}/similarity")->assertForbidden();
    }

    /** An instructor of another semester has no business here at all. */
    public function test_an_instructor_outside_the_semester_is_refused(): void
    {
        $elsewhere = $this->sectionIn($this->semesterIn($this->courseIn($this->organization)));

        Sanctum::actingAs($this->sectionMember($elsewhere, SectionRole::Instructor));

        $this->detail()->assertForbidden();
    }

    /** A pair from another batch is not reachable through this one's id. */
    public function test_a_result_from_another_batch_is_not_found(): void
    {
        $other = AnalysisProblem::factory()->create([
            'semester_id' => $this->semester->id,
            'bank_problem_id' => null,
            'manual_match_group_id' => (string) Str::uuid(),
            'triggered_by_problem_id' => $this->submissionA->problem_id,
            'analyst_id' => $this->instructorA->id,
            'services' => [AnalysisService::PlagiarismChecker->value],
        ]);

        Sanctum::actingAs($this->instructorA);

        $this->getJson("/api/v1/analysis/{$other->id}/similarity/{$this->crossSectionPair->id}")
            ->assertNotFound();
    }

    // ── Fixtures ──

    /** @return TestResponse<Response> */
    private function detail(?SimilarityResult $pair = null): TestResponse
    {
        $pair ??= $this->crossSectionPair;

        return $this->getJson("/api/v1/analysis/{$this->batch->id}/similarity/{$pair->id}");
    }

    private function organizationAdmin(): User
    {
        $admin = User::factory()->create();

        OrganizationMember::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $admin->id,
            'role' => OrganizationRole::Admin,
        ]);

        return $admin;
    }

    private function problemIn(Section $section, string $groupId): Problem
    {
        return Problem::factory()->for($section)->create([
            'bank_problem_id' => null,
            'manual_match_group_id' => $groupId,
        ]);
    }

    private function submissionIn(Problem $problem, string $source): Submission
    {
        $student = $this->sectionMember($problem->section, SectionRole::Student);
        $path = "submissions/{$problem->id}/{$student->id}/main.py";

        Storage::disk('minio')->put($path, $source);

        return Submission::factory()->create([
            'problem_id' => $problem->id,
            'creator_id' => $student->id,
            'file_path' => $path,
        ]);
    }

    private function pair(Submission $a, Submission $b, MatchType $matchType): SimilarityResult
    {
        return SimilarityResult::factory()->create([
            'analysis_problem_id' => $this->batch->id,
            'submission_a_id' => min($a->id, $b->id),
            'submission_b_id' => max($a->id, $b->id),
            'similarity' => 0.9,
            'match_type' => $matchType,
            'a_regions' => '1,0,4,10',
            'b_regions' => '2,0,5,10',
        ]);
    }

    private function enrol(Submission $submission): AnalysisSubmission
    {
        return AnalysisSubmission::factory()->create([
            'analysis_problem_id' => $this->batch->id,
            'submission_id' => $submission->id,
            'plagiarism_status' => ServiceStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
