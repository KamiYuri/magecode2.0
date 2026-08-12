<?php

declare(strict_types=1);

namespace App\Enums;

/** Overall CES verdict on a submission, written by code-executor (D-81). */
enum ExecutionStatus: string
{
    case InQueue = 'in_queue';
    case Processing = 'processing';
    case Accepted = 'accepted';
    case PartiallyAccepted = 'partially_accepted';
    case Error = 'error';
    case Timeout = 'timeout';
    case LanguageNotSupported = 'language_not_supported';
}
