<?php

declare(strict_types=1);

namespace App\Messaging;

use PHPUnit\Framework\Assert;

/**
 * Records messages instead of sending them, so a test can assert what the
 * request would have published without a broker in the loop.
 *
 * Lives in `app/` rather than `tests/` because `JobPublisher` is resolved from
 * the container: swapping the binding is how a test reaches it.
 */
class FakeJobPublisher implements JobPublisher
{
    /** @var list<QueueMessage> */
    private array $published = [];

    /** Set to make the next publish fail, standing in for a dead broker. */
    public ?PublishFailed $failure = null;

    public function publish(QueueMessage $message): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->published[] = $message;
    }

    /** @return list<QueueMessage> */
    public function published(?string $queue = null): array
    {
        if ($queue === null) {
            return $this->published;
        }

        return array_values(array_filter(
            $this->published,
            static fn (QueueMessage $message): bool => $message->queue() === $queue,
        ));
    }

    public function assertNothingPublished(): void
    {
        Assert::assertSame([], $this->published, 'expected no message to be published');
    }

    /** @return QueueMessage the single message on that queue */
    public function assertPublishedOnce(string $queue): QueueMessage
    {
        $messages = $this->published($queue);

        Assert::assertCount(1, $messages, "expected exactly one message on queue {$queue}");

        return $messages[0];
    }
}
