<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Messaging\AmqpConsumerLoop;
use App\Messaging\AnalysisResultHandler;
use Illuminate\Console\Command;

/**
 * Long-running consumer of `result-analysis`: SIM, AID and VUL send their
 * results here and api writes them, because api is the only writer in the
 * batch path (D-80, D-83).
 *
 * The twin of `ConsumeExecutionResults`, and now literally so: both drive
 * `AmqpConsumerLoop`, which declares the topology `shared/go/rmq` expects and
 * routes failures the way D-79e says. Not a Laravel queue worker -- the
 * producers are Go and Python services with no idea of Laravel's format.
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

    public function handle(AmqpConsumerLoop $loop, AnalysisResultHandler $handler): int
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
