<?php

declare(strict_types=1);

namespace App\Messaging\Jobs;

use App\Enums\AiDetectorLanguage;
use App\Messaging\QueueMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * `job.ai-detector` v1.0 — one submission.
 *
 * AID has no database access (D-80), so the message carries the source URL
 * (pre-signed, D-85) and the language to route the model on. `submission_id`
 * is there for logging only; `analysis_submission_id` is what the result comes
 * back against.
 *
 * Deliberately not sharing a base class with `VulnScannerJob`, whose payload
 * is currently identical: the two schemas are separate files at the top of the
 * source-of-truth hierarchy and may drift, and a shared parent would make one
 * of them silently follow the other.
 */
final readonly class AiDetectorJob implements QueueMessage
{
    public const QUEUE = 'ai-detector';

    public const VERSION = '1.0';

    public function __construct(
        public int $analysisSubmissionId,
        public int $submissionId,
        public string $fileUrl,
        public AiDetectorLanguage $language,
        public string $traceId,
        public Carbon $publishedAt,
    ) {}

    public static function for(
        int $analysisSubmissionId,
        int $submissionId,
        string $fileUrl,
        AiDetectorLanguage $language,
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
