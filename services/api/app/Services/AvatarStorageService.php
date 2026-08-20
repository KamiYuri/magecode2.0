<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;

/**
 * The only writer of avatar objects in MinIO — users' and organizations'.
 *
 * Kept apart from `SubmissionStorageService` on purpose: that one's key must
 * stay byte-identical to `shared/go/storage.SubmissionPath` because the Go
 * workers read the same objects back, and nothing outside api ever reads an
 * avatar. Two invariants, two services.
 *
 * Avatars are **streamed by api** (v3 §7, 2026-08-14): pre-signed URLs are
 * signed against the internal endpoint, so they serve the batch workers and
 * not a browser, and no bucket is ever published. The object key is therefore
 * private to this service — the client only ever sees
 * `GET /users/{id}/avatar`.
 *
 * The size and mime limits are this task's own: neither openapi nor a
 * D-decision names them, and an unbounded upload into a bucket the api streams
 * from is a slow request for every viewer afterwards.
 */
class AvatarStorageService
{
    /** 2 MiB. Generous for a 512px avatar, small enough to stream cheaply. */
    public const MAX_FILE_BYTES = 2097152;

    /** @var list<string> The formats every target browser decodes. */
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private readonly FilesystemFactory $filesystems) {}

    /**
     * Writes the object and returns its key.
     *
     * The key carries a random component so a replaced avatar never collides
     * with a cached copy of the old one; the caller stores what comes back and
     * deletes what it replaced.
     */
    public function store(string $ownerType, int $ownerId, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $extension = 'jpg';
        }

        $path = "avatars/{$ownerType}/{$ownerId}/".bin2hex(random_bytes(8)).".{$extension}";

        $this->disk()->put($path, (string) file_get_contents($file->getRealPath()), [
            'ContentType' => (string) $file->getMimeType(),
        ]);

        return $path;
    }

    /** Null when the object is gone: a row may outlive its object. */
    public function read(string $path): ?string
    {
        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }

    public function mimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /** Quiet when the object is already gone — deleting twice is not an error. */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if ($this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = $this->filesystems->disk('minio');

        return $disk;
    }
}
