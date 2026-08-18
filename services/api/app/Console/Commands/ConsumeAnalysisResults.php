<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\AnalysisResultHandler;
use App\Messaging\InvalidResultMessage;
use Illuminate\Console\Command;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Long-running consumer of `result-analysis`: SIM, AID and VUL send their
 * results here and api writes them, because api is the only writer in the
 * batch path (D-80, D-83).
 *
 * Structurally a twin of `ConsumeExecutionResults` — deliberately, since the
 * topology it declares must stay byte-identical to `shared/go/rmq`'s or
 * whichever side declares second dies on PRECONDITION_FAILED. Not a Laravel
 * queue worker: the producers are Go and Python services with no idea of
 * Laravel's payload format.
 */
class ConsumeAnalysisResults extends Command
{
    protected $signature = 'amqp:consume-analysis {--once : Handle one message and exit, for smoke tests}';

    protected $description = 'Consume result-analysis and write SIM/AID/VUL results';

    private const QUEUE = 'result-analysis';

    /**
     * One at a time (D-76): a SIM message can carry thousands of pairs and is
     * written in one transaction, so a second in flight buys nothing but
     * memory and lock contention.
     */
    private const PREFETCH = 1;

    public function handle(AnalysisResultHandler $handler): int
    {
        $connection = $this->connect();
        $channel = $connection->channel();

        $this->declareTopology($channel);
        $channel->basic_qos(0, self::PREFETCH, false);

        $channel->basic_consume(
            self::QUEUE,
            consumer_tag: 'magecode-api-'.getmypid(),
            no_local: false,
            no_ack: false,
            exclusive: false,
            nowait: false,
            callback: fn (AMQPMessage $message) => $this->settle($handler, $message),
        );

        $this->info('consuming '.self::QUEUE);

        while ($channel->is_consuming()) {
            $channel->wait();

            if ($this->option('once')) {
                break;
            }
        }

        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }

    private function settle(AnalysisResultHandler $handler, AMQPMessage $message): void
    {
        try {
            $handler->handle($message->getBody());
            $message->ack();
        } catch (InvalidResultMessage $invalid) {
            // A body this service cannot read will not read on a retry, so it
            // is dropped rather than requeued into a loop (D-79e).
            $this->error('dropping unreadable message: '.$invalid->getMessage());
            $message->reject(false);
        } catch (Throwable $failure) {
            // Anything else — the database, the broadcaster — may pass later.
            $this->error('requeueing after failure: '.$failure->getMessage());
            $message->nack(true);
        }
    }

    /**
     * The same trio `shared/go/rmq` declares (D-79e). The arguments must match
     * byte for byte or whichever side declares second dies on
     * PRECONDITION_FAILED.
     */
    private function declareTopology(AMQPChannel $channel): void
    {
        $channel->queue_declare(self::QUEUE.'.dlq', durable: true, auto_delete: false);
        $channel->queue_declare(
            self::QUEUE.'.retry',
            durable: true,
            auto_delete: false,
            arguments: new AMQPTable([
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => self::QUEUE,
            ]),
        );
        $channel->queue_declare(self::QUEUE, durable: true, auto_delete: false);
    }

    private function connect(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            (string) config('amqp.host'),
            (int) config('amqp.port'),
            (string) config('amqp.user'),
            (string) config('amqp.password'),
            (string) config('amqp.vhost'),
        );
    }
}
