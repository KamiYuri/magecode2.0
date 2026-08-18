<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Enums\AiDetectorLanguage;
use App\Enums\CodeqlLanguage;
use App\Enums\DolosLanguage;
use App\Messaging\Jobs\AiDetectorJob;
use App\Messaging\Jobs\CodeExecutorJob;
use App\Messaging\Jobs\PlagiarismCheckerJob;
use App\Messaging\Jobs\VulnScannerJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Tests\Support\FindsRepositoryFile;
use Tests\TestCase;

/**
 * `shared/schemas/*.json` is the top of the source-of-truth hierarchy, so a
 * payload this service publishes is checked against the schema itself rather
 * than against a copy of it — the two cannot drift apart unnoticed.
 */
class QueueSchemaConformanceTest extends TestCase
{
    use FindsRepositoryFile;

    private const CODE_EXECUTOR_SCHEMA = 'shared/schemas/job.code-executor.v1.schema.json';

    private const PLAGIARISM_CHECKER_SCHEMA = 'shared/schemas/job.plagiarism-checker.v1.schema.json';

    private const AI_DETECTOR_SCHEMA = 'shared/schemas/job.ai-detector.v1.schema.json';

    private const VULN_SCANNER_SCHEMA = 'shared/schemas/job.vuln-scanner.v1.schema.json';

    private const ANALYSIS_RESULT_SCHEMA = 'shared/schemas/result.analysis.v1.schema.json';

    public function test_a_code_executor_job_matches_the_published_schema(): void
    {
        $job = new CodeExecutorJob(
            submissionId: 42,
            traceId: (string) Str::uuid(),
            publishedAt: Carbon::parse('2026-08-14T09:30:00Z'),
        );

        $this->assertValidates($job->toArray(), self::CODE_EXECUTOR_SCHEMA);
    }

    /**
     * The schema sets `additionalProperties: false`. Proving the validator
     * rejects an extra key is what makes the positive case above meaningful —
     * a misconfigured validator would pass everything.
     */
    public function test_an_unexpected_key_is_rejected(): void
    {
        $payload = (new CodeExecutorJob(1, (string) Str::uuid(), Carbon::now()))->toArray();
        $payload['problem_id'] = 7;

        $this->assertViolates($payload, self::CODE_EXECUTOR_SCHEMA);
    }

    /** `date-time` is one of the formats this validator does assert. */
    public function test_a_timestamp_that_is_not_iso_8601_is_rejected(): void
    {
        $payload = (new CodeExecutorJob(1, (string) Str::uuid(), Carbon::now()))->toArray();
        $payload['timestamp'] = '14/08/2026 09:30';

        $this->assertViolates($payload, self::CODE_EXECUTOR_SCHEMA);
    }

    /**
     * The schema declares `format: uuid`, which this validator does not
     * assert — its FormatConstraint knows date-time, uri, email and friends,
     * but not uuid. The guarantee still matters for D-88 tracing, so it is
     * checked here against the generator directly rather than assumed.
     */
    public function test_the_generated_trace_id_is_a_uuid(): void
    {
        $job = CodeExecutorJob::for(1);

        $this->assertTrue(Str::isUuid($job->traceId()), "{$job->traceId()} is not a UUID");
    }

    /** D-84: the message carries the id and nothing CES could read from the DB. */
    public function test_the_payload_carries_only_the_documented_keys(): void
    {
        $payload = (new CodeExecutorJob(1, (string) Str::uuid(), Carbon::now()))->toArray();

        $this->assertSame(['submission_id', 'trace_id', 'timestamp', 'version'], array_keys($payload));
        $this->assertSame('1.0', $payload['version']);
    }

    public function test_a_plagiarism_checker_job_matches_the_published_schema(): void
    {
        $this->assertValidates($this->simJob()->toArray(), self::PLAGIARISM_CHECKER_SCHEMA);
    }

    public function test_a_plagiarism_checker_job_with_an_unexpected_key_is_rejected(): void
    {
        $payload = $this->simJob()->toArray();
        $payload['section_id'] = 3;

        $this->assertViolates($payload, self::PLAGIARISM_CHECKER_SCHEMA);
    }

    /**
     * `minItems: 2` is the schema half of the rule `SimJobPlan` enforces: a
     * language with one submission is never published. Proving the validator
     * refuses a single-submission message is what makes the plan's silence
     * about it safe.
     */
    public function test_a_group_of_one_submission_is_rejected(): void
    {
        $payload = $this->simJob()->toArray();
        $payload['submissions'] = [$payload['submissions'][0]];

        $this->assertViolates($payload, self::PLAGIARISM_CHECKER_SCHEMA);
    }

