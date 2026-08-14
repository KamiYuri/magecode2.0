<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Messaging\QueueMessage;

/**
 * A real job redirected to a throwaway queue, so an integration run publishes
 * the exact payload production would without competing with a live worker for
 * `code-executor`.
 */
final readonly class TestQueueMessage implements QueueMessage
{
    public function __construct(private QueueMessage $inner, private string $queue) {}

    public function queue(): string
    {
        return $this->queue;
    }

    public function traceId(): string
    {
        return $this->inner->traceId();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->inner->toArray();
    }
}
