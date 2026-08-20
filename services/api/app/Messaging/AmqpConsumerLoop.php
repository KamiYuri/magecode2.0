<?php

declare(strict_types=1);

namespace App\Messaging;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * The consume loop both api result consumers run.
 *
 * It exists because the two commands were near-identical copies and each
 * carried the same two defects. First, they treated a lost connection as a
 * clean finish: `while ($channel->is_consuming())` falls through the moment
 * the broker goes away, the command returned SUCCESS, and only compose's
 * `restart: unless-stopped` kept results flowing -- both containers had
 * restarted seven times. Second, every failure was `nack(requeue: true)`,
 * which redelivers immediately and forever, so a message that always fails
 * spun in a hot loop and, at a prefetch of one, blocked everything behind it.
 *
 * So: reconnect with backoff like `shared/go/rmq` does, and route failures
 * through `FailureRouter` into the `.retry`/`.dlq` queues that were being
 * declared and never used. Both are covered by `AmqpConsumerLoopTest` against
 * a real broker.
 *
 * The SIGTERM handler below is a third, *unproven* piece. It works when the
 * process has a parent -- verified by sending SIGTERM to the command run as a
 * child -- but compose runs `php artisan` as PID 1, and there the handler does
 * not run: `docker stop` waits its full grace period and SIGKILLs. That is not
 * this class's doing (a five-line PHP script with a pcntl handler behaves the
 * same way as PID 1 in this image), so **D-73 is not met for these two
 * processes** and the fix belongs with whoever owns the container's init --
 * `init: true` in compose is the usual answer, and it stopped the container in
 * one second here, though not yet with the clean exit that would prove the
 * handler ran. Left in place because it costs nothing and is correct the
 * moment the process stops being PID 1.
 */
final class AmqpConsumerLoop
{
    /**
     * How long one `wait()` blocks. It bounds how quickly the loop notices a
     * SIGTERM, so it is short; a timeout is the normal state of an idle queue,
     * not a failure.
     */
    private const WAIT_TIMEOUT_SECONDS = 5;

    /**
     * Without a heartbeat a broker that vanishes is invisible until the next
     * publish, which for a consumer may be never. `read_write_timeout` must
     * be at least twice this or php-amqplib rejects the pair.
     */
    private const HEARTBEAT_SECONDS = 60;

    private const RECONNECT_BASE_MS = 500;

    private const RECONNECT_MAX_MS = 30_000;

    private bool $stopping = false;

    public function __construct(private readonly FailureRouter $router) {}

    /**
     * Read through a method rather than touching the property: the flag is
     * only ever set from a signal handler, which static analysis cannot see
     * being called, so a direct read gets narrowed to `false` and every
     * shutdown check looks like dead code.
     */
    private function stopRequested(): bool
    {
        return $this->stopping;
    }

    /**
     * Consumes `$queue` until SIGTERM, or until one message is handled when
     * `$once` is set (the smoke-test path).
     *
     * @param  callable(string): void  $handle  receives the raw message body
     */
    public function run(string $queue, int $prefetch, callable $handle, bool $once = false): void
    {
        $this->listenForTermination();

        $attempt = 0;

        while (! $this->stopRequested()) {
            try {
                $this->session($queue, $prefetch, $handle, $once);

                if ($once || $this->stopRequested()) {
                    return;
                }

                // `is_consuming()` went false without throwing: the broker
                // cancelled the consumer or the connection went away quietly.
                Log::warning('amqp consumer stopped consuming, reconnecting', [
                    'queue' => $queue,
                ]);
                $attempt = 0;
            } catch (Throwable $failure) {
                if ($once) {
                    throw $failure;
                }

                Log::error('amqp consumer session failed, reconnecting', [
                    'queue' => $queue,
                    'error' => $failure->getMessage(),
                ]);
            }

            if ($this->stopRequested()) {
                return;
            }

            usleep($this->reconnectDelayMs($attempt) * 1000);
            $attempt++;
        }
    }

    /**
     * One connection's worth of consuming. Returns when the broker stops
     * feeding us, when SIGTERM arrives, or when `$once` has had its message.
     *
     * @param  callable(string): void  $handle
     */
    private function session(string $queue, int $prefetch, callable $handle, bool $once): void
    {
        $connection = $this->connect();

        try {
            $channel = $connection->channel();
            $this->declareTopology($channel, $queue);
            $channel->basic_qos(0, $prefetch, false);

            $handled = 0;

            $channel->basic_consume(
                $queue,
                consumer_tag: 'magecode-api-'.getmypid(),
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: function (AMQPMessage $message) use ($channel, $queue, $handle, &$handled): void {
                    $this->settle($channel, $queue, $handle, $message);
                    $handled++;
                },
            );

            Log::info('consuming '.$queue, ['queue' => $queue]);

            while ($channel->is_consuming() && ! $this->stopRequested()) {
                try {
                    $channel->wait(timeout: self::WAIT_TIMEOUT_SECONDS);
                } catch (AMQPTimeoutException) {
                    // An idle queue. Loop round so SIGTERM is noticed.
                    continue;
                }

                if ($once && $handled > 0) {
                    break;
                }
            }

            $channel->close();
        } finally {
            $this->close($connection);
        }
    }

