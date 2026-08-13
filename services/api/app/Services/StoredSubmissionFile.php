<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What was written, in the shape `submissions` stores it: `path` goes to
 * `file_path`, `name` to `file_name`.
 */
final readonly class StoredSubmissionFile
{
    public function __construct(
        public string $path,
        public string $name,
        public int $bytes,
    ) {}
}
