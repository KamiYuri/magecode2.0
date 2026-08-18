<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisService;
use App\Enums\AnalysisStatus;
use App\Enums\SectionRole;
use App\Enums\ServiceStatus;
use App\Events\AnalysisCompleted;
use App\Events\AnalysisProgress;
use App\Messaging\AnalysisResultHandler;
use App\Models\AnalysisProblem;
use App\Models\AnalysisSubmission;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Submission;
use App\Services\Analysis\SimResultIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * D6: when a batch is finished, and what the sections watching it hear.
 *
 * Results are staged through `AnalysisResultHandler`, the same door the
 * consumer uses, so the counts move exactly as they would in production.
 */
class BatchCompletionTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Semester $semester;

    private Section $section;

    private Problem $problem;

    private AnalysisProblem $batch;

    /** @var list<AnalysisSubmission> */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([AnalysisProgress::class, AnalysisCompleted::class]);

        $this->semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()));
        $this->section = $this->sectionIn($this->semester);
        $this->problem = Problem::factory()->for($this->section)->create();

        $this->batch = AnalysisProblem::factory()->create([
            'semester_id' => $this->semester->id,
            'bank_problem_id' => null,
            'manual_match_group_id' => (string) Str::uuid(),
            'triggered_by_problem_id' => $this->problem->id,
            'services' => [AnalysisService::AiDetector->value],
            'status' => AnalysisStatus::Processing,
        ]);

        $this->entries = [$this->enrol(), $this->enrol()];
    }

    public function test_a_submission_is_stamped_when_every_service_has_answered(): void
    {
        $this->aidResult($this->entries[0], 'completed');

        $this->assertNotNull($this->entries[0]->fresh()->completed_at);
        $this->assertNull($this->entries[1]->fresh()->completed_at);
    }

    /** `progress_percent` reads `completed_at`, so the two must agree. */
    public function test_progress_is_announced_as_each_submission_finishes(): void
    {
        $this->aidResult($this->entries[0], 'completed');

        Event::assertDispatchedTimes(AnalysisProgress::class, 1);
        Event::assertDispatched(AnalysisProgress::class, function (AnalysisProgress $event): bool {
            return $event->analysisProblemId === $this->batch->id
                && $event->completedCount === 1
                && $event->totalCount === 2
                && $event->sectionIds === [$this->section->id];
        });
        Event::assertNotDispatched(AnalysisCompleted::class);
    }

    public function test_the_batch_closes_when_the_last_submission_finishes(): void
    {
        $this->aidResult($this->entries[0], 'completed');
        $this->aidResult($this->entries[1], 'error');

        $batch = $this->batch->fresh();

        $this->assertSame(AnalysisStatus::Completed, $batch->status);
        $this->assertNotNull($batch->completed_at);

        Event::assertDispatchedTimes(AnalysisProgress::class, 2);
        Event::assertDispatchedTimes(AnalysisCompleted::class, 1);
        Event::assertDispatched(AnalysisCompleted::class, function (AnalysisCompleted $event): bool {
            return $event->status === AnalysisStatus::Completed;
        });
    }

    /**
     * `error` and `not_applicable` are as final as `completed` — a batch that
     * waited for them would never end.
     */
    public function test_a_service_nobody_asked_for_does_not_hold_the_batch_open(): void
    {
        $this->assertSame(ServiceStatus::NotApplicable, $this->entries[0]->vuln_scan_status);
        $this->assertSame(ServiceStatus::NotApplicable, $this->entries[0]->plagiarism_status);

        $this->aidResult($this->entries[0], 'not_applicable');
        $this->aidResult($this->entries[1], 'not_applicable');

        $this->assertSame(AnalysisStatus::Completed, $this->batch->fresh()->status);
    }

    /**
     * A redelivered message re-runs the whole ingest, so the closing update
     * has to be conditional or every repeat would announce the batch again.
     */
    public function test_a_message_delivered_again_after_closure_announces_nothing(): void
    {
        $this->aidResult($this->entries[0], 'completed');
        $this->aidResult($this->entries[1], 'completed');

        Event::assertDispatchedTimes(AnalysisCompleted::class, 1);

        $this->aidResult($this->entries[1], 'completed');

        Event::assertDispatchedTimes(AnalysisCompleted::class, 1);
        // Two, not three: the redelivery completed nothing that was not
        // already stamped, so there was no progress to announce.
        Event::assertDispatchedTimes(AnalysisProgress::class, 2);
    }

    /** The set is a SIM-phase signal; once the batch is over it says nothing. */
    public function test_closing_the_batch_forgets_the_sim_completion_key(): void
    {
        Cache::put(SimResultIngestor::COMPLETION_KEY.$this->batch->id, ['total' => 1, 'received' => [0]]);

        $this->aidResult($this->entries[0], 'completed');
        $this->aidResult($this->entries[1], 'completed');

        $this->assertNull(Cache::get(SimResultIngestor::COMPLETION_KEY.$this->batch->id));
    }

    /**
     * A batch is semester-wide (D-48), so an instructor watching L02 sees the
     * progress of a run triggered from L01.
     */
    public function test_every_section_in_scope_hears_the_frame(): void
    {
        $otherSection = $this->sectionIn($this->semester);
        $otherProblem = Problem::factory()->for($otherSection)->create();
        $this->enrol($otherProblem);

        $this->aidResult($this->entries[0], 'completed');

        Event::assertDispatched(AnalysisProgress::class, function (AnalysisProgress $event) use ($otherSection): bool {
            return $event->sectionIds === [$this->section->id, $otherSection->id];
        });
    }

    // ── Fixtures ──

    private function aidResult(AnalysisSubmission $entry, string $status): void
    {
        app(AnalysisResultHandler::class)->handle((string) json_encode([
            'analysis_submission_id' => $entry->id,
            'service' => AnalysisService::AiDetector->value,
            'status' => $status,
            'probability' => $status === 'completed' ? 0.5 : null,
            'trace_id' => (string) Str::uuid(),
            'timestamp' => '2026-08-18T14:00:00Z',
            'version' => '1.0',
        ]));
    }

    private function enrol(?Problem $problem = null): AnalysisSubmission
    {
        $problem ??= $this->problem;

        $submission = Submission::factory()->create([
            'problem_id' => $problem->id,
            'creator_id' => $this->sectionMember($problem->section, SectionRole::Student)->id,
        ]);

        return AnalysisSubmission::factory()->create([
            'analysis_problem_id' => $this->batch->id,
            'submission_id' => $submission->id,
            'plagiarism_status' => ServiceStatus::NotApplicable,
            'ai_detection_status' => ServiceStatus::InQueue,
            'vuln_scan_status' => ServiceStatus::NotApplicable,
        ]);
    }
}
