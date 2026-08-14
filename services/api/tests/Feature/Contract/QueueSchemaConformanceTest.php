<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Messaging\Jobs\CodeExecutorJob;
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
