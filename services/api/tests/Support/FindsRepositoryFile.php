<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Locates a monorepo file that lives above this service — the API contract,
 * the queue schemas — by walking up from the app root.
 *
 * The same walk works on the host, where the repository root is two levels up,
 * and in the test image, where the Makefile mounts each directory one level
 * above the app root. Neither side has to know which it is.
 */
trait FindsRepositoryFile
{
    /** How far above the app root to look before giving up. */
    private const MAX_DEPTH = 5;

    protected function repositoryFile(string $relativePath): string
    {
        $directory = base_path();

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $candidate = $directory.'/'.$relativePath;

            if (is_file($candidate)) {
                return $candidate;
            }

            $directory = dirname($directory);
        }

        $this->fail("Could not find {$relativePath} above ".base_path());
    }
}
