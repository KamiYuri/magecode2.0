<?php

declare(strict_types=1);

namespace App\Messaging\Jobs;

use App\Enums\DolosLanguage;
use App\Messaging\QueueMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * `job.plagiarism-checker` v1.0 — one language group of one batch.
 *
 * SIM has no database access (D-80), so everything it needs travels here: the
 * language it must run Dolos with, both ids per submission, and a pre-signed
 * URL to fetch the source from (D-85). `language_group_index`/`_total` are what
 * api later uses to tell a finished batch from a half-finished one, so they
 * describe the messages actually published — a language group of one is never
 * published and never counted.
 *
 * @phpstan-type SimJobSubmission array{submission_id: int, analysis_submission_id: int, file_url: string}
 */
final readonly class PlagiarismCheckerJob implements QueueMessage
{
    public const QUEUE = 'plagiarism-checker';

    public const VERSION = '1.0';

    /** @param list<SimJobSubmission> $submissions at least two, per the schema's minItems */
    public function __construct(
        public int $analysisProblemId,
        public DolosLanguage $language,
        public int $languageGroupIndex,
        public int $languageGroupTotal,
        public array $submissions,
        public string $traceId,
        public Carbon $publishedAt,
    ) {}

    /** @param list<SimJobSubmission> $submissions */
    public static function for(
        int $analysisProblemId,
        DolosLanguage $language,
        int $languageGroupIndex,
        int $languageGroupTotal,
        array $submissions,
    ): self {
        return new self(
            $analysisProblemId,
            $language,
            $languageGroupIndex,
            $languageGroupTotal,
            $submissions,
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
     * Key order follows the schema's `required` list. `additionalProperties`
     * is false there — on the message and on each submission — so anything
     * extra added here is a broken message.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'analysis_problem_id' => $this->analysisProblemId,
            'language' => $this->language->value,
            'language_group_index' => $this->languageGroupIndex,
            'language_group_total' => $this->languageGroupTotal,
            'submissions' => $this->submissions,
            'trace_id' => $this->traceId,
            // The schema asks for ISO 8601 UTC; Carbon's default string form
            // is not that, so the format is spelled out.
            'timestamp' => $this->publishedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'version' => self::VERSION,
        ];
    }
}