    /** The enum is closed; a language Dolos cannot parse must never reach a message. */
    public function test_a_language_outside_the_enum_is_rejected(): void
    {
        $payload = $this->simJob()->toArray();
        $payload['language'] = 'javascript';

        $this->assertViolates($payload, self::PLAGIARISM_CHECKER_SCHEMA);
    }

    public function test_the_plagiarism_payload_carries_only_the_documented_keys(): void
    {
        $payload = $this->simJob()->toArray();

        $this->assertSame([
            'analysis_problem_id', 'language', 'language_group_index', 'language_group_total',
            'submissions', 'trace_id', 'timestamp', 'version',
        ], array_keys($payload));

        $this->assertSame(
            ['submission_id', 'analysis_submission_id', 'file_url'],
            array_keys($payload['submissions'][0]),
        );
        $this->assertSame('1.0', $payload['version']);
    }

    public function test_an_ai_detector_job_matches_the_published_schema(): void
    {
        $this->assertValidates($this->aidJob()->toArray(), self::AI_DETECTOR_SCHEMA);
    }

    public function test_an_ai_detector_job_with_an_unexpected_key_is_rejected(): void
    {
        $payload = $this->aidJob()->toArray();
        $payload['analysis_problem_id'] = 45;

        $this->assertViolates($payload, self::AI_DETECTOR_SCHEMA);
    }

    public function test_a_vuln_scanner_job_matches_the_published_schema(): void
    {
        $this->assertValidates($this->vulJob()->toArray(), self::VULN_SCANNER_SCHEMA);
    }

    /**
     * CodeQL has no analyser for C on its own — `cpp` covers both — so the VUL
     * schema's enum is one member shorter than AID's, and `CodeqlLanguage`
     * carries that difference. A C submission is scanned as `cpp` through
     * `programming_languages.codeql_language`, which is covered in
     * `AnalysisJobPublishingTest`.
     */
    public function test_c_is_not_a_codeql_language(): void
    {
        $payload = $this->vulJob()->toArray();
        $payload['language'] = 'c';

        $this->assertViolates($payload, self::VULN_SCANNER_SCHEMA);
    }

    /** Both per-submission jobs carry the same keys in the same order. */
    public function test_the_per_submission_payloads_carry_only_the_documented_keys(): void
    {
        $keys = ['analysis_submission_id', 'submission_id', 'file_url', 'language',
            'trace_id', 'timestamp', 'version'];

        $this->assertSame($keys, array_keys($this->aidJob()->toArray()));
        $this->assertSame($keys, array_keys($this->vulJob()->toArray()));
    }

    /**
     * The result direction, which api consumes rather than produces: the
     * fixtures D4's ingestion tests feed the handler are validated here, so a
     * suite that passes against a message the real SIM could never send is
     * caught rather than trusted.
     */
    public function test_the_sim_result_fixture_is_a_legal_message(): void
    {
        $this->assertValidates($this->simResult(), self::ANALYSIS_RESULT_SCHEMA);
    }

    public function test_a_sim_result_with_an_unordered_pair_is_still_legal(): void
    {
        // The schema documents the ordering but cannot express it, which is
        // why `SimResultIngestor` normalises rather than trusting the producer.
        $payload = $this->simResult();
        $payload['pairs'][0]['submission_a_id'] = 999;

        $this->assertValidates($payload, self::ANALYSIS_RESULT_SCHEMA);
    }

    /** `oneOf` discriminates on `service`; a value none of the branches names fits nothing. */
    public function test_a_result_for_an_unknown_service_is_rejected(): void
    {
        $payload = $this->simResult();
        $payload['service'] = 'sentiment-analyser';

        $this->assertViolates($payload, self::ANALYSIS_RESULT_SCHEMA);
    }

    public function test_a_similarity_above_one_is_rejected(): void
    {
        $payload = $this->simResult();
        $payload['pairs'][0]['similarity'] = 1.5;

        $this->assertViolates($payload, self::ANALYSIS_RESULT_SCHEMA);
    }

    public function test_the_aid_result_fixture_is_a_legal_message(): void
    {
        $this->assertValidates($this->aidResult(), self::ANALYSIS_RESULT_SCHEMA);
    }

    /** The schema permits it; `AiDetectionResultIngestor` records it as an error. */
    public function test_a_completed_aid_result_with_a_null_probability_is_legal(): void
    {
        $payload = $this->aidResult();
        $payload['probability'] = null;

        $this->assertValidates($payload, self::ANALYSIS_RESULT_SCHEMA);
    }

    public function test_the_vul_result_fixture_is_a_legal_message(): void
    {
        $this->assertValidates($this->vulResult(), self::ANALYSIS_RESULT_SCHEMA);
    }

    public function test_a_finding_severity_outside_the_enum_is_rejected(): void
    {
        $payload = $this->vulResult();
        $payload['findings'][0]['severity'] = 'catastrophic';

        $this->assertViolates($payload, self::ANALYSIS_RESULT_SCHEMA);
    }

