<?php

declare(strict_types=1);

namespace App\Enums;

/** Judge0 verdict for one test case. */
enum TestCaseStatus: string
{
    case Accepted = 'accepted';
    case WrongAnswer = 'wrong_answer';
    case TimeLimitExceeded = 'time_limit_exceeded';
    case MemoryLimitExceeded = 'memory_limit_exceeded';
    case RuntimeError = 'runtime_error';
    case CompilationError = 'compilation_error';
    case InternalError = 'internal_error';
    case Timeout = 'timeout';
}
