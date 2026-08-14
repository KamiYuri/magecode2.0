<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Messaging\AmqpPublisher;
use App\Messaging\Jobs\CodeExecutorJob;
use App\Messaging\PublishFailed;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Tests\Support\RequiresRabbitMq;
use Tests\TestCase;

/**
 * Publishes against the compose broker.
 *
 * Reading code proves the api's topology *looks* like `shared/go/rmq`'s. Only
 * the broker can prove they are interchangeable, which is what the redeclare
 * test below is for: RabbitMQ answers a mismatched redeclare with
 * PRECONDITION_FAILED, so if the two ever drift, this fails rather than C4.
 */
class AmqpPublisherTest extends TestCase
{
    use RequiresRabbitMq;

    /** @var array<string, mixed> */
    private array $config;

    /** Its own queue, so a run never eats a message a worker was waiting for. */
    private string $queue;

    private ?AMQPStreamConnection $inspector = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->requireRabbitMq();
        $this->queue = 'test-code-executor-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->inspector !== null) {
            $channel = $this->inspector->channel();

            foreach ([$this->queue, $this->queue.'.retry', $this->queue.'.dlq'] as $queue) {
                $channel->queue_delete($queue);
            }

            $channel->close();
            $this->inspector->close();
            $this->inspector = null;
        }

        parent::tearDown();
    }

    public function test_a_published_job_arrives_intact(): void
    {
        $job = new TestQueueMessage(CodeExecutorJob::for(4242), $this->queue);

        $publisher = new AmqpPublisher($this->config);
        $publisher->publish($job);
        $publisher->disconnect();

        $delivered = $this->readOne();

        $this->assertNotNull($delivered, 'nothing arrived on the queue');
        $this->assertSame($job->toArray(), json_decode($delivered->getBody(), true));
        $this->assertSame('application/json', $delivered->get('content_type'));
        // Persistent, or a broker restart loses a student's queued submission.
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $delivered->get('delivery_mode'));

        /** @var AMQPTable $headers */
        $headers = $delivered->get('application_headers');
        $this->assertSame($job->traceId(), $headers->getNativeData()['trace_id']);
    }

    /**
     * The whole point of the task: the api and the Go workers must be able to
     * declare the same queues. Different arguments here would make whichever
     * process declared second die on PRECONDITION_FAILED.
     */
    public function test_the_go_topology_redeclares_over_the_api_one(): void
    {
        $publisher = new AmqpPublisher($this->config);
        $publisher->publish(new TestQueueMessage(CodeExecutorJob::for(1), $this->queue));
        $publisher->disconnect();

        $channel = $this->inspectorChannel();

        // Transcribed from shared/go/rmq/client.go declareTopology.
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
        [, $messageCount] = $channel->queue_declare($this->queue, durable: true, auto_delete: false);

        $this->assertSame(1, $messageCount, 'the published message should be waiting in the main queue');
    }

    /**
     * Proves the test above is not vacuous: this broker really does refuse a
     * redeclare whose arguments differ, so "the Go arguments redeclare fine"
     * is a statement about the arguments and not about RabbitMQ being lenient.
     *
     * The wrong arguments used here are the ones `bschmitt/laravel-amqp` would
     * have applied by default (v3 §7, 2026-08-14).
     */
    public function test_a_mismatched_redeclare_is_refused(): void
    {
        $publisher = new AmqpPublisher($this->config);
        $publisher->publish(new TestQueueMessage(CodeExecutorJob::for(1), $this->queue));
        $publisher->disconnect();

        // Its own connection: a failed declare kills the channel, and killing
        // the inspector's would break tearDown's cleanup.
        $connection = new AMQPStreamConnection(
            (string) $this->config['host'],
            (int) $this->config['port'],
            (string) $this->config['user'],
            (string) $this->config['password'],
            (string) $this->config['vhost'],
        );

        try {
            $this->expectException(AMQPProtocolChannelException::class);

            $connection->channel()->queue_declare(
                $this->queue,
                durable: true,
                auto_delete: false,
                arguments: new AMQPTable(['x-max-length' => 1]),
            );
        } finally {
            $connection->close();
        }
    }

    /** mandatory=true: a queue that does not exist must not swallow the job. */
    public function test_an_unroutable_message_is_reported(): void
    {
        $publisher = new AmqpPublisher($this->config);

        // Declare the topology, then delete the main queue behind the
        // publisher's back so its cache still believes the queue is there.
        $publisher->publish(new TestQueueMessage(CodeExecutorJob::for(1), $this->queue));
        $this->inspectorChannel()->queue_delete($this->queue);

        $this->expectException(PublishFailed::class);
        $publisher->publish(new TestQueueMessage(CodeExecutorJob::for(2), $this->queue));
    }

    private function readOne(): ?AMQPMessage
    {
        $message = $this->inspectorChannel()->basic_get($this->queue, no_ack: true);

        return $message instanceof AMQPMessage ? $message : null;
    }

    private function inspectorChannel(): AMQPChannel
    {
        $this->inspector ??= new AMQPStreamConnection(
            (string) $this->config['host'],
            (int) $this->config['port'],
            (string) $this->config['user'],
            (string) $this->config['password'],
            (string) $this->config['vhost'],
        );

        return $this->inspector->channel();
    }
}