    /** `not_applicable` is a result status the job schemas have no counterpart for. */
    public function test_not_applicable_is_a_legal_result_status(): void
    {
        foreach ([$this->aidResult(), $this->vulResult()] as $payload) {
            $payload['status'] = 'not_applicable';

            $this->assertValidates($payload, self::ANALYSIS_RESULT_SCHEMA);
        }
    }

    /** @return array<string, mixed> */
    private function aidResult(): array
    {
        return [
            'analysis_submission_id' => 501,
            'service' => 'ai-detector',
            'status' => 'completed',
            'probability' => 0.9312,
            'trace_id' => (string) Str::uuid(),
            'timestamp' => '2026-08-18T14:00:00Z',
            'version' => '1.0',
        ];
    }

    /** @return array<string, mixed> */
    private function vulResult(): array
    {
        return [
            'analysis_submission_id' => 501,
            'service' => 'vuln-scanner',
            'status' => 'completed',
            'findings' => [[
                'name' => 'py/sql-injection',
                'description' => 'Untrusted input reaches a query.',
                'severity' => 'error',
                'file_path' => 'main.py',
                'start_line' => 12,
                'start_column' => 4,
                'end_line' => 12,
                'end_column' => 40,
            ]],
            'trace_id' => (string) Str::uuid(),
            'timestamp' => '2026-08-18T14:00:00Z',
            'version' => '1.0',
        ];
    }

    /** @return array<string, mixed> */
    private function simResult(): array
    {
        return [
            'analysis_problem_id' => 45,
            'service' => 'plagiarism-checker',
            'status' => 'completed',
            'language' => 'python',
            'language_group_index' => 0,
            'language_group_total' => 1,
            'pairs' => [[
                'submission_a_id' => 101,
                'submission_b_id' => 102,
                'similarity' => 0.87,
                'longest_fragment' => 42,
                'total_overlap' => 128,
                'a_regions' => '1,0,4,10',
                'b_regions' => '2,0,5,10',
            ]],
            'submission_statuses' => [
                ['analysis_submission_id' => 501, 'status' => 'completed'],
                ['analysis_submission_id' => 502, 'status' => 'completed'],
            ],
            'trace_id' => (string) Str::uuid(),
            'timestamp' => '2026-08-18T14:00:00Z',
            'version' => '1.0',
        ];
    }

    private function aidJob(): AiDetectorJob
    {
        return new AiDetectorJob(
            analysisSubmissionId: 501,
            submissionId: 101,
            fileUrl: 'https://minio.test/a?sig=1',
            language: AiDetectorLanguage::Python,
            traceId: (string) Str::uuid(),
            publishedAt: Carbon::parse('2026-08-15T14:00:01Z'),
        );
    }

    private function vulJob(): VulnScannerJob
    {
        return new VulnScannerJob(
            analysisSubmissionId: 501,
            submissionId: 101,
            fileUrl: 'https://minio.test/a?sig=1',
            language: CodeqlLanguage::Cpp,
            traceId: (string) Str::uuid(),
            publishedAt: Carbon::parse('2026-08-15T14:00:01Z'),
        );
    }

    private function simJob(): PlagiarismCheckerJob
    {
        return new PlagiarismCheckerJob(
            analysisProblemId: 45,
            language: DolosLanguage::Python,
            languageGroupIndex: 0,
            languageGroupTotal: 2,
            submissions: [
                ['submission_id' => 101, 'analysis_submission_id' => 501, 'file_url' => 'https://minio.test/a?sig=1'],
                ['submission_id' => 102, 'analysis_submission_id' => 502, 'file_url' => 'https://minio.test/b?sig=2'],
            ],
            traceId: (string) Str::uuid(),
            publishedAt: Carbon::parse('2026-08-15T14:00:00Z'),
        );
    }

    /** @param array<string, mixed> $payload */
    private function assertValidates(array $payload, string $schema): void
    {
        $errors = $this->validate($payload, $schema);

        $this->assertSame([], $errors, 'payload must satisfy '.$schema);
    }

    /** @param array<string, mixed> $payload */
    private function assertViolates(array $payload, string $schema): void
    {
        $this->assertNotSame([], $this->validate($payload, $schema), 'payload should have been rejected');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function validate(array $payload, string $schema): array
    {
        $validator = new Validator;

        // json_decode round-trip: the validator distinguishes objects from
        // associative arrays, and an empty PHP array would read as `[]`.
        $document = json_decode((string) json_encode($payload));

        $validator->validate(
            $document,
            (object) ['$ref' => 'file://'.$this->repositoryFile($schema)],
            Constraint::CHECK_MODE_VALIDATE_SCHEMA,
        );

        return array_map(
            static fn (array $error): string => trim(($error['property'] ?? '').' '.$error['message']),
            $validator->getErrors(),
        );
    }
}
