<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\DolosLanguage;

/**
 * The submissions of one language within a batch — one published message.
 *
 * A group only exists when it holds at least two submissions: Dolos compares
 * pairs, and the job schema's `minItems: 2` refuses anything smaller.
 */
final readonly class SimLanguageGroup
{
    /** @param list<SimSubmissionRef> $submissions */
    public function __construct(
        public DolosLanguage $language,
        public array $submissions,
    ) {}
}
