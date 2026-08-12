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
}
