<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisService;
use App\Enums\SectionRole;
use App\Enums\ServiceStatus;
use App\Enums\Severity;
use App\Messaging\AnalysisResultHandler;
use App\Models\AiDetectionResult;
use App\Models\AnalysisProblem;
use App\Models\AnalysisSubmission;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\VulnerabilityResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * D5: AID and VUL results, one message per analysis submission.
 *
 * Neither service compares anything, so what is worth pinning is the status
 * transitions and what a second delivery of the same message leaves behind —
 * the two tables answer that question differently, because only one of them
 * has a key to upsert on.
 */
class AnalysisResultIngestionTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Semester $semester;

    private Section $section;

    private AnalysisProblem $batch;

    private AnalysisSubmission $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()));
        $this->section = $this->sectionIn($this->semester);
        $problem = Problem::factory()->for($this->section)->create();

        $this->batch = AnalysisProblem::factory()->create([
            'semester_id' => $this->semester->id,
            'bank_problem_id' => null,
            'manual_match_group_id' => (string) Str::uuid(),
            'triggered_by_problem_id' => $problem->id,
            'services' => [AnalysisService::AiDetector->value, AnalysisService::VulnScanner->value],
        ]);

        $submission = Submission::factory()->create([
            'problem_id' => $problem->id,
            'creator_id' => $this->sectionMember($this->section, SectionRole::Student)->id,
        ]);

        $this->entry = AnalysisSubmission::factory()->create([
            'analysis_problem_id' => $this->batch->id,
            'submission_id' => $submission->id,
            'ai_detection_status' => ServiceStatus::InQueue,
            'vuln_scan_status' => ServiceStatus::InQueue,
        ]);
    }

    // ── AID ──

    public function test_a_completed_ai_detection_writes_one_probability_row(): void
    {
        $this->handle($this->aidResult('completed', 0.9312));

        $row = AiDetectionResult::firstOrFail();

        $this->assertSame($this->entry->id, $row->analysis_submission_id);
        $this->assertSame('0.9312', $row->probability);
        $this->assertSame(ServiceStatus::Completed, $this->entry->fresh()->ai_detection_status);
    }

    /** The unique key on `analysis_submission_id` is what makes redelivery safe. */
    public function test_a_redelivered_ai_detection_updates_the_same_row(): void
    {
        $this->handle($this->aidResult('completed', 0.1));
        $this->handle($this->aidResult('completed', 0.8));

        $this->assertSame(1, AiDetectionResult::count());
        $this->assertSame('0.8000', AiDetectionResult::firstOrFail()->probability);
    }

    public function test_an_unsupported_language_is_not_applicable_with_no_row(): void
    {
        $this->handle($this->aidResult('not_applicable'));

        $this->assertSame(0, AiDetectionResult::count());
        $this->assertSame(ServiceStatus::NotApplicable, $this->entry->fresh()->ai_detection_status);
    }

    public function test_a_failed_ai_detection_records_the_status_only(): void
    {
        $message = $this->aidResult('error');
        $message['error_message'] = 'model load failed';

        $this->handle($message);

        $this->assertSame(0, AiDetectionResult::count());
        $this->assertSame(ServiceStatus::Error, $this->entry->fresh()->ai_detection_status);
    }

    /**
     * The schema permits `completed` with a null probability; the column is
     * NOT NULL and a completion with no result is meaningless to a reader.
     */
    public function test_a_completed_ai_detection_with_no_probability_is_an_error(): void
    {
        $logged = $this->captureLogs();

        $this->handle($this->aidResult('completed'));

        $this->assertSame(0, AiDetectionResult::count());
        $this->assertSame(ServiceStatus::Error, $this->entry->fresh()->ai_detection_status);
        $this->assertNotSame([], $this->warnings($logged()));
    }

    // ── VUL ──

    public function test_findings_are_written_with_their_severity_and_location(): void
    {
        $this->handle($this->vulResult('completed', [
            $this->finding('py/sql-injection', 'error'),
            $this->finding('py/unused-import', 'recommendation'),
        ]));

        $this->assertSame(2, VulnerabilityResult::count());
        $this->assertSame(ServiceStatus::Completed, $this->entry->fresh()->vuln_scan_status);

        $row = VulnerabilityResult::where('name', 'py/sql-injection')->firstOrFail();

        $this->assertSame(Severity::Error, $row->severity);
        $this->assertSame(12, $row->start_line);
        $this->assertSame('main.py', $row->file_path);
    }

    /** A clean scan is a completed scan, not an error. */
    public function test_no_findings_still_completes(): void
    {
        $this->handle($this->vulResult('completed', []));

        $this->assertSame(0, VulnerabilityResult::count());
        $this->assertSame(ServiceStatus::Completed, $this->entry->fresh()->vuln_scan_status);
    }

    /**
     * `vulnerability_results` has no key to upsert on, so the findings are
     * replaced wholesale — appending would double them on redelivery.
     */
    public function test_a_redelivered_scan_replaces_the_previous_findings(): void
    {
        $this->handle($this->vulResult('completed', [
            $this->finding('py/sql-injection', 'error'),
            $this->finding('py/unused-import', 'recommendation'),
        ]));
        $this->handle($this->vulResult('completed', [$this->finding('py/sql-injection', 'warning')]));

        $this->assertSame(1, VulnerabilityResult::count());
        $this->assertSame(Severity::Warning, VulnerabilityResult::firstOrFail()->severity);
    }

    public function test_a_null_codeql_language_arrives_as_not_applicable(): void
    {
        $this->handle($this->vulResult('not_applicable', []));

        $this->assertSame(0, VulnerabilityResult::count());
        $this->assertSame(ServiceStatus::NotApplicable, $this->entry->fresh()->vuln_scan_status);
    }

    public function test_a_failed_scan_keeps_no_findings(): void
    {
        $this->handle($this->vulResult('completed', [$this->finding('py/sql-injection', 'error')]));
        $this->handle($this->vulResult('error', []));

        $this->assertSame(0, VulnerabilityResult::count(), 'a failed re-scan must not leave a stale finding');
        $this->assertSame(ServiceStatus::Error, $this->entry->fresh()->vuln_scan_status);
    }

    public function test_a_finding_with_an_unknown_severity_is_dropped(): void
    {
        $logged = $this->captureLogs();

        $this->handle($this->vulResult('completed', [
            $this->finding('py/mystery', 'catastrophic'),
            $this->finding('py/sql-injection', 'error'),
        ]));

        $this->assertSame(1, VulnerabilityResult::count());
        $this->assertSame('py/sql-injection', VulnerabilityResult::firstOrFail()->name);
        $this->assertNotSame([], $this->warnings($logged()));
    }

    // ── Both ──

    public function test_a_result_for_an_unknown_submission_is_logged_and_dropped(): void
    {
        $logged = $this->captureLogs();

        $message = $this->aidResult('completed', 0.5);
        $message['analysis_submission_id'] = $this->entry->id + 1000;

        $this->handle($message);

        $this->assertSame(0, AiDetectionResult::count());
        $this->assertSame(ServiceStatus::InQueue, $this->entry->fresh()->ai_detection_status);
        $this->assertNotSame([], $this->warnings($logged()));
    }

    /** One service's outcome never moves another's status. */
    public function test_the_two_services_write_only_their_own_column(): void
    {
        $this->handle($this->aidResult('completed', 0.2));

        $this->assertSame(ServiceStatus::Completed, $this->entry->fresh()->ai_detection_status);
        $this->assertSame(ServiceStatus::InQueue, $this->entry->fresh()->vuln_scan_status);
        $this->assertSame(ServiceStatus::InQueue, $this->entry->fresh()->plagiarism_status);
    }

    // ── Fixtures ──

    /** @param array<string, mixed> $message */
    private function handle(array $message): void
    {
        app(AnalysisResultHandler::class)->handle((string) json_encode($message));
    }

    /** @return array<string, mixed> */
    private function aidResult(string $status, ?float $probability = null): array
    {
        return [
            'analysis_submission_id' => $this->entry->id,
            'service' => AnalysisService::AiDetector->value,
            'status' => $status,
            'probability' => $probability,
            'trace_id' => (string) Str::uuid(),
            'timestamp' => '2026-08-18T14:00:00Z',
            'version' => '1.0',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    private function vulResult(string $status, array $findings): array
    {
        return [
            'analysis_submission_id' => $this->entry->id,
            'service' => AnalysisService::VulnScanner->value,
            'status' => $status,
            'findings' => $findings,
            'trace_id' => (string) Str::uuid(),
            'timestamp' => '2026-08-18T14:00:00Z',
            'version' => '1.0',
        ];
    }

    /** @return array<string, mixed> */
    private function finding(string $name, string $severity): array
    {
        return [
            'name' => $name,
            'description' => 'Untrusted input reaches a query.',
            'severity' => $severity,
            'file_path' => 'main.py',
            'start_line' => 12,
            'start_column' => 4,
            'end_line' => 12,
            'end_column' => 40,
        ];
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
