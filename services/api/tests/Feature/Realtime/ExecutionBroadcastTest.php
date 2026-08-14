<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Enums\ExecutionStatus;
use App\Enums\SectionRole;
use App\Events\ExecutionCompleted;
use App\Events\ExecutionUpdated;
use App\Messaging\ExecutionResultHandler;
use App\Messaging\InvalidResultMessage;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * C7: what CES puts on `result-execution` becomes what the student's browser
 * receives.
 *
 * The handler is exercised directly rather than through a running consumer —
 * the queue plumbing is `shared/go/rmq`'s job and already has its own tests;
 * what belongs here is the translation from message to broadcast.
 */
class ExecutionBroadcastTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Submission $submission;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()));
        $section = $this->sectionIn($semester);
        $this->student = $this->sectionMember($section, SectionRole::Student);
        $problem = Problem::factory()->for($section)->create();

        $this->submission = Submission::factory()->create([
            'problem_id' => $problem->id,
            'creator_id' => $this->student->id,
        ]);
    }

    public function test_a_progress_message_broadcasts_execution_updated(): void
    {
        Event::fake([ExecutionUpdated::class, ExecutionCompleted::class]);

        $this->handle([
            'submission_id' => $this->submission->id,
            'service' => 'code-executor',
            'status' => 'processing',
            'execution_status' => 'processing',
            'testcases_passed' => 1,
            'testcases_total' => 3,
            'latest_result' => ['test_case_order' => 0, 'status' => 'accepted'],
            'trace_id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            'timestamp' => '2026-08-14T09:30:12Z',
            'version' => '1.0',
        ]);

        Event::assertDispatched(ExecutionUpdated::class, function (ExecutionUpdated $event): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === 'private-submission.'.$this->submission->id
                && $event->broadcastAs() === 'execution.updated'
                && $payload['submission_id'] === $this->submission->id
                && $payload['testcases_passed'] === 1
                && $payload['testcases_total'] === 3
                && $payload['latest_result']['test_case_order'] === 0
                && $payload['latest_result']['status'] === 'accepted';
        });
        Event::assertNotDispatched(ExecutionCompleted::class);
    }

    public function test_a_completed_message_broadcasts_execution_completed(): void
    {
        Event::fake([ExecutionUpdated::class, ExecutionCompleted::class]);

        $this->handle($this->completedPayload());

        Event::assertDispatched(ExecutionCompleted::class, function (ExecutionCompleted $event): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === 'private-submission.'.$this->submission->id
                && $event->broadcastAs() === 'execution.completed'
                && $payload['final_status'] === 'partially_accepted'
                && $payload['testcases_passed'] === 7
                && $payload['testcases_total'] === 10;
        });
        Event::assertNotDispatched(ExecutionUpdated::class);
    }

    /**
     * CES has already written the verdict (D-81), so the message is a signal
     * and not the source of truth — the handler must not write it back.
     */
    public function test_the_handler_does_not_write_to_the_submission(): void
    {
        Event::fake();
        $this->submission->update(['execution_status' => ExecutionStatus::Processing]);

        $this->handle($this->completedPayload());

        $this->assertSame(
            ExecutionStatus::Processing,
            $this->submission->fresh()->execution_status,
            'api must not overwrite what CES wrote',
        );
    }

    /**
     * A message for a submission that is gone is dropped, not fatal — and it
     * is logged, so the drop is visible rather than silent.
     */
    public function test_an_unknown_submission_is_ignored(): void
    {
        Event::fake([ExecutionUpdated::class, ExecutionCompleted::class]);

        /** @var list<MessageLogged> $logged */
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $this->handle(['submission_id' => 999_999] + $this->completedPayload());

        Event::assertNotDispatched(ExecutionUpdated::class);
        Event::assertNotDispatched(ExecutionCompleted::class);

        $warnings = array_filter($logged, static fn (MessageLogged $e): bool => $e->level === 'warning');
        $this->assertCount(1, $warnings, 'a dropped message must be visible, not silent');
    }

    public function test_a_malformed_message_is_rejected(): void
    {
        Event::fake([ExecutionUpdated::class, ExecutionCompleted::class]);

        $this->expectException(InvalidResultMessage::class);
        $this->handle(['submission_id' => $this->submission->id, 'status' => 'completed']);
    }

    /** @return array<string, mixed> */
    private function completedPayload(): array
    {
        return [
            'submission_id' => $this->submission->id,
            'service' => 'code-executor',
            'status' => 'completed',
            'execution_status' => 'partially_accepted',
            'testcases_passed' => 7,
            'testcases_total' => 10,
            'trace_id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            'timestamp' => '2026-08-14T09:30:12Z',
            'version' => '1.0',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function handle(array $payload): void
    {
        app(ExecutionResultHandler::class)->handle((string) json_encode($payload));
    }
}
