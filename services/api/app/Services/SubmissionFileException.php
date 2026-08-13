<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A source file was refused before it reached MinIO.
 *
 * Deliberately not an `ApiException`: openapi's 422 code set for the submission
 * endpoints carries no file codes, so C2 rejects oversize and wrong-extension
 * uploads in its FormRequest and returns the ordinary validation envelope. This
 * exception is the layer underneath that — the guarantee that nothing outside
 * the contract can be written even if a caller skips validation — and `$reason`
 * lets the caller map it without matching message strings.
 */
final class SubmissionFileException extends RuntimeException
{
    public const FILE_TOO_LARGE = 'FILE_TOO_LARGE';

    public const EXTENSION_NOT_ALLOWED = 'EXTENSION_NOT_ALLOWED';

    public const EMPTY_FILE = 'EMPTY_FILE';

    public const UNNAMED_FILE = 'UNNAMED_FILE';

    public const LANGUAGE_HAS_NO_EXTENSIONS = 'LANGUAGE_HAS_NO_EXTENSIONS';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function tooLarge(int $bytes, int $limit): self
    {
        return new self(
            self::FILE_TOO_LARGE,
            "Source file is {$bytes} bytes; the limit is {$limit} bytes."
        );
    }

    /** @param  list<string>  $allowed */
    public static function extensionNotAllowed(string $extension, array $allowed): self
    {
        $list = implode(', ', $allowed);

        return new self(
            self::EXTENSION_NOT_ALLOWED,
            "Extension '{$extension}' is not accepted for this language; expected one of: {$list}."
        );
    }

    public static function emptyFile(): self
    {
        return new self(self::EMPTY_FILE, 'Source file is empty.');
    }

    public static function unnamedFile(): self
    {
        return new self(self::UNNAMED_FILE, 'Source file has no usable name.');
    }

    public static function languageHasNoExtensions(string $language): self
    {
        return new self(
            self::LANGUAGE_HAS_NO_EXTENSIONS,
            "Language '{$language}' has no configured file extensions."
        );
    }
}
