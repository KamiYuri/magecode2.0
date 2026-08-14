<?php

declare(strict_types=1);

namespace App\Messaging;

use RuntimeException;

/**
 * A message on a result queue that this service cannot read.
 *
 * Deliberately not an `ApiException`: nothing about it reaches an HTTP caller.
 * The consumer catches it, logs it and drops the message — a body that does
 * not parse will not parse on the third attempt either (D-79e).
 */
final class InvalidResultMessage extends RuntimeException
{
    public static function notJson(string $reason): self
    {
        return new self("result message is not JSON: {$reason}");
    }

    /** @param list<string> $missing */
    public static function missingFields(array $missing): self
    {
        return new self('result message is missing '.implode(', ', $missing));
    }

    public static function unknownStatus(string $status): self
    {
        return new self("result message carries unknown status {$status}");
    }
}
