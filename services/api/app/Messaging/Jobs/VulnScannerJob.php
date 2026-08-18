<?php

declare(strict_types=1);

namespace App\Messaging\Jobs;

use App\Enums\CodeqlLanguage;
use App\Messaging\QueueMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * `job.vuln-scanner` v1.0 — one submission.
 *
 * VUL has no database access (D-80), so the message carries the source URL
 * (pre-signed, D-85) and the CodeQL language pack to scan with. A language
 * whose `codeql_language` is null never reaches here: api publishes nothing
 * and marks the submission `not_applicable`.
 *
 * Deliberately not sharing a base class with `AiDetectorJob`, whose payload is
 * currently identical: the two schemas are separate files at the top of the
 * source-of-truth hierarchy and may drift — their `language` enums already do.
 */
final readonly class VulnScannerJob implements QueueMessage
{
    public const QUEUE = 'vuln-scanner';

    public const VERSION = '1.0';

    public function __construct(
        public int $analysisSubmissionId,
        public int $submissionId,
        public string $fileUrl,
        public CodeqlLanguage $language,
        public string $traceId,
        public Carbon $publishedAt,
    ) {}

    public static function for(
        int $analysisSubmissionId,
        int $submissionId,
        string $fileUrl,
        CodeqlLanguage $language,
    ): self {
        return new self(
            $analysisSubmissionId,
            $submissionId,
            $fileUrl,
            $language,
            (string) Str::uuid(),
            Carbon::now(),
        );
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
     * Key order follows the schema's `required` list; `additionalProperties`
     * is false there, so anything extra is a broken message.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'analysis_submission_id' => $this->analysisSubmissionId,
            'submission_id' => $this->submissionId,
            'file_url' => $this->fileUrl,
            'language' => $this->language->value,
            'trace_id' => $this->traceId,
            // The schema asks for ISO 8601 UTC; Carbon's default string form
            // is not that, so the format is spelled out.
            'timestamp' => $this->publishedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'version' => self::VERSION,
        ];
    }
}
