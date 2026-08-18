<?php

declare(strict_types=1);

namespace App\Services\Analysis;

/**
 * One submission as SIM grouping sees it: the two ids the message carries, the
 * object key its `file_url` is signed from, and the raw `dolos_language` of the
 * language it was written in.
 *
 * The language stays a nullable string rather than a `DolosLanguage` because
 * the grouping rule is precisely about values that are null or unrecognised —
 * resolving it before the plan would hide the case the plan exists to handle.
 */
final readonly class SimSubmissionRef
{
    public function __construct(
        public int $analysisSubmissionId,
        public int $submissionId,
        public string $filePath,
        public ?string $dolosLanguage,
    ) {}
}
