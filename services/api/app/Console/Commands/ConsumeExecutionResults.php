<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\AmqpConsumerLoop;
use App\Messaging\ExecutionResultHandler;
use Illuminate\Console\Command;

/**
 * Long-running consumer of `result-execution`: turns CES's signals into the
 * WebSocket frames the student is waiting for (C7, D-83).
 *
 * Deliberately not a Laravel queue worker. The producer is a Go service
 * speaking the topology in `shared/go/rmq`, and Laravel's own queue payload
 * format has nothing to do with it. The connection handling, D-79e failure
 * routing and SIGTERM drain all live in `AmqpConsumerLoop`, shared with
 * `amqp:consume-analysis`.
 */
class ConsumeExecutionResults extends Command
{
    protected $signature = 'amqp:consume-execution {--once : Handle one message and exit, for smoke tests}';

    protected $description = 'Consume result-execution and broadcast submission verdicts';

    private const QUEUE = 'result-execution';

    /** One at a time: broadcasting is cheap, and the order the student sees matters. */
    private const PREFETCH = 1;

    public function handle(AmqpConsumerLoop $loop, ExecutionResultHandler $handler): int
    {
        $loop->run(
            self::QUEUE,
            self::PREFETCH,
            fn (string $body) => $handler->handle($body),
            (bool) $this->option('once'),
        );

        return self::SUCCESS;
    }
}
