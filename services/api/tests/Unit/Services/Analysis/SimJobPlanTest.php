<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analysis;

use App\Enums\DolosLanguage;
use App\Services\Analysis\SimJobPlan;
use App\Services\Analysis\SimSubmissionRef;
use PHPUnit\Framework\TestCase;

/**
 * The grouping rule of `rabbitmq-schemas.md` §3.2, on its own: no database, no
 * container, no broker. Every edge case the roadmap names for D2 lives here,
 * because a rule this small is cheaper to pin exhaustively than to debug from a
 * batch that silently compared nothing.
 */
class SimJobPlanTest extends TestCase
{
    public function test_two_qualifying_languages_become_two_groups(): void
    {
        $plan = SimJobPlan::build([
            $this->ref(1, 'python'),
            $this->ref(2, 'python'),
            $this->ref(3, 'java'),
            $this->ref(4, 'java'),
        ]);

        $this->assertCount(2, $plan->groups);
        $this->assertSame(2, $plan->total());
        $this->assertSame([], $plan->notApplicableIds);
    }

    public function test_one_qualifying_language_is_one_group_with_total_one(): void
    {
        $plan = SimJobPlan::build([$this->ref(1, 'python'), $this->ref(2, 'python')]);

        $this->assertCount(1, $plan->groups);
        $this->assertSame(1, $plan->total());
        $this->assertSame(DolosLanguage::Python, $plan->groups[0]->language);
    }

    /**
     * Every student wrote in a different language, so no pair exists to compare.
     * `language_group_total` has `minimum: 1` in the schema, so this state
     * cannot be expressed in a message at all — it is only reachable as rows.
     */
    public function test_no_qualifying_language_produces_no_group(): void
    {
        $plan = SimJobPlan::build([
            $this->ref(1, 'python'),
            $this->ref(2, 'java'),
            $this->ref(3, 'c'),
        ]);

        $this->assertTrue($plan->isEmpty());
        $this->assertSame([], $plan->groups);
        $this->assertSame([1, 2, 3], $plan->notApplicableIds);
    }

    /** D1 answers 422 before this can happen; a bug is the only other route in. */
    public function test_an_empty_batch_produces_an_empty_plan(): void
    {
        $plan = SimJobPlan::build([]);

        $this->assertTrue($plan->isEmpty());
        $this->assertSame(0, $plan->total());
        $this->assertSame([], $plan->notApplicableIds);
    }

    /** The `minItems: 2` boundary, from both sides. */
    public function test_a_group_of_exactly_two_qualifies(): void
    {
        $plan = SimJobPlan::build([$this->ref(1, 'cpp'), $this->ref(2, 'cpp')]);

        $this->assertCount(1, $plan->groups);
        $this->assertCount(2, $plan->groups[0]->submissions);
    }

    public function test_a_group_of_one_does_not_qualify(): void
    {
        $plan = SimJobPlan::build([$this->ref(1, 'cpp')]);

        $this->assertTrue($plan->isEmpty());
        $this->assertSame([1], $plan->notApplicableIds);
    }

    /**
     * `programming_languages.dolos_language` is nullable, and the job schema's
     * `language` enum has no null member — a language Dolos cannot parse is
     * not a group, however many students used it.
     */
    public function test_submissions_with_no_dolos_language_are_never_grouped(): void
    {
        $plan = SimJobPlan::build([
            $this->ref(1, null),
            $this->ref(2, null),
            $this->ref(3, null),
        ]);

        $this->assertTrue($plan->isEmpty());
        $this->assertSame([1, 2, 3], $plan->notApplicableIds);
    }

    /**
     * The column is a free-form varchar(30). A seed row naming a language the
     * schema's enum does not carry must park rather than publish a message SIM
     * would reject at the consumer.
     */
    public function test_a_dolos_language_outside_the_schema_enum_is_not_applicable(): void
    {
        $plan = SimJobPlan::build([
            $this->ref(1, 'javascript'),
            $this->ref(2, 'javascript'),
            $this->ref(3, 'javascript'),
        ]);

        $this->assertTrue($plan->isEmpty());
        $this->assertSame([1, 2, 3], $plan->notApplicableIds);
    }

    /** The roadmap's "mixed null dolos_language" case. */
    public function test_mixed_null_and_qualifying_languages(): void
    {
        $plan = SimJobPlan::build([
            $this->ref(1, 'python'),
            $this->ref(2, 'python'),
            $this->ref(3, 'python'),
            $this->ref(4, 'java'),
            $this->ref(5, null),
            $this->ref(6, null),
        ]);

        $this->assertCount(1, $plan->groups);
        $this->assertSame(DolosLanguage::Python, $plan->groups[0]->language);
        $this->assertCount(3, $plan->groups[0]->submissions);
        $this->assertSame([4, 5, 6], $plan->notApplicableIds);
    }

    /**
     * `language_group_index` is positional, so it has to come from the data and
     * not from insertion order: the same batch must produce the same index on a
     * re-run, or two runs' logs cannot be compared.
     */
    public function test_group_order_is_alphabetical_regardless_of_input_order(): void
    {
        $plan = SimJobPlan::build([
            $this->ref(1, 'python'),
            $this->ref(2, 'python'),
            $this->ref(3, 'c'),
            $this->ref(4, 'c'),
            $this->ref(5, 'java'),
            $this->ref(6, 'java'),
        ]);

        $languages = array_map(
            static fn (object $group): string => $group->language->value,
            $plan->groups,
        );

        $this->assertSame(['c', 'java', 'python'], $languages);
    }

    public function test_submissions_within_a_group_are_ordered_by_analysis_submission_id(): void
    {
        $plan = SimJobPlan::build([
            $this->ref(9, 'python'),
            $this->ref(3, 'python'),
            $this->ref(7, 'python'),
        ]);

        $ids = array_map(
            static fn (SimSubmissionRef $ref): int => $ref->analysisSubmissionId,
            $plan->groups[0]->submissions,
        );

        $this->assertSame([3, 7, 9], $ids);
    }

    /** `language_group_total` counts messages, not languages seen. */
    public function test_the_total_counts_published_groups_not_languages_seen(): void
    {
        $plan = SimJobPlan::build([
            $this->ref(1, 'python'),
            $this->ref(2, 'python'),
            $this->ref(3, 'java'),
            $this->ref(4, 'java'),
            $this->ref(5, 'c'),
            $this->ref(6, 'cpp'),
        ]);

        $this->assertSame(2, $plan->total());
        $this->assertSame([5, 6], $plan->notApplicableIds);
    }

    /** The ids parked are reported in ascending order, whatever order they arrive in. */
    public function test_not_applicable_ids_are_ordered(): void
    {
        $plan = SimJobPlan::build([
            $this->ref(8, 'c'),
            $this->ref(2, 'java'),
            $this->ref(5, null),
        ]);

        $this->assertSame([2, 5, 8], $plan->notApplicableIds);
    }

    public function test_an_empty_plan_carries_nothing(): void
    {
        $plan = SimJobPlan::empty();

        $this->assertTrue($plan->isEmpty());
        $this->assertSame([], $plan->groups);
        $this->assertSame([], $plan->notApplicableIds);
    }

    private function ref(int $analysisSubmissionId, ?string $dolosLanguage): SimSubmissionRef
    {
        return new SimSubmissionRef(
            analysisSubmissionId: $analysisSubmissionId,
            submissionId: $analysisSubmissionId * 100,
            filePath: "submissions/1/{$analysisSubmissionId}/main.txt",
            dolosLanguage: $dolosLanguage,
        );
    }
}
