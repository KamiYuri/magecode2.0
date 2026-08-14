<?php

declare(strict_types=1);

namespace App\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Publishes jobs to RabbitMQ over the **default exchange**, using the queue
 * name as the routing key.
 *
 * This mirrors `shared/go/rmq` exactly, and it has to: the Go workers declare
 * their own queues, and RabbitMQ rejects a redeclare whose arguments differ
 * from the original with PRECONDITION_FAILED. The trio below is a transcription
 * of `declareTopology` in `shared/go/rmq/client.go` — durable, argument-free
 * main queue and DLQ, and a retry queue that dead-letters back to the main one
 * (D-79e). Change one argument here and the api and the workers stop being
 * able to share a broker.
 *
 * Publishing is persistent, uses confirms, and sets `mandatory` so a message
 * with nowhere to go comes back as an error rather than disappearing — the
 * exact failure a named exchange with no binding would have caused
 * (v3 §7, 2026-08-14).
 */
class AmqpPublisher implements JobPublisher
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /** @var array<string, true> queues whose topology this instance has declared */
    private array $declared = [];

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function publish(QueueMessage $message): void
    {
        $queue = $message->queue();

        try {
            $channel = $this->channelFor($queue);

            $channel->basic_publish(
                $this->encode($message),
                exchange: '',
                routing_key: $queue,
                mandatory: true,
            );

            $channel->wait_for_pending_acks_returns((float) $this->config['publish_timeout']);
        } catch (PublishFailed $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            // The connection may be half-open after any of these; drop it so
            // the next publish redials rather than reusing a dead socket.
            $this->disconnect();

            throw PublishFailed::forQueue($queue, $failure);
        }
    }

    public function disconnect(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (Throwable) {
            // Closing a socket the broker already dropped is not a failure
            // worth reporting — the caller is discarding this anyway.
        }

        $this->channel = null;
        $this->connection = null;
        $this->declared = [];
    }

    private function encode(QueueMessage $message): AMQPMessage
    {
        return new AMQPMessage(
            (string) json_encode($message->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'timestamp' => time(),
                // Header name matches `headerTraceID` in shared/go/rmq/rmq.go;
                // the consumer reads `trace_id`, not `x-trace-id`.
                'application_headers' => new AMQPTable(['trace_id' => $message->traceId()]),
            ],
        );
    }

    private function channelFor(string $queue): AMQPChannel
    {
        $channel = $this->channel();

        if (! isset($this->declared[$queue])) {
            $this->declareTopology($channel, $queue);
            $this->declared[$queue] = true;
        }

        return $channel;
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            return $this->channel;
        }

        $this->connection = new AMQPStreamConnection(
            (string) $this->config['host'],
            (int) $this->config['port'],
            (string) $this->config['user'],
            (string) $this->config['password'],
            (string) $this->config['vhost'],
            connection_timeout: (float) $this->config['connect_timeout'],
            read_write_timeout: (float) $this->config['publish_timeout'],
        );

        $channel = $this->connection->channel();
        $channel->confirm_select();
        $channel->set_nack_handler(function (AMQPMessage $message): void {
            throw PublishFailed::nacked((string) $message->getRoutingKey());
        });
        $channel->set_return_listener(
            function (int $code, string $text, string $exchange, string $routingKey): void {
                throw PublishFailed::unroutable($routingKey);
            }
        );

        // A fresh channel knows nothing of what an older one declared.
        $this->declared = [];

        return $this->channel = $channel;
    }

    /**
     * The D-79e trio, in the same order and with the same arguments as
     * `declareTopology` in shared/go/rmq. Idempotent on both sides.
     */
    private function declareTopology(AMQPChannel $channel, string $queue): void
    {
        $channel->queue_declare($queue.'.dlq', durable: true, auto_delete: false);

        $channel->queue_declare(
            $queue.'.retry',
            durable: true,
            auto_delete: false,
            arguments: new AMQPTable([
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $queue,
            ]),
        );

        $channel->queue_declare($queue, durable: true, auto_delete: false);
    }
}
