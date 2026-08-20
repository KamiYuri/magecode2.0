<?php

declare(strict_types=1);

namespace App\Messaging;

/**
 * Where a failed delivery goes and how long it waits first — the api-side
 * value of `shared/go/rmq`'s (failureAction, delay) pair.
 */
final readonly class FailureRoute
{
    private function __construct(
        private bool $retry,
        public int $delayMs,
        public int $nextRetryCount,
    ) {}

    public static function retry(int $delayMs, int $nextRetryCount): self
    {
        return new self(true, $delayMs, $nextRetryCount);
    }

    public static function deadLetter(int $retryCount): self
    {
        return new self(false, 0, $retryCount);
    }

    public function isRetry(): bool
    {
        return $this->retry;
    }

    /** The queue this route publishes to, given the queue the message came from. */
    public function queueFor(string $queue): string
    {
        return $queue.($this->retry ? '.retry' : '.dlq');
    }
}
