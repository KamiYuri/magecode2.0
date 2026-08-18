<?php

declare(strict_types=1);

namespace App\Enums;

/** Per-service status on an analysis_submission. `not_applicable` marks a
 *  service excluded from a partial re-run (D-54). */
enum ServiceStatus: string
{
    case InQueue = 'in_queue';
    case Processing = 'processing';
    case Completed = 'completed';
    case Error = 'error';
    case Timeout = 'timeout';
    case NotApplicable = 'not_applicable';

    /**
     * Nothing more will arrive for this service on this submission.
     *
     * The completion check (D-55) is written against this rather than against
     * a list of "done" values repeated at each call site: `not_applicable` is
     * as final as `completed`, and a submission waiting on a service nobody
     * asked for would otherwise never finish.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::InQueue, self::Processing => false,
            self::Completed, self::Error, self::Timeout, self::NotApplicable => true,
        };
    }

    /**
     * The two statuses a batch is still waiting on.
     *
     * @return list<string>
     */
    public static function pendingValues(): array
    {
        return [self::InQueue->value, self::Processing->value];
    }
}
