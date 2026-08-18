<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisService;
use App\Enums\MatchType;
use App\Enums\SectionRole;
use App\Enums\ServiceStatus;
use App\Messaging\AnalysisResultHandler;
use App\Messaging\InvalidResultMessage;
use App\Models\AnalysisProblem;
use App\Models\AnalysisSubmission;
use App\Models\Problem;
use App\Models\ProgrammingLanguage;
use App\Models\Section;
use App\Models\Semester;
use App\Models\SimilarityResult;
use App\Models\Submission;
use App\Services\Analysis\SimResultIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * D4: what SIM puts on `result-analysis` becomes rows api owns (D-80).
 *
 * The handler is driven directly rather than through a running consumer — the
 * queue plumbing has its own tests, and what belongs here is the translation
 * from message to rows: `match_type`, the pair ordering the schema calls
 * critical, the per-submission statuses, and idempotence under redelivery.
 */
class SimResultIngestionTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Semester $semester;

    private Section $section;

    private Problem $problem;

    private ProgrammingLanguage $python;

    private AnalysisProblem $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()));
        $this->section = $this->sectionIn($this->semester);
        $this->problem = Problem::factory()->for($this->section)->create();
        $this->python = ProgrammingLanguage::factory()->create(['dolos_language' => 'python']);

        $this->batch = AnalysisProblem::factory()->create([
            'semester_id' => $this->semester->id,
            'bank_problem_id' => null,
            'manual_match_group_id' => (string) Str::uuid(),
            'triggered_by_problem_id' => $this->problem->id,
            'services' => [AnalysisService::PlagiarismChecker->value],
        ]);
    }

    public function test_two_submissions_of_one_section_are_a_within_section_match(): void
    {
        [$a, $b] = [$this->enrol($this->problem), $this->enrol($this->problem)];

        $this->handle($this->simResult([$this->pair($a, $b, 0.87)]));

        $row = SimilarityResult::firstOrFail();

        $this->assertSame($this->batch->id, $row->analysis_problem_id);
        $this->assertSame(MatchType::WithinSection, $row->match_type);
        $this->assertSame('0.8700', $row->similarity);
    }

    /** D-61: the section of each submission's problem, which SIM cannot know. */
    public function test_submissions_from_two_sections_are_a_cross_section_match(): void
    {
        $other = Problem::factory()->for($this->sectionIn($this->semester))->create();

        $this->handle($this->simResult([$this->pair($this->enrol($this->problem), $this->enrol($other), 0.5)]));

        $this->assertSame(MatchType::CrossSection, SimilarityResult::firstOrFail()->match_type);
    }

    /**
     * Schema §5.5 calls `submission_a_id < submission_b_id` critical but no
     * constraint enforces it, so a producer sending a pair the other way round
     * would sit alongside its own mirror image rather than replacing it.
     */
    public function test_a_reversed_pair_is_normalised_with_its_regions(): void
    {
        [$a, $b] = [$this->enrol($this->problem), $this->enrol($this->problem)];

        $this->handle($this->simResult([[
            'submission_a_id' => $b->id,
            'submission_b_id' => $a->id,
            'similarity' => 0.42,
            'a_regions' => 'b-side',
            'b_regions' => 'a-side',
        ]]));

        $row = SimilarityResult::firstOrFail();

        $this->assertSame($a->id, $row->submission_a_id);
        $this->assertSame($b->id, $row->submission_b_id);
        $this->assertSame('a-side', $row->a_regions, 'the regions must follow their own submission');
        $this->assertSame('b-side', $row->b_regions);
    }

    public function test_a_redelivered_message_leaves_one_row_per_pair(): void
    {
        [$a, $b] = [$this->enrol($this->problem), $this->enrol($this->problem)];

        $this->handle($this->simResult([$this->pair($a, $b, 0.3)]));
        $this->handle($this->simResult([$this->pair($a, $b, 0.9)]));

        $this->assertSame(1, SimilarityResult::count());
        $this->assertSame('0.9000', SimilarityResult::firstOrFail()->similarity, 'the later result wins');
    }

    /**
     * The foreign keys are RESTRICT, so an id from outside the batch would
     * abort every delivery of this message identically. The valid pairs of the
     * same group must not be lost with it (v3 §7, 2026-08-18).
     */
    public function test_a_pair_naming_a_submission_outside_the_batch_is_dropped(): void
    {
        [$a, $b] = [$this->enrol($this->problem), $this->enrol($this->problem)];
        $stranger = Submission::factory()->create([
            'problem_id' => $this->problem->id,
            'creator_id' => $this->sectionMember($this->section, SectionRole::Student)->id,
            'programming_language_id' => $this->python->id,
        ]);

        $logged = $this->captureLogs();

        $this->handle($this->simResult([
            $this->pair($a, $stranger, 0.99),
            $this->pair($a, $b, 0.4),
        ]));

        $this->assertSame(1, SimilarityResult::count(), 'the valid pair still lands');
        $this->assertSame('0.4000', SimilarityResult::firstOrFail()->similarity);
        $this->assertNotSame([], $this->warnings($logged()));
    }

    public function test_a_pair_of_a_submission_with_itself_is_dropped(): void
    {
        $a = $this->enrol($this->problem);
        $this->enrol($this->problem);

        $this->handle($this->simResult([$this->pair($a, $a, 1.0)]));

        $this->assertSame(0, SimilarityResult::count());
    }

    public function test_per_submission_statuses_are_written(): void
    {
        [$a, $b] = [$this->enrol($this->problem), $this->enrol($this->problem)];

        $this->handle($this->simResult([], [
            ['analysis_submission_id' => $this->entryFor($a)->id, 'status' => 'completed'],
            ['analysis_submission_id' => $this->entryFor($b)->id, 'status' => 'error'],
        ]));

        $this->assertSame(ServiceStatus::Completed, $this->entryFor($a)->plagiarism_status);
        $this->assertSame(ServiceStatus::Error, $this->entryFor($b)->plagiarism_status);
    }

    /**
     * A group that answered must not leave rows at `in_queue`: the completion
     * check would wait on a message that has already been delivered.
     */
    public function test_submissions_the_message_does_not_name_take_the_group_status(): void
    {
        [$a, $b] = [$this->enrol($this->problem), $this->enrol($this->problem)];

        $this->handle($this->simResult([], [
            ['analysis_submission_id' => $this->entryFor($a)->id, 'status' => 'completed'],
        ]));

        $this->assertSame(ServiceStatus::Completed, $this->entryFor($a)->plagiarism_status);
        $this->assertSame(ServiceStatus::Completed, $this->entryFor($b)->plagiarism_status);
    }

    public function test_a_failed_group_marks_its_submissions_error(): void
    {
        [$a, $b] = [$this->enrol($this->problem), $this->enrol($this->problem)];

        $message = $this->simResult();
        $message['status'] = 'error';
        $message['error_message'] = 'dolos exited 1';

        $this->handle($message);

        $this->assertSame(ServiceStatus::Error, $this->entryFor($a)->plagiarism_status);
        $this->assertSame(ServiceStatus::Error, $this->entryFor($b)->plagiarism_status);
    }

    /** Another language's group is untouched by this one's outcome. */
    public function test_the_group_status_only_reaches_its_own_language(): void
    {
        $a = $this->enrol($this->problem);
        $java = ProgrammingLanguage::factory()->create(['name' => 'Java', 'dolos_language' => 'java']);
        $b = $this->enrol($this->problem, $java);

        $this->handle($this->simResult());

        $this->assertSame(ServiceStatus::Completed, $this->entryFor($a)->plagiarism_status);
        $this->assertSame(ServiceStatus::InQueue, $this->entryFor($b)->plagiarism_status);
    }

    // ── U-9 completion set ──

    public function test_the_completion_set_fills_one_group_at_a_time(): void
    {
        $this->enrol($this->problem);
        $this->enrol($this->problem);

        $first = $this->simResult();
        $first['language_group_index'] = 0;
        $first['language_group_total'] = 2;
        $this->handle($first);

        $this->assertSame(['total' => 2, 'received' => [0]], $this->completion());

        $second = $this->simResult();
        $second['language_group_index'] = 1;
        $second['language_group_total'] = 2;
        $this->handle($second);

        $this->assertSame(['total' => 2, 'received' => [0, 1]], $this->completion());
    }

    /** U-9 as a plain counter would call this batch complete with one group missing. */
    public function test_a_redelivered_group_does_not_advance_the_completion_set(): void
    {
        $this->enrol($this->problem);
        $this->enrol($this->problem);

        $message = $this->simResult();
        $message['language_group_total'] = 2;

        $this->handle($message);
        $this->handle($message);

        $this->assertSame(['total' => 2, 'received' => [0]], $this->completion());
    }

    // ── Routing ──

    public function test_a_message_for_an_unknown_service_is_rejected(): void
    {
        $this->expectException(InvalidResultMessage::class);

        $this->handle(['service' => 'sentiment-analyser', 'status' => 'completed', 'trace_id' => (string) Str::uuid()]);
    }

    public function test_a_message_missing_its_discriminator_is_rejected(): void
    {
        $this->expectException(InvalidResultMessage::class);

        $this->handle(['status' => 'completed', 'trace_id' => (string) Str::uuid()]);
    }

    /** A batch that is gone cannot be written to, and no retry will change that. */
    public function test_a_result_for_an_unknown_batch_is_logged_and_dropped(): void
    {
        $logged = $this->captureLogs();

        $message = $this->simResult();
        $message['analysis_problem_id'] = $this->batch->id + 1000;

        $this->handle($message);

        $this->assertSame(0, SimilarityResult::count());
        $this->assertNotSame([], $this->warnings($logged()));
    }

    // ── Fixtures ──

    /** @param array<string, mixed> $message */
    private function handle(array $message): void
    {
        app(AnalysisResultHandler::class)->handle((string) json_encode($message));
    }

    /**
     * @param  list<array<string, mixed>>  $pairs
     * @param  list<array<string, mixed>>|null  $statuses
     * @return array<string, mixed>
     */
    private function simResult(array $pairs = [], ?array $statuses = null): array
    {
        return [
            'analysis_problem_id' => $this->batch->id,
            'service' => AnalysisService::PlagiarismChecker->value,
            'status' => 'completed',
            'language' => 'python',
            'language_group_index' => 0,
            'language_group_total' => 1,
            'pairs' => $pairs,
            'submission_statuses' => $statuses ?? [],
            'trace_id' => (string) Str::uuid(),
            'timestamp' => '2026-08-18T14:00:00Z',
            'version' => '1.0',
        ];
    }

    /** @return array<string, mixed> */
    private function pair(Submission $a, Submission $b, float $similarity): array
    {
        return [
            'submission_a_id' => min($a->id, $b->id),
            'submission_b_id' => max($a->id, $b->id),
            'similarity' => $similarity,
            'longest_fragment' => 42,
            'total_overlap' => 128,
            'a_regions' => '1,0,4,10',
            'b_regions' => '2,0,5,10',
        ];
    }

    /** A submission by a fresh student, enrolled in the batch and waiting on SIM. */
    private function enrol(Problem $problem, ?ProgrammingLanguage $language = null): Submission
    {
        $submission = Submission::factory()->create([
            'problem_id' => $problem->id,
            'creator_id' => $this->sectionMember($problem->section, SectionRole::Student)->id,
            'programming_language_id' => ($language ?? $this->python)->id,
        ]);

        AnalysisSubmission::factory()->create([
            'analysis_problem_id' => $this->batch->id,
            'submission_id' => $submission->id,
            'plagiarism_status' => ServiceStatus::InQueue,
        ]);

        return $submission;
    }

    private function entryFor(Submission $submission): AnalysisSubmission
    {
        return AnalysisSubmission::where('submission_id', $submission->id)->firstOrFail();
    }

    /** @return array{total: int, received: list<int>}|null */
    private function completion(): ?array
    {
        return Cache::get(SimResultIngestor::COMPLETION_KEY.$this->batch->id);
    }

    /** @return callable(): list<MessageLogged> */
    private function captureLogs(): callable
    {
        /** @var list<MessageLogged> $logged */
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        return function () use (&$logged): array {
            return $logged;
        };
    }

    /**
     * @param  list<MessageLogged>  $logged
     * @return list<MessageLogged>
     */
    private function warnings(array $logged): array
    {
        return array_values(array_filter(
            $logged,
            static fn (MessageLogged $event): bool => $event->level === 'warning',
        ));
    }
}
