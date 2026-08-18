<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\DolosLanguage;

/**
 * What SIM publishing does with one batch: which language groups become
 * messages, and which submissions no message can serve.
 *
 * The rule is `rabbitmq-schemas.md` §3.2 — group by `dolos_language`, publish a
 * group of two or more, mark the rest `not_applicable` — and it lives here on
 * its own, with no database and no broker, because it is the part worth pinning
 * exhaustively.
 *
 * Ordering is part of the contract, not incidental. `language_group_index` is
 * positional, so it has to come from the data: groups are alphabetical by
 * language and submissions ascending by `analysis_submission_id`, which makes a
 * re-run of the same batch produce the same indexes and therefore comparable
 * logs.
 */
final readonly class SimJobPlan
{
    /**
     * @param  list<SimLanguageGroup>  $groups
     * @param  list<int>  $notApplicableIds  analysis_submission ids nothing was published for
     * @param  list<string>  $unrecognisedLanguages  `dolos_language` values the schema's enum lacks
     */
    private function __construct(
        public array $groups,
        public array $notApplicableIds,
        public array $unrecognisedLanguages,
    ) {}

    public static function empty(): self
    {
        return new self([], [], []);
    }

    /** @param list<SimSubmissionRef> $refs */
    public static function build(array $refs): self
    {
        /** @var array<string, list<SimSubmissionRef>> $buckets */
        $buckets = [];

        /** @var list<int> $notApplicable */
        $notApplicable = [];

        /** @var list<string> $unrecognised */
        $unrecognised = [];

        foreach ($refs as $ref) {
            $language = $ref->dolosLanguage === null ? null : DolosLanguage::tryFrom($ref->dolosLanguage);

            if ($language === null) {
                $notApplicable[] = $ref->analysisSubmissionId;

                // A non-null value the enum does not carry is a mis-seeded
                // `programming_languages` row, not an ordinary gap — worth
                // telling the operator about, so it is kept separate.
                if ($ref->dolosLanguage !== null && ! in_array($ref->dolosLanguage, $unrecognised, true)) {
                    $unrecognised[] = $ref->dolosLanguage;
                }

                continue;
            }

            $buckets[$language->value][] = $ref;
        }

        ksort($buckets);

        /** @var list<SimLanguageGroup> $groups */
        $groups = [];

        foreach ($buckets as $value => $members) {
            // Dolos compares pairs; one submission has nothing to be compared
            // against, and the job schema refuses `submissions` below 2 anyway.
            if (count($members) < 2) {
                $notApplicable[] = $members[0]->analysisSubmissionId;

                continue;
            }

            usort(
                $members,
                static fn (SimSubmissionRef $a, SimSubmissionRef $b): int => $a->analysisSubmissionId <=> $b->analysisSubmissionId,
            );

            $groups[] = new SimLanguageGroup(DolosLanguage::from((string) $value), $members);
        }

        sort($notApplicable);
        sort($unrecognised);

        return new self($groups, $notApplicable, $unrecognised);
    }

    /** `language_group_total`: the number of messages published, not of languages seen. */
    public function total(): int
    {
        return count($this->groups);
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }
}
