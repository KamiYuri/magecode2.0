<?php

declare(strict_types=1);

namespace App\Messaging;

use RuntimeException;
use Throwable;

/**
 * The broker did not take the message.
 *
 * Deliberately not an `ApiException`: by the time this can be thrown the
 * submission is already durable, so it is not a refusal to report to the
 * caller (v3 §7, 2026-08-14). The caller logs it and moves on.
 */
final class PublishFailed extends RuntimeException
{
    public static function forQueue(string $queue, ?Throwable $previous = null): self
    {
        return new self("Could not publish to queue {$queue}.", 0, $previous);
    }

    public static function unroutable(string $queue): self
    {
        return new self(
            "The broker returned the message for queue {$queue} as unroutable; the queue is missing or misbound."
        );
    }

    public static function nacked(string $queue): self
    {
        return new self("The broker nacked the message for queue {$queue}.");
    }
}
