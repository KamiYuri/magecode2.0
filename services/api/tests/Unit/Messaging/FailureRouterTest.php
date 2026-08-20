<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use App\Messaging\FailureRouter;
use App\Messaging\InvalidResultMessage;
use RuntimeException;
use Tests\TestCase;

/**
 * D-79e's retry-then-park rule, which every other consumer in the system
 * already implements. This is the api side of `shared/go/rmq/route.go`, and
 * the numbers are its numbers: three retries at base << retryCount.
 */
class FailureRouterTest extends TestCase
{
    private function router(): FailureRouter
    {
        return new FailureRouter(maxRetries: 3, baseDelayMs: 1000);
    }

    public function test_the_first_three_failures_retry_with_doubling_delay(): void
    {
        foreach ([0 => 1000, 1 => 2000, 2 => 4000] as $retryCount => $expectedDelay) {
            $route = $this->router()->route(new RuntimeException('database is away'), $retryCount);

            $this->assertTrue($route->isRetry(), "retry #{$retryCount} should be retried");
            $this->assertSame($expectedDelay, $route->delayMs);
            $this->assertSame('result-execution.retry', $route->queueFor('result-execution'));
        }
    }

    public function test_the_fourth_failure_is_parked_on_the_dlq(): void
    {
        $route = $this->router()->route(new RuntimeException('database is away'), 3);

        $this->assertFalse($route->isRetry());
        $this->assertSame(0, $route->delayMs, 'A dead letter is not delayed');
        $this->assertSame('result-execution.dlq', $route->queueFor('result-execution'));
    }

    /**
     * The api's equivalent of `apperror.Permanent`: a body this service cannot
     * read will not read on the third attempt, so it parks straight away
     * rather than occupying a worker three more times.
     */
    public function test_an_unreadable_message_is_parked_without_retrying(): void
    {
        $route = $this->router()->route(InvalidResultMessage::notJson('unexpected end'), 0);

        $this->assertFalse($route->isRetry());
        $this->assertSame('result-analysis.dlq', $route->queueFor('result-analysis'));
    }

    public function test_the_retry_count_carried_forward_advances_only_on_a_retry(): void
    {
        $this->assertSame(1, $this->router()->route(new RuntimeException('x'), 0)->nextRetryCount);
        $this->assertSame(3, $this->router()->route(new RuntimeException('x'), 3)->nextRetryCount);
    }
}
