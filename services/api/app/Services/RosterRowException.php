<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * One roster row could not be enrolled. Carried back to the caller as a
 * `{row, error}` entry rather than an HTTP status: the file as a whole still
 * succeeded.
 */
final class RosterRowException extends RuntimeException {}
