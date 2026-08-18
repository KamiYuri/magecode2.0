<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\PublishMode;
use App\Enums\SectionRole;
use App\Enums\ServiceStatus;
use App\Messaging\FakeJobPublisher;
use App\Messaging\JobPublisher;
use App\Messaging\Jobs\PlagiarismCheckerJob;
use App\Messaging\PublishFailed;
use App\Messaging\QueueMessage;
use App\Models\AnalysisProblem;
use App\Models\AnalysisSubmission;
use App\Models\Problem;
use App\Models\ProgrammingLanguage;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * D2 over HTTP: what the trigger endpoint hands to the broker, and what it
 * writes for the submissions no message can serve.
 *
 * The grouping rule itself is pinned without a database in `SimJobPlanTest`.
 * What is checked here is everything that unit cannot see — the join that
 * produces the refs, the pre-signed URLs, the `not_applicable` writes, and the
 * rule that publishing happens after the commit and only for a batch this
 * request created.
 */
class SimJobPublishingTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Semester $semester;

    private Section $section;

    private User $instructor;

    private Problem $problem;

    private FakeJobPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');
        // The faked disk is a local one, whose temporaryUrl() throws; the
        // callback is how a test sees the URL the dispatcher signed.
        Storage::disk('minio')->buildTemporaryUrlsUsing(
            fn (string $path): string => "https://minio.test/{$path}?signed=1"
        );

        $this->publisher = new FakeJobPublisher;
        $this->app->instance(JobPublisher::class, $this->publisher);

        $this->semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()), [
            'publish_mode' => PublishMode::Auto,
            'lock_mode' => PublishMode::Auto,
        ]);
        $this->section = $this->sectionIn($this->semester);
        $this->instructor = $this->sectionMember($this->section, SectionRole::Instructor);
        $this->problem = $this->lockedProblem();
    }

    public function test_each_qualifying_language_becomes_one_message(): void
    {
        $this->submissionsIn('python', 2);
        $this->submissionsIn('java', 3);

        Sanctum::actingAs($this->instructor);
        $batchId = (int) $this->trigger()->assertCreated()->json('data.id');

        $messages = $this->publisher->published(PlagiarismCheckerJob::QUEUE);
        $this->assertCount(2, $messages);

        $payloads = array_map(static fn (QueueMessage $m): array => $m->toArray(), $messages);

        $this->assertSame(['java', 'python'], array_column($payloads, 'language'));
        $this->assertSame([0, 1], array_column($payloads, 'language_group_index'));
        $this->assertSame([2, 2], array_column($payloads, 'language_group_total'));
        $this->assertSame([$batchId, $batchId], array_column($payloads, 'analysis_problem_id'));
        $this->assertCount(3, $payloads[0]['submissions'], 'java carries its three submissions');
        $this->assertCount(2, $payloads[1]['submissions']);
    }

    public function test_every_submission_carries_a_presigned_url_for_its_own_object(): void
    {
        $submissions = $this->submissionsIn('python', 2);

        Sanctum::actingAs($this->instructor);
        $this->trigger()->assertCreated();

        $payload = $this->publisher->assertPublishedOnce(PlagiarismCheckerJob::QUEUE)->toArray();
        $entries = AnalysisSubmission::query()->orderBy('id')->get()->keyBy('submission_id');

        foreach ($submissions as $submission) {
            $expected = [
                'submission_id' => $submission->id,
                'analysis_submission_id' => $entries[$submission->id]->id,
                'file_url' => "https://minio.test/{$submission->file_path}?signed=1",
            ];

            $this->assertContains($expected, $payload['submissions']);
        }
    }

    /** Dolos compares pairs, so a language only one student used has nothing to run. */
    public function test_a_language_with_one_submission_is_parked_as_not_applicable(): void
    {
        $this->submissionsIn('python', 2);
        $lonely = $this->submissionsIn('java', 1)[0];

        Sanctum::actingAs($this->instructor);
        $this->trigger()->assertCreated();

        $payload = $this->publisher->assertPublishedOnce(PlagiarismCheckerJob::QUEUE)->toArray();

        $this->assertSame('python', $payload['language']);
        $this->assertSame(1, $payload['language_group_total']);
        $this->assertSame(ServiceStatus::NotApplicable, $this->entryFor($lonely)->plagiarism_status);
        $this->assertSame(2, AnalysisSubmission::where('plagiarism_status', ServiceStatus::InQueue)->count());
    }

    /**
     * A language `programming_languages` leaves null, or names in a way the job
     * schema's enum does not carry, can never be published — the submission
     * parks rather than waiting for a message that is not coming.
     */
    public function test_a_language_dolos_cannot_parse_is_parked(): void
    {
        $this->submissionsIn(null, 2);
        $this->submissionsIn('javascript', 2);

        Sanctum::actingAs($this->instructor);
        $this->trigger()->assertCreated();

        $this->publisher->assertNothingPublished();
        $this->assertSame(4, AnalysisSubmission::where('plagiarism_status', ServiceStatus::NotApplicable)->count());
    }

    /**
     * D6 owns `analysis_problems.status`; D2 only writes the per-submission
     * statuses, so a batch with nothing to publish stays `processing` until
     * D6 lands. Asserted rather than assumed, so the day D6 changes it, this
     * test says so.
     */
    public function test_a_batch_with_no_qualifying_group_stays_processing(): void
    {
        $this->submissionsIn('python', 1);

        Sanctum::actingAs($this->instructor);
        $batchId = (int) $this->trigger()->assertCreated()->json('data.id');

        $this->publisher->assertNothingPublished();
        $this->assertSame(AnalysisStatus::Processing, AnalysisProblem::findOrFail($batchId)->status);
    }

    public function test_a_batch_without_sim_publishes_nothing(): void
    {
        $this->submissionsIn('python', 2);

        Sanctum::actingAs($this->instructor);
        $this->trigger(['services' => ['ai-detector']])->assertCreated();

        $this->publisher->assertNothingPublished();
        $this->assertSame(2, AnalysisSubmission::where('plagiarism_status', ServiceStatus::NotApplicable)->count());
    }

    /** The 200-with-existing branch of D1 runs nothing again, so it publishes nothing. */
    public function test_a_returned_completed_batch_publishes_nothing(): void
    {
        $this->submissionsIn('python', 2);
        Sanctum::actingAs($this->instructor);

        $first = (int) $this->trigger()->assertCreated()->json('data.id');
        AnalysisProblem::whereKey($first)->update([
            'status' => AnalysisStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->trigger()->assertOk()->assertJsonPath('data.id', $first);

        $this->assertCount(1, $this->publisher->published(PlagiarismCheckerJob::QUEUE), 'only the first trigger published');
    }

    /**
     * A broker that refuses one group is not a reason to drop the others: the
     * batch is already durable, so the failure is logged and those submissions
     * wait at `in_queue` for D7's sweeper (v3 §7, 2026-08-14).
     */
    public function test_a_group_the_broker_refuses_leaves_its_submissions_in_queue(): void
    {
        $this->submissionsIn('java', 2);
        $this->submissionsIn('python', 2);

        $publisher = new class extends FakeJobPublisher
        {
            private int $seen = 0;

            public function publish(QueueMessage $message): void
            {
                if ($this->seen++ === 0) {
                    throw PublishFailed::forQueue($message->queue(), new RuntimeException('broker down'));
                }

                parent::publish($message);
            }
        };
        $this->app->instance(JobPublisher::class, $publisher);

        /** @var list<MessageLogged> $logged */
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        Sanctum::actingAs($this->instructor);
        $this->trigger()->assertCreated();

        $published = $publisher->assertPublishedOnce(PlagiarismCheckerJob::QUEUE)->toArray();

        $this->assertSame('python', $published['language'], 'the group after the failure still went out');
        $this->assertSame(4, AnalysisSubmission::where('plagiarism_status', ServiceStatus::InQueue)->count());

        // Greppable in Loki (D-88): the batch and the language are what a
        // later sweep needs to know which group never went out.
        $errors = array_values(array_filter(
            $logged,
            static fn (MessageLogged $event): bool => $event->level === 'error',
        ));

        $this->assertCount(1, $errors, 'the failed publish must be logged exactly once');
        $this->assertSame('java', $errors[0]->context['language']);
        $this->assertSame(PlagiarismCheckerJob::QUEUE, $errors[0]->context['queue']);
        $this->assertArrayHasKey('trace_id', $errors[0]->context);
    }

    // ── Fixtures ──

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function trigger(array $overrides = []): TestResponse
    {
        return $this->postJson(
            "/api/v1/problems/{$this->problem->id}/analysis",
            $overrides + ['services' => ['plagiarism-checker']],
        );
    }

    private function lockedProblem(): Problem
    {
        return Problem::factory()->for($this->section)->create([
            'activation_time' => now()->subMonth(),
            'lock_time' => now()->subDay(),
        ]);
    }

    /**
     * `$students` submissions, one per student, all in a language whose
     * `dolos_language` is the given value.
     *
     * @return list<Submission>
     */
    private function submissionsIn(?string $dolosLanguage, int $students): array
    {
        $language = ProgrammingLanguage::factory()->create([
            'name' => 'Language '.($dolosLanguage ?? 'none').' '.ProgrammingLanguage::count(),
            'dolos_language' => $dolosLanguage,
        ]);

        $submissions = [];

        for ($i = 0; $i < $students; $i++) {
            $student = $this->sectionMember($this->section, SectionRole::Student);

            $submissions[] = Submission::factory()->create([
                'problem_id' => $this->problem->id,
                'creator_id' => $student->id,
                'programming_language_id' => $language->id,
                'file_path' => "submissions/{$this->problem->id}/{$student->id}/main.txt",
            ]);
        }

        return $submissions;
    }

    private function entryFor(Submission $submission): AnalysisSubmission
    {
        return AnalysisSubmission::where('submission_id', $submission->id)->firstOrFail();
    }
}
