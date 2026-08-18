<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisService;
use App\Enums\AnalysisStatus;
use App\Enums\SectionRole;
use App\Enums\ServiceStatus;
use App\Events\AnalysisCompleted;
use App\Models\AiDetectionResult;
use App\Models\AnalysisProblem;
use App\Models\AnalysisSubmission;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * D7/D-82: a batch nobody answers for ends anyway, and what already arrived
 * survives that ending (D-59).
 */
class AnalysisTimeoutTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Semester $semester;

    private Section $section;

    private Problem $problem;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([AnalysisCompleted::class]);

        $this->semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()));
        $this->section = $this->sectionIn($this->semester);
        $this->problem = Problem::factory()->for($this->section)->create();
    }

    public function test_a_batch_past_thirty_minutes_times_out(): void
    {
        $batch = $this->processingBatch(startedMinutesAgo: 31);

        $this->sweep();

        $fresh = $batch->fresh();

        $this->assertSame(AnalysisStatus::Timeout, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_a_batch_still_inside_the_window_is_untouched(): void
    {
        $batch = $this->processingBatch(startedMinutesAgo: 29);

        $this->sweep();

        $this->assertSame(AnalysisStatus::Processing, $batch->fresh()->status);
        Event::assertNotDispatched(AnalysisCompleted::class);
    }

    /** The same channel and the same event: `status` is what carries the ending. */
    public function test_a_timeout_is_announced_to_the_sections(): void
    {
        $batch = $this->processingBatch(startedMinutesAgo: 45);

        $this->sweep();

        Event::assertDispatchedTimes(AnalysisCompleted::class, 1);
        Event::assertDispatched(AnalysisCompleted::class, function (AnalysisCompleted $event) use ($batch): bool {
            return $event->analysisProblemId === $batch->id
                && $event->status === AnalysisStatus::Timeout
                && $event->sectionIds === [$this->section->id];
        });
    }

    /** D-59: whatever arrived before the deadline is still there afterwards. */
    public function test_results_that_arrived_survive_the_timeout(): void
    {
        $batch = $this->processingBatch(startedMinutesAgo: 31);
        $answered = AnalysisSubmission::where('analysis_problem_id', $batch->id)->firstOrFail();

        $answered->update(['ai_detection_status' => ServiceStatus::Completed, 'completed_at' => now()]);
        AiDetectionResult::factory()->create(['analysis_submission_id' => $answered->id]);

        $this->sweep();

        $this->assertSame(AnalysisStatus::Timeout, $batch->fresh()->status);
        $this->assertSame(ServiceStatus::Completed, $answered->fresh()->ai_detection_status);
        $this->assertNotNull($answered->fresh()->completed_at);
        $this->assertSame(1, AiDetectionResult::count());
    }

    public function test_a_finished_batch_is_never_swept(): void
    {
        $batch = $this->processingBatch(startedMinutesAgo: 90);
        $batch->update(['status' => AnalysisStatus::Completed, 'completed_at' => now()->subHour()]);
        $closedAt = $batch->fresh()->completed_at;

        $this->sweep();

        $fresh = $batch->fresh();

        $this->assertSame(AnalysisStatus::Completed, $fresh->status);
        $this->assertEquals($closedAt, $fresh->completed_at, 'the original closing time must stand');
        Event::assertNotDispatched(AnalysisCompleted::class);
    }

    /** D-82's half-way complaint: loud in Loki, and it changes nothing. */
    public function test_a_batch_past_fifteen_minutes_is_warned_about_but_left_alone(): void
    {
        $batch = $this->processingBatch(startedMinutesAgo: 20);

        /** @var list<MessageLogged> $logged */
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $this->sweep();

        $this->assertSame(AnalysisStatus::Processing, $batch->fresh()->status);

        $warnings = array_values(array_filter(
            $logged,
            static fn (MessageLogged $event): bool => $event->level === 'warning',
        ));

        $this->assertCount(1, $warnings);
        $this->assertSame($batch->id, $warnings[0]->context['analysis_problem_id']);
    }

    // ── Fixtures ──

    private function sweep(): void
    {
        $this->artisan('analysis:sweep-timeouts')->assertSuccessful();
    }

    private function processingBatch(int $startedMinutesAgo): AnalysisProblem
    {
        $batch = AnalysisProblem::factory()->create([
            'semester_id' => $this->semester->id,
            'bank_problem_id' => null,
            'manual_match_group_id' => (string) Str::uuid(),
            'triggered_by_problem_id' => $this->problem->id,
            'services' => [AnalysisService::AiDetector->value],
            'status' => AnalysisStatus::Processing,
            'started_at' => now()->subMinutes($startedMinutesAgo),
            'completed_at' => null,
        ]);

        foreach (range(1, 2) as $ignored) {
            $submission = Submission::factory()->create([
                'problem_id' => $this->problem->id,
                'creator_id' => $this->sectionMember($this->section, SectionRole::Student)->id,
            ]);

            AnalysisSubmission::factory()->create([
                'analysis_problem_id' => $batch->id,
                'submission_id' => $submission->id,
                'plagiarism_status' => ServiceStatus::NotApplicable,
                'ai_detection_status' => ServiceStatus::InQueue,
                'vuln_scan_status' => ServiceStatus::NotApplicable,
            ]);
        }

        return $batch;
    }
}
