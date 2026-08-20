<?php

declare(strict_types=1);

namespace Tests\Feature\Submissions;

use App\Enums\ExecutionStatus;
use App\Enums\SectionRole;
use App\Events\ExecutionCompleted;
use App\Models\Problem;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * The execution half of D-82's idea: a submission nobody answers for has to
 * end anyway.
 *
 * D-82 gave analysis batches a sweeper and executions got none, so the two
 * unfinished states were both terminal in practice. A job whose retries are
 * spent dead-letters and leaves its row at `processing` forever; a publish
 * that the broker refuses is swallowed by C3 and leaves it at `in_queue`.
 * Either way the student's verdict strip waits for an `execution.completed`
 * that is never coming, and `execution_status = 'timeout'` -- which v3 §7
 * defines as exactly this and which nothing has ever written -- stays
 * unreachable.
 */
class SweepExecutionTimeoutsTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Problem $problem;

    protected function setUp(): void
    {
        parent::setUp();

        $section = $this->sectionIn($this->semesterIn($this->courseIn($this->organizationWithAdmin())));
        $this->sectionMember($section, SectionRole::Student);
        $this->problem = Problem::factory()->for($section)->create();
    }

    /** @return list<array{0: ExecutionStatus}> */
    public static function unfinishedStatuses(): array
    {
        return [[ExecutionStatus::InQueue], [ExecutionStatus::Processing]];
    }

    #[DataProvider('unfinishedStatuses')]
    public function test_an_unfinished_submission_past_the_deadline_times_out(ExecutionStatus $status): void
    {
        Event::fake([ExecutionCompleted::class]);

        $submission = $this->submissionStuckFor(90, $status);

        $this->artisan('submissions:sweep-timeouts')->assertSuccessful();

        $this->assertSame(
            ExecutionStatus::Timeout->value,
            $submission->fresh()?->execution_status->value
        );
    }

    public function test_the_student_is_told_so_their_strip_stops_waiting(): void
    {
        Event::fake([ExecutionCompleted::class]);

        $submission = $this->submissionStuckFor(90, ExecutionStatus::Processing, passed: 2, total: 5);

        $this->artisan('submissions:sweep-timeouts')->assertSuccessful();

        Event::assertDispatched(
            ExecutionCompleted::class,
            fn (ExecutionCompleted $event): bool => $event->submissionId === $submission->id
                && $event->finalStatus === ExecutionStatus::Timeout->value
                // The counters are the row's own: a run that graded two of
                // five before dying still shows those two (D-59's spirit).
                && $event->testcasesPassed === 2
                && $event->testcasesTotal === 5,
        );
    }

    public function test_a_submission_still_inside_the_window_is_left_alone(): void
    {
        Event::fake([ExecutionCompleted::class]);

        $submission = $this->submissionStuckFor(5, ExecutionStatus::Processing);

        $this->artisan('submissions:sweep-timeouts')->assertSuccessful();

        $this->assertSame(
            ExecutionStatus::Processing->value,
            $submission->fresh()?->execution_status->value
        );
        Event::assertNotDispatched(ExecutionCompleted::class);
    }

    /**
     * The counterpart of D7's conditional close: a run that finished between
     * the sweeper's query and its write keeps the verdict it earned.
     */
    public function test_a_finished_submission_is_never_overwritten(): void
    {
        Event::fake([ExecutionCompleted::class]);

        $submission = $this->submissionStuckFor(90, ExecutionStatus::Accepted, passed: 5, total: 5);

        $this->artisan('submissions:sweep-timeouts')->assertSuccessful();

        $this->assertSame(
            ExecutionStatus::Accepted->value,
            $submission->fresh()?->execution_status->value
        );
        Event::assertNotDispatched(ExecutionCompleted::class);
    }

    private function submissionStuckFor(
        int $minutes,
        ExecutionStatus $status,
        int $passed = 0,
        int $total = 0,
    ): Submission {
        $submission = Submission::factory()->create([
            'problem_id' => $this->problem->id,
            'execution_status' => $status->value,
            'testcases_passed' => $passed,
            'testcases_total' => $total,
        ]);

        // `updated_at` is when CES last touched the row -- MarkProcessing for
        // a run that started, the insert for one that never left the queue --
        // so it is what "stuck since" means here.
        $submission->forceFill(['updated_at' => now()->subMinutes($minutes)])->saveQuietly();

        return $submission;
    }
}
