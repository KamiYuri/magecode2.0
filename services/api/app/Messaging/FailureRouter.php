<?php

declare(strict_types=1);

namespace App\Messaging;

use Throwable;

/**
 * D-79e's routing decision, transcribed from `shared/go/rmq/route.go`.
 *
 * Both api consumers used to answer every failure with `nack(requeue: true)`,
 * which re-delivers immediately and forever: with a prefetch of one, a message
 * that always fails is a hot loop that also blocks every message behind it.
 * The `.retry`/`.dlq` queues the consumers already declare exist precisely so
 * that cannot happen, and this is what routes to them.
 *
 * A permanent failure parks at once; anything else backs off exponentially and
 * parks once the retry budget is spent. An unclassified error is treated as
 * transient for the same reason the Go side does it — a flaky dependency
 * deserves its three attempts.
 */
final readonly class FailureRouter
{
    public function __construct(
        private int $maxRetries = 3,
        private int $baseDelayMs = 1000,
    ) {}

    public function route(Throwable $failure, int $retryCount): FailureRoute
    {
        if ($this->isPermanent($failure) || $retryCount >= $this->maxRetries) {
            return FailureRoute::deadLetter($retryCount);
        }

        return FailureRoute::retry($this->baseDelayMs << $retryCount, $retryCount + 1);
    }

    /**
     * `InvalidResultMessage` is the api's `apperror.Permanent`: the body does
     * not parse, and it will not parse later either.
     */
    private function isPermanent(Throwable $failure): bool
    {
        return $failure instanceof InvalidResultMessage;
    }
}
