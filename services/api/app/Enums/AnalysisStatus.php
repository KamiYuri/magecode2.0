<?php

declare(strict_types=1);

namespace App\Enums;

/** Batch-level analysis status; `timeout` is set by the D-82 scheduler. */
enum AnalysisStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Timeout = 'timeout';
}
