<?php

declare(strict_types=1);

namespace Tests\Feature\Submissions;

use App\Enums\ExecutionStatus;
use App\Enums\PublishMode;
use App\Enums\SectionRole;
use App\Messaging\FakeJobPublisher;
use App\Messaging\JobPublisher;
use App\Messaging\Jobs\CodeExecutorJob;
use App\Messaging\PublishFailed;
use App\Models\Problem;
use App\Models\ProgrammingLanguage;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * C3: an accepted submission wakes CES, and a refused one does not.
 *
 * The publish is the last thing that happens and it happens after the commit,
 * so the two failure directions matter equally: a message for a submission
 * that does not exist is a job that can never succeed, and a submission with
 * no message sits at `in_queue` forever.
 */
class PublishesExecutionJobTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private FakeJobPublisher $publisher;

    private User $student;

    private Problem $problem;

    private ProgrammingLanguage $python;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');

        $this->publisher = new FakeJobPublisher;
        $this->app->instance(JobPublisher::class, $this->publisher);

        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()), [
            'publish_mode' => PublishMode::Auto,
            'lock_mode' => PublishMode::Auto,
        ]);
        $section = $this->sectionIn($semester);
        $this->student = $this->sectionMember($section, SectionRole::Student);
        $this->python = ProgrammingLanguage::factory()->create();

        $this->problem = Problem::factory()->for($section)->create([
            'activation_time' => now()->subDay(),
            'lock_time' => now()->addDay(),
        ]);
        $this->problem->programmingLanguages()->attach($this->python);
    }

    public function test_an_accepted_submission_publishes_one_execution_job(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => "print(1)\n",
        ])->assertCreated();

        $message = $this->publisher->assertPublishedOnce(CodeExecutorJob::QUEUE);

        $this->assertInstanceOf(CodeExecutorJob::class, $message);
        $this->assertSame($response->json('data.id'), $message->submissionId);
        $this->assertNotSame('', $message->traceId());
    }

    public function test_an_uploaded_submission_publishes_too(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->post("/api/v1/problems/{$this->problem->id}/submissions/upload", [
            'programming_language_id' => $this->python->id,
            'file' => UploadedFile::fake()->createWithContent('main.py', "print(1)\n"),
        ])->assertCreated();

        $message = $this->publisher->assertPublishedOnce(CodeExecutorJob::QUEUE);

        $this->assertInstanceOf(CodeExecutorJob::class, $message);
        $this->assertSame($response->json('data.id'), $message->submissionId);
    }

    /** No row, no job: a message naming nothing is a job that never succeeds. */
    public function test_a_refused_submission_publishes_nothing(): void
    {
        $this->problem->update(['lock_time' => now()->subHour()]);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertStatus(422);

        $this->publisher->assertNothingPublished();
    }

    public function test_a_submission_over_quota_publishes_nothing(): void
    {
        $this->problem->update(['max_submissions' => 1]);
        Submission::factory()->create([
            'problem_id' => $this->problem->id,
            'creator_id' => $this->student->id,
            'execution_status' => ExecutionStatus::Accepted,
        ]);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => 'print(1)',
        ])->assertStatus(422);

        $this->publisher->assertNothingPublished();
    }

    /**
     * v3 §7: the submission is already durable when the broker is asked, so a
     * broker that refuses must not turn an accepted submission into a
     * rejection the student sees.
     */
    public function test_a_broker_failure_still_accepts_the_submission(): void
    {
        /** @var list<MessageLogged> $logged */
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $this->publisher->failure = PublishFailed::forQueue(CodeExecutorJob::QUEUE);
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/v1/problems/{$this->problem->id}/submissions", [
            'programming_language_id' => $this->python->id,
            'source_code' => "print(1)\n",
        ])->assertCreated();

        $submission = Submission::findOrFail($response->json('data.id'));

        $this->assertSame(ExecutionStatus::InQueue, $submission->execution_status);
        Storage::disk('minio')->assertExists($submission->file_path);

        // Greppable in Loki (D-88): the id is what a later sweep needs.
        $errors = array_values(array_filter(
            $logged,
            static fn (MessageLogged $event): bool => $event->level === 'error',
        ));

        $this->assertCount(1, $errors, 'the failed publish must be logged exactly once');
        $this->assertSame($submission->id, $errors[0]->context['submission_id']);
        $this->assertArrayHasKey('trace_id', $errors[0]->context);
        $this->assertSame(CodeExecutorJob::QUEUE, $errors[0]->context['queue']);
    }
}
