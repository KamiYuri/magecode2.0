<?php

declare(strict_types=1);

namespace App\Messaging;

/**
 * A message this service publishes, in the shape `shared/schemas/` declares
 * for its queue.
 *
 * The queue name travels with the message because the topology routes by it:
 * publishing goes through the default exchange with the queue name as the
 * routing key, matching `shared/go/rmq` (v3 §7, 2026-08-14).
 */
interface QueueMessage
{
    /** Queue name, which is also the routing key. */
    public function queue(): string;

    /** D-88: the id that ties this message to the request that caused it. */
    public function traceId(): string;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