    /**
     * Runs the handler and settles the delivery: ack on success, otherwise
     * hand it to `FailureRouter` and publish a copy onto `.retry` or `.dlq`.
     *
     * @param  callable(string): void  $handle
     */
    private function settle(AMQPChannel $channel, string $queue, callable $handle, AMQPMessage $message): void
    {
        $retryCount = (int) ($this->header($message, 'x-retry-count') ?? 0);
        $traceId = (string) ($this->header($message, 'trace_id') ?? '');

        try {
            $handle($message->getBody());
            $message->ack();

            return;
        } catch (Throwable $failure) {
            $route = $this->router->route($failure, $retryCount);

            try {
                $this->publishCopy($channel, $route->queueFor($queue), $message, $route, $traceId, $failure);
            } catch (Throwable $routingFailure) {
                // The broker refused the copy, so the original is the only
                // copy left: requeue it rather than acking it into nothing.
                Log::error('routing a failed delivery failed, requeueing', [
                    'queue' => $queue,
                    'trace_id' => $traceId,
                    'error' => $routingFailure->getMessage(),
                ]);
                $message->nack(requeue: true);

                return;
            }

            Log::warning('delivery failed', [
                'queue' => $queue,
                'trace_id' => $traceId,
                'retry_count' => $retryCount,
                'action' => $route->isRetry() ? 'retry' : 'dead-letter',
                'error' => $failure->getMessage(),
            ]);

            $message->ack();
        }
    }

    private function publishCopy(
        AMQPChannel $channel,
        string $target,
        AMQPMessage $message,
        FailureRoute $route,
        string $traceId,
        Throwable $failure,
    ): void {
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'application_headers' => new AMQPTable([
                'x-retry-count' => $route->nextRetryCount,
                'trace_id' => $traceId,
                'x-last-error' => $failure->getMessage(),
            ]),
        ];

        // Per-message TTL, matching `shared/go/rmq`: the `.retry` queue
        // dead-letters back onto the main queue when this expires, which is
        // what makes the delay a delay rather than a sleep in this process.
        if ($route->delayMs > 0) {
            $properties['expiration'] = (string) $route->delayMs;
        }

        $channel->basic_publish(new AMQPMessage($message->getBody(), $properties), '', $target);
    }

    /**
     * The same trio `shared/go/rmq` declares (D-79e). The arguments must match
     * byte for byte or whichever side declares second dies on
     * PRECONDITION_FAILED.
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

    private function connect(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            (string) config('amqp.host'),
            (int) config('amqp.port'),
            (string) config('amqp.user'),
            (string) config('amqp.password'),
            (string) config('amqp.vhost'),
            connection_timeout: (float) config('amqp.connect_timeout'),
            read_write_timeout: self::HEARTBEAT_SECONDS * 2 + 10,
            keepalive: true,
            heartbeat: self::HEARTBEAT_SECONDS,
        );
    }

    private function close(AMQPStreamConnection $connection): void
    {
        try {
            $connection->close();
        } catch (Throwable) {
            // Already gone. There is nothing to salvage and nothing to say.
        }
    }

    private function header(AMQPMessage $message, string $key): int|string|null
    {
        /** @var AMQPTable|array<string, mixed>|null $headers */
        $headers = $message->get_properties()['application_headers'] ?? null;

        if ($headers instanceof AMQPTable) {
            $headers = $headers->getNativeData();
        }

        $value = is_array($headers) ? ($headers[$key] ?? null) : null;

        return is_int($value) || is_string($value) ? $value : null;
    }

    private function reconnectDelayMs(int $attempt): int
    {
        $delay = self::RECONNECT_BASE_MS << min($attempt, 8);

        return min($delay, self::RECONNECT_MAX_MS);
    }

    /**
     * Stop after the message in hand rather than mid-transaction. See the
     * class docblock: this does not fire while the command is PID 1, which is
     * how compose runs it today.
     */
    private function listenForTermination(): void
    {
        if (! function_exists('pcntl_async_signals') || ! defined('SIGTERM')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () use ($signal): void {
                Log::info('shutting down after the message in hand', ['signal' => $signal]);
                $this->stopping = true;
            });
        }
    }
}
