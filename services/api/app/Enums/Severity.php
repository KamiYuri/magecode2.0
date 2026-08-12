<?php

declare(strict_types=1);

namespace App\Enums;

/** CodeQL finding severity. */
enum Severity: string
{
    case Recommendation = 'recommendation';
    case Warning = 'warning';
    case Error = 'error';
}
