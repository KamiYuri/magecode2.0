<?php

declare(strict_types=1);

namespace App\Messaging\Jobs;

use App\Messaging\QueueMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * `job.code-executor` v1.0.
 *
 * D-84: the id and nothing else. CES has its own database access (D-81), so
 * anything it could read there — test cases, language, file path — is
 * deliberately absent, and a test case edited while the message waits in the
 * queue is picked up in its current form rather than a stale copy.
 */
final readonly class CodeExecutorJob implements QueueMessage
{
    public const QUEUE = 'code-executor';

    public const VERSION = '1.0';

    public function __construct(
        public int $submissionId,
        public string $traceId,
        public Carbon $publishedAt,
    ) {}

    public static function for(int $submissionId): self
    {
        return new self($submissionId, (string) Str::uuid(), Carbon::now());
    }

    public function queue(): string
    {
        return self::QUEUE;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * Key order follows the schema's `required` list. `additionalProperties`
     * is false there, so anything extra added here is a broken message.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'submission_id' => $this->submissionId,
            'trace_id' => $this->traceId,
            // The schema asks for ISO 8601 UTC; Carbon's default string form
            // is not that, so the format is spelled out.
            'timestamp' => $this->publishedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'version' => self::VERSION,
        ];
    }
}
