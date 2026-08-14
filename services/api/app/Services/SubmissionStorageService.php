<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProgrammingLanguage;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * The only writer of submission source objects in MinIO.
 *
 * The object key is `submissions/{problem_id}/{submission_id}/{filename}`
 * (D-42/D-77) and must stay byte-identical to
 * `shared/go/storage.SubmissionPath`, which the code-executor uses to read the
 * same objects back. The Go side takes the filename verbatim; sanitising here is
 * safe because this service is the only thing that ever produces a key — it
 * narrows what gets written without changing the format.
 *
 * Uploads are capped at 50KB (D-34) and must carry an extension the chosen
 * language declares (U-4). Both entry points — a multipart upload and a JSON
 * `source_code` body — funnel into one writer so the stored object is identical
 * whichever way the student submitted.
 */
class SubmissionStorageService
{
    /** D-34: 50 KiB, matching Laravel's `max:50` validation rule. */
    public const MAX_FILE_BYTES = 51200;

    /** Names the file when the editor path supplies none. */
    public const DEFAULT_BASENAME = 'main';

    /** Keeps `submissions.file_path` (varchar 500) satisfiable for any id pair. */
    public const MAX_FILENAME_BYTES = 120;

    public function __construct(private readonly FilesystemFactory $filesystems) {}

    /** @throws SubmissionFileException when nothing usable is left of the filename */
    public function path(int $problemId, int $submissionId, string $filename): string
    {
        return "submissions/{$problemId}/{$submissionId}/".$this->sanitise($filename);
    }

    /** @throws SubmissionFileException */
    public function storeUpload(
        int $problemId,
        int $submissionId,
        UploadedFile $file,
        ProgrammingLanguage $language,
    ): StoredSubmissionFile {
        // Read the bytes rather than trusting getSize(): the limit must apply to
        // what is actually written.
        $contents = (string) file_get_contents($file->getRealPath());

        return $this->store($problemId, $submissionId, $contents, $file->getClientOriginalName(), $language);
    }

    /** @throws SubmissionFileException */
    public function storeSource(
        int $problemId,
        int $submissionId,
        string $source,
        ProgrammingLanguage $language,
    ): StoredSubmissionFile {
        $filename = self::DEFAULT_BASENAME.'.'.$language->defaultExtension();

        return $this->store($problemId, $submissionId, $source, $filename, $language);
    }

    /**
     * A pre-signed GET, TTL 6h by default (D-85). Signed against the internal
     * endpoint, so it is fetchable by SIM/AID/VUL on the backend network and not
     * by a browser. The object is not checked for existence first: a batch
     * signs hundreds of these and a HEAD apiece would be pure latency.
     */
    public function temporaryUrl(string $path, ?int $ttlSeconds = null): string
    {
        $ttl = $ttlSeconds ?? (int) config('minio.presigned_url_ttl');

        return $this->disk()->temporaryUrl($path, Carbon::now()->addSeconds($ttl));
    }

    /** Removing an object that is not there is not an error — C2 rolls back this way. */
    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    /**
     * The stored source, or null when the object is gone.
     *
     * A missing object is not an error the reader can act on: the row is the
     * record of the submission and the bytes are storage, so C2 renders
     * `source_code: null` rather than turning a lost object into a 500.
     */
    public function read(string $path): ?string
    {
        $disk = $this->disk();

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    /** @throws SubmissionFileException */
    private function store(
        int $problemId,
        int $submissionId,
        string $contents,
        string $filename,
        ProgrammingLanguage $language,
    ): StoredSubmissionFile {
        // Sanitise before anything else: Flysystem rejects a `..` segment with
        // its own exception, which would mask the refusal reason.
        $name = $this->sanitise($filename);

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if (! $language->allowsExtension($extension)) {
            throw SubmissionFileException::extensionNotAllowed($extension, $language->file_extensions);
        }

        $bytes = strlen($contents);
        if ($bytes === 0) {
            throw SubmissionFileException::emptyFile();
        }
        if ($bytes > self::MAX_FILE_BYTES) {
            throw SubmissionFileException::tooLarge($bytes, self::MAX_FILE_BYTES);
        }

        $path = "submissions/{$problemId}/{$submissionId}/{$name}";
        $this->disk()->put($path, $contents);

        return new StoredSubmissionFile($path, $name, $bytes);
    }

    /**
     * Reduce a client-supplied filename to something safe to append to a key,
     * while leaving anything a student would recognise untouched — no slug, no
     * ASCII filter, so `Giải Thuật.py` survives.
     *
     * @throws SubmissionFileException
     */
    private function sanitise(string $filename): string
    {
        $name = str_replace('\\', '/', $filename);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = basename($name);
        $name = ltrim(trim($name), '.');

        if ($name === '') {
            throw SubmissionFileException::unnamedFile();
        }

        return $this->truncate($name);
    }

    /** Trim the stem, never the extension, and never mid-character. */
    private function truncate(string $name): string
    {
        if (strlen($name) <= self::MAX_FILENAME_BYTES) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension === '' ? '' : '.'.$extension;
        $stem = $extension === '' ? $name : substr($name, 0, -strlen($suffix));

        $stem = mb_strcut($stem, 0, self::MAX_FILENAME_BYTES - strlen($suffix));

        if ($stem === '') {
            throw SubmissionFileException::unnamedFile();
        }

        return $stem.$suffix;
    }

    private function disk(): FilesystemAdapter
    {
        $disk = $this->filesystems->disk((string) config('minio.disk'));
        assert($disk instanceof FilesystemAdapter);

        return $disk;
    }
}
