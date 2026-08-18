<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\PublishMode;
use App\Enums\SectionRole;
use App\Enums\ServiceStatus;
use App\Messaging\FakeJobPublisher;
use App\Messaging\JobPublisher;
use App\Messaging\Jobs\AiDetectorJob;
use App\Messaging\Jobs\VulnScannerJob;
use App\Messaging\PublishFailed;
use App\Messaging\QueueMessage;
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
 * D3: one message per submission for AID and VUL, and the language gates that
 * decide which submissions get one at all.
 *
 * SIM's grouping lives in `SimJobPublishingTest`; the two services here have
 * no grouping to get wrong, so what is worth pinning is the gating — a
 * submission nothing will analyse must be closed as `not_applicable` rather
 * than left waiting for a result that is not coming.
 */
class AnalysisJobPublishingTest extends TestCase
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
        $this->problem = Problem::factory()->for($this->section)->create([
            'activation_time' => now()->subMonth(),
            'lock_time' => now()->subDay(),
        ]);
    }

    public function test_each_submission_becomes_one_message_per_requested_service(): void
    {
        $submissions = $this->submissionsIn(['monaco_language' => 'python', 'codeql_language' => 'python'], 2);

        Sanctum::actingAs($this->instructor);
        $this->trigger(['services' => ['ai-detector', 'vuln-scanner']])->assertCreated();

        $this->assertCount(2, $this->publisher->published(AiDetectorJob::QUEUE));
        $this->assertCount(2, $this->publisher->published(VulnScannerJob::QUEUE));

        $entries = AnalysisSubmission::query()->get()->keyBy('submission_id');
        $payload = $this->publisher->published(AiDetectorJob::QUEUE)[0]->toArray();
        $first = $submissions[0];

        $this->assertSame($entries[$first->id]->id, $payload['analysis_submission_id']);
        $this->assertSame($first->id, $payload['submission_id']);
        $this->assertSame('python', $payload['language']);
        $this->assertSame("https://minio.test/{$first->file_path}?signed=1", $payload['file_url']);
    }

    /** A single submission is enough for both: neither service compares anything. */
    public function test_one_submission_is_analysed_where_sim_would_have_nothing_to_compare(): void
    {
        $this->submissionsIn(['monaco_language' => 'python', 'codeql_language' => 'python'], 1);

        Sanctum::actingAs($this->instructor);
        $this->trigger(['services' => ['plagiarism-checker', 'ai-detector', 'vuln-scanner']])->assertCreated();

        $this->assertCount(1, $this->publisher->published(AiDetectorJob::QUEUE));
        $this->assertCount(1, $this->publisher->published(VulnScannerJob::QUEUE));

        $entry = AnalysisSubmission::firstOrFail();
        $this->assertSame(ServiceStatus::NotApplicable, $entry->plagiarism_status, 'nothing to compare against');
        $this->assertSame(ServiceStatus::InQueue, $entry->ai_detection_status);
        $this->assertSame(ServiceStatus::InQueue, $entry->vuln_scan_status);
    }

    /** The roadmap's explicit gate: no CodeQL analyser, no message. */
    public function test_a_language_codeql_cannot_scan_is_parked(): void
    {
        $this->submissionsIn(['monaco_language' => 'python', 'codeql_language' => null], 1);

        Sanctum::actingAs($this->instructor);
        $this->trigger(['services' => ['ai-detector', 'vuln-scanner']])->assertCreated();

        $this->assertCount(1, $this->publisher->published(AiDetectorJob::QUEUE));
        $this->assertSame([], $this->publisher->published(VulnScannerJob::QUEUE));

        $entry = AnalysisSubmission::firstOrFail();
        $this->assertSame(ServiceStatus::NotApplicable, $entry->vuln_scan_status);
        $this->assertSame(ServiceStatus::InQueue, $entry->ai_detection_status, 'AID is gated separately');
    }

    /**
     * CodeQL analyses C through its `cpp` pack, and the mapping is data: the
     * seeded C row already carries `codeql_language = cpp`.
     */
    public function test_c_is_scanned_as_cpp(): void
    {
        $this->submissionsIn(['monaco_language' => 'c', 'codeql_language' => 'cpp'], 1);

        Sanctum::actingAs($this->instructor);
        $this->trigger(['services' => ['ai-detector', 'vuln-scanner']])->assertCreated();

        $this->assertSame('cpp', $this->publisher->assertPublishedOnce(VulnScannerJob::QUEUE)->toArray()['language']);
        $this->assertSame('c', $this->publisher->assertPublishedOnce(AiDetectorJob::QUEUE)->toArray()['language']);
    }

    /**
     * `monaco_language` is what AID routes on (v3 §7, 2026-08-18) and it is a
     * free-form varchar: a value the job schema's enum does not carry parks
     * rather than publishing a message AID would reject.
     */
    public function test_a_language_outside_the_ai_detector_enum_is_parked(): void
    {
        $this->submissionsIn(['monaco_language' => 'javascript', 'codeql_language' => 'javascript'], 1);

        Sanctum::actingAs($this->instructor);
        $this->trigger(['services' => ['ai-detector', 'vuln-scanner']])->assertCreated();

        $this->publisher->assertNothingPublished();

        $entry = AnalysisSubmission::firstOrFail();
        $this->assertSame(ServiceStatus::NotApplicable, $entry->ai_detection_status);
        $this->assertSame(ServiceStatus::NotApplicable, $entry->vuln_scan_status);
    }

    public function test_a_service_that_was_not_requested_publishes_nothing(): void
    {
        $this->submissionsIn(['monaco_language' => 'python', 'codeql_language' => 'python'], 1);

        Sanctum::actingAs($this->instructor);
        $this->trigger(['services' => ['ai-detector']])->assertCreated();

        $this->assertCount(1, $this->publisher->published(AiDetectorJob::QUEUE));
        $this->assertSame([], $this->publisher->published(VulnScannerJob::QUEUE));
        $this->assertSame(ServiceStatus::NotApplicable, AnalysisSubmission::firstOrFail()->vuln_scan_status);
    }

    /** One refused message leaves its own row in_queue and the rest going out. */
    public function test_a_submission_the_broker_refuses_stays_in_queue(): void
    {
        $this->submissionsIn(['monaco_language' => 'python', 'codeql_language' => 'python'], 2);

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
        $this->trigger(['services' => ['ai-detector']])->assertCreated();

        $this->assertCount(1, $publisher->published(AiDetectorJob::QUEUE), 'the second submission still went out');
        $this->assertSame(2, AnalysisSubmission::where('ai_detection_status', ServiceStatus::InQueue)->count());

        $errors = array_values(array_filter(
            $logged,
            static fn (MessageLogged $event): bool => $event->level === 'error',
        ));

        $this->assertCount(1, $errors);
        $this->assertSame(AiDetectorJob::QUEUE, $errors[0]->context['queue']);
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
            $overrides + ['services' => ['ai-detector']],
        );
    }

    /**
     * @param  array<string, mixed>  $language  the columns the gates read
     * @return list<Submission>
     */
    private function submissionsIn(array $language, int $students): array
    {
        $row = ProgrammingLanguage::factory()->create($language + [
            'name' => 'Language '.ProgrammingLanguage::count(),
        ]);

        $submissions = [];

        for ($i = 0; $i < $students; $i++) {
            $student = $this->sectionMember($this->section, SectionRole::Student);

            $submissions[] = Submission::factory()->create([
                'problem_id' => $this->problem->id,
                'creator_id' => $student->id,
                'programming_language_id' => $row->id,
                'file_path' => "submissions/{$this->problem->id}/{$student->id}/main.txt",
            ]);
        }

        return $submissions;
    }
}
