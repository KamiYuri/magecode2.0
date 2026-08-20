<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Messaging\AmqpConsumerLoop;
use App\Messaging\FailureRouter;
use App\Messaging\InvalidResultMessage;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Tests\Support\RequiresRabbitMq;
use Tests\TestCase;

/**
 * Proves against a real broker that a failing message ends up parked instead
 * of spinning.
 *
 * Reading the code shows the loop *calls* FailureRouter; only the broker shows
 * that the `.retry` queue's per-message TTL really dead-letters back onto the
 * main queue, which is the mechanism the whole retry budget rests on. Before
 * this, both consumers answered every failure with `nack(requeue: true)` --
 * an immediate, endless redelivery that a unit test would have been happy to
 * confirm.
 */
class AmqpConsumerLoopTest extends TestCase
{
    use RequiresRabbitMq;

    /** @var array<string, mixed> */
    private array $config;

    /** Its own queue, so a run never eats a message a worker was waiting for. */
    private string $queue;

    private ?AMQPStreamConnection $inspector = null;

    /** Milliseconds. Small enough that three retries take under a second. */
    private const BASE_DELAY_MS = 50;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->requireRabbitMq();
        $this->queue = 'test-result-execution-'.bin2hex(random_bytes(4));

        config([
            'amqp.host' => $this->config['host'],
            'amqp.port' => $this->config['port'],
            'amqp.user' => $this->config['user'],
            'amqp.password' => $this->config['password'],
            'amqp.vhost' => $this->config['vhost'],
        ]);
    }

    protected function tearDown(): void
    {
        // Independent of whether the inspector was ever opened: a test that
        // failed after declaring its three queues still declared them, and a
        // durable queue nobody deletes outlives the suite. Guarded only on
        // the skip path, where there is no broker to talk to.
        if (! isset($this->config)) {
            parent::tearDown();

            return;
        }

        $channel = $this->connection()->channel();

        foreach ([$this->queue, $this->queue.'.retry', $this->queue.'.dlq'] as $queue) {
            $channel->queue_delete($queue);
        }

        $channel->close();
        $this->inspector?->close();
        $this->inspector = null;

        parent::tearDown();
    }

    public function test_a_message_that_always_fails_is_parked_after_three_retries(): void
    {
        $attempts = 0;

        $loop = new AmqpConsumerLoop(new FailureRouter(maxRetries: 3, baseDelayMs: self::BASE_DELAY_MS));
        $handle = function () use (&$attempts): void {
            $attempts++;
            throw new RuntimeException('the database is away');
        };

        $this->publish(['submission_id' => 4242]);

        // Four passes: three retries, then the budget is spent and it parks.
        // Each pass returns as soon as it has settled its one message; the
        // wait in between is the `.retry` queue's TTL expiring back onto the
        // main queue, which is the part only a broker can demonstrate.
        for ($pass = 0; $pass < 4; $pass++) {
            $loop->run($this->queue, 1, $handle, once: true);
            usleep((self::BASE_DELAY_MS << $pass) * 1000 + 150_000);
        }

        $this->assertSame(4, $attempts, 'one first delivery plus three retries');

        $parked = $this->readOne($this->queue.'.dlq');
        $this->assertNotNull($parked, 'the message should be on the dlq');

        /** @var AMQPTable $headers */
        $headers = $parked->get('application_headers');
        $native = $headers->getNativeData();

        $this->assertSame(3, $native['x-retry-count'], 'the retry budget is spent');
        $this->assertStringContainsString('the database is away', (string) $native['x-last-error']);

        $this->assertNull(
            $this->readOne($this->queue),
            'nothing may be left on the main queue, or the hot loop is still there'
        );
    }

    /**
     * `InvalidResultMessage` is the api's permanent error: it must not consume
     * the retry budget, because a body that does not parse never will.
     */
    public function test_an_unreadable_message_is_parked_immediately(): void
    {
        $attempts = 0;

        $loop = new AmqpConsumerLoop(new FailureRouter(maxRetries: 3, baseDelayMs: self::BASE_DELAY_MS));

        $this->publish(['not' => 'a result']);

        $loop->run($this->queue, 1, function () use (&$attempts): void {
            $attempts++;
            throw InvalidResultMessage::notJson('unexpected end of input');
        }, once: true);

        $this->assertSame(1, $attempts, 'a permanent failure is not retried');

        $parked = $this->readOne($this->queue.'.dlq');
        $this->assertNotNull($parked, 'the message should be on the dlq at once');

        /** @var AMQPTable $headers */
        $headers = $parked->get('application_headers');
        $this->assertSame(0, $headers->getNativeData()['x-retry-count']);
    }

    public function test_a_handled_message_is_acked_and_leaves_nothing_behind(): void
    {
        $loop = new AmqpConsumerLoop(new FailureRouter);
        $seen = null;

        $this->publish(['submission_id' => 7]);

        $loop->run($this->queue, 1, function (string $body) use (&$seen): void {
            $seen = json_decode($body, true);
        }, once: true);

        $this->assertSame(['submission_id' => 7], $seen);
        $this->assertNull($this->readOne($this->queue));
        $this->assertNull($this->readOne($this->queue.'.dlq'));
    }

    /** @param array<string, mixed> $payload */
    private function publish(array $payload): void
    {
        $channel = $this->connection()->channel();

        // The loop declares the trio itself; declaring here too would be a
        // redeclare with the same arguments, which is exactly what must work.
        $channel->queue_declare($this->queue.'.dlq', durable: true, auto_delete: false);
        $channel->queue_declare(
            $this->queue.'.retry',
            durable: true,
            auto_delete: false,
            arguments: new AMQPTable([
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $this->queue,
            ]),
        );
        $channel->queue_declare($this->queue, durable: true, auto_delete: false);

        $channel->basic_publish(
            new AMQPMessage((string) json_encode($payload), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable(['trace_id' => 'loop-test']),
            ]),
            '',
            $this->queue,
        );

        $channel->close();
    }

    private function readOne(string $queue): ?AMQPMessage
    {
        $channel = $this->connection()->channel();
        $message = $channel->basic_get($queue);

        if ($message !== null) {
            $message->ack();
        }

        $channel->close();

        return $message;
    }

    private function connection(): AMQPStreamConnection
    {
        return $this->inspector ??= new AMQPStreamConnection(
            (string) $this->config['host'],
            (int) $this->config['port'],
            (string) $this->config['user'],
            (string) $this->config['password'],
            (string) $this->config['vhost'],
        );
    }
}
