<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ProgrammingLanguage;
use App\Services\StoredSubmissionFile;
use App\Services\SubmissionFileException;
use App\Services\SubmissionStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubmissionStorageServiceTest extends TestCase
{
    private SubmissionStorageService $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');
        $this->storage = app(SubmissionStorageService::class);
    }

    // ── Path scheme (D-42/D-77) ──

    public function test_the_path_is_problem_then_submission_then_filename(): void
    {
        $this->assertSame(
            'submissions/12/1042/main.go',
            $this->storage->path(12, 1042, 'main.go')
        );
    }

    public function test_a_non_ascii_filename_survives_intact(): void
    {
        // Same vector as shared/go/storage/storage_test.go — CES reads objects
        // this service writes, so the two path builders must agree byte for byte.
        $this->assertSame(
            'submissions/3/7/Giải Thuật.py',
            $this->storage->path(3, 7, 'Giải Thuật.py')
        );
    }

    // ── Filename sanitisation ──

    public function test_a_traversal_attempt_is_reduced_to_its_basename(): void
    {
        $this->assertSame('submissions/1/1/passwd', $this->storage->path(1, 1, '../../etc/passwd'));
        $this->assertSame('submissions/1/1/b.py', $this->storage->path(1, 1, 'a/b.py'));
        $this->assertSame('submissions/1/1/b.py', $this->storage->path(1, 1, 'a\\b.py'));
    }

    public function test_control_characters_and_leading_dots_are_stripped(): void
    {
        $this->assertSame('submissions/1/1/x.py', $this->storage->path(1, 1, "x\x00.py"));
        $this->assertSame('submissions/1/1/x.py', $this->storage->path(1, 1, "x\n.py"));
        $this->assertSame('submissions/1/1/main.py', $this->storage->path(1, 1, '  main.py  '));
        $this->assertSame('submissions/1/1/main.py', $this->storage->path(1, 1, '...main.py'));
    }

    public function test_a_long_filename_is_truncated_on_a_character_boundary(): void
    {
        $filename = str_repeat('á', 300).'.py';

        $path = $this->storage->path(1, 1, $filename);
        $name = basename($path);

        $this->assertLessThanOrEqual(SubmissionStorageService::MAX_FILENAME_BYTES, strlen($name));
        $this->assertStringEndsWith('.py', $name);
        // submissions.file_path is varchar(500).
        $this->assertLessThanOrEqual(500, strlen($path));
        $this->assertSame($name, mb_convert_encoding($name, 'UTF-8', 'UTF-8'));
    }

    public function test_a_filename_with_nothing_usable_left_is_rejected(): void
    {
        $this->expectException(SubmissionFileException::class);

        $this->storage->path(1, 1, '../');
    }

    // ── Size (D-34) ──

    public function test_a_file_at_the_size_limit_is_accepted(): void
    {
        $stored = $this->storage->storeUpload(
            1, 1, $this->upload('main.py', str_repeat('a', SubmissionStorageService::MAX_FILE_BYTES)), $this->python()
        );

        $this->assertSame(SubmissionStorageService::MAX_FILE_BYTES, $stored->bytes);
    }

    public function test_a_file_over_the_size_limit_is_rejected(): void
    {
        $oversize = str_repeat('a', SubmissionStorageService::MAX_FILE_BYTES + 1);

        $this->assertRefusal(
            SubmissionFileException::FILE_TOO_LARGE,
            fn () => $this->storage->storeUpload(1, 1, $this->upload('main.py', $oversize), $this->python())
        );
        $this->assertRefusal(
            SubmissionFileException::FILE_TOO_LARGE,
            fn () => $this->storage->storeSource(1, 1, $oversize, $this->python())
        );
    }

    public function test_an_empty_file_is_rejected(): void
    {
        $this->assertRefusal(
            SubmissionFileException::EMPTY_FILE,
            fn () => $this->storage->storeUpload(1, 1, $this->upload('main.py', ''), $this->python())
        );
        $this->assertRefusal(
            SubmissionFileException::EMPTY_FILE,
            fn () => $this->storage->storeSource(1, 1, '', $this->python())
        );
    }

    // ── Extension allowlist (U-4) ──

    public function test_an_extension_outside_the_language_allowlist_is_rejected(): void
    {
        $this->assertRefusal(
            SubmissionFileException::EXTENSION_NOT_ALLOWED,
            fn () => $this->storage->storeUpload(1, 1, $this->upload('main.txt', 'print(1)'), $this->python())
        );
    }

    public function test_the_extension_check_ignores_case(): void
    {
        $stored = $this->storage->storeUpload(1, 1, $this->upload('MAIN.PY', 'print(1)'), $this->python());

        $this->assertSame('submissions/1/1/MAIN.PY', $stored->path);
    }

    public function test_every_extension_a_language_declares_is_accepted(): void
    {
        $cpp = $this->cpp();

        foreach (['cpp', 'cc', 'cxx'] as $extension) {
            $stored = $this->storage->storeUpload(1, 1, $this->upload("main.{$extension}", 'int main(){}'), $cpp);
            $this->assertSame("submissions/1/1/main.{$extension}", $stored->path);
        }
    }

    public function test_a_file_without_an_extension_is_rejected(): void
    {
        $this->assertRefusal(
            SubmissionFileException::EXTENSION_NOT_ALLOWED,
            fn () => $this->storage->storeUpload(1, 1, $this->upload('main', 'print(1)'), $this->python())
        );
    }

    // ── The editor path: JSON source with no filename ──

    public function test_source_without_a_filename_is_named_from_the_language(): void
    {
        $this->assertSame(
            'submissions/5/9/main.py',
            $this->storage->storeSource(5, 9, 'print(1)', $this->python())->path
        );
        $this->assertSame(
            'submissions/5/9/main.cpp',
            $this->storage->storeSource(5, 9, 'int main(){}', $this->cpp())->path
        );
    }

    public function test_both_entry_points_write_the_same_object(): void
    {
        $source = "print('hello')\n";

        $fromEditor = $this->storage->storeSource(1, 1, $source, $this->python());
        $fromUpload = $this->storage->storeUpload(1, 2, $this->upload('main.py', $source), $this->python());

        $this->assertSame(
            Storage::disk('minio')->get($fromEditor->path),
            Storage::disk('minio')->get($fromUpload->path)
        );
        $this->assertSame($fromEditor->name, $fromUpload->name);
        $this->assertSame($fromEditor->bytes, $fromUpload->bytes);
    }

    // ── Writing ──

    public function test_it_writes_the_object_and_describes_what_it_wrote(): void
    {
        $source = "print('hello')\n";

        $stored = $this->storage->storeUpload(7, 42, $this->upload('solution.py', $source), $this->python());

        $this->assertInstanceOf(StoredSubmissionFile::class, $stored);
        $this->assertSame('submissions/7/42/solution.py', $stored->path);
        $this->assertSame('solution.py', $stored->name);
        $this->assertSame(strlen($source), $stored->bytes);
        Storage::disk('minio')->assertExists($stored->path);
        $this->assertSame($source, Storage::disk('minio')->get($stored->path));
    }

    public function test_deleting_is_idempotent(): void
    {
        $stored = $this->storage->storeSource(1, 1, 'print(1)', $this->python());

        $this->storage->delete($stored->path);
        $this->storage->delete($stored->path);

        Storage::disk('minio')->assertMissing($stored->path);
    }

    // ── Pre-signed URLs (D-85) ──

    public function test_a_pre_signed_url_expires_after_the_configured_ttl(): void
    {
        $captured = $this->captureExpiration();

        $this->storage->temporaryUrl('submissions/1/1/main.py');

        $this->assertEqualsWithDelta(
            now()->addSeconds(21600)->timestamp,
            $captured()->timestamp,
            1
        );
    }

    public function test_the_ttl_can_be_overridden_per_call(): void
    {
        $captured = $this->captureExpiration();

        $this->storage->temporaryUrl('submissions/1/1/main.py', 60);

        $this->assertEqualsWithDelta(now()->addSeconds(60)->timestamp, $captured()->timestamp, 1);
    }

    // ── Helpers ──

    /**
     * The faked disk is a local one, whose temporaryUrl() throws. Intercepting
     * the callback is the only way to see the expiry the service asked for.
     *
     * @return callable(): \Illuminate\Support\Carbon
     */
    private function captureExpiration(): callable
    {
        $expiration = null;

        Storage::disk('minio')->buildTemporaryUrlsUsing(
            function (string $path, \DateTimeInterface $at) use (&$expiration): string {
                $expiration = \Illuminate\Support\Carbon::instance($at);

                return "https://minio.test/{$path}";
            }
        );

        return function () use (&$expiration) {
            $this->assertNotNull($expiration, 'temporaryUrl() never reached the disk');

            return $expiration;
        };
    }

    private function assertRefusal(string $reason, callable $attempt): void
    {
        try {
            $attempt();
        } catch (SubmissionFileException $e) {
            $this->assertSame($reason, $e->reason);

            return;
        }

        $this->fail("Expected a {$reason} refusal.");
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function python(): ProgrammingLanguage
    {
        return ProgrammingLanguage::factory()->make(['file_extensions' => ['py']]);
    }

    private function cpp(): ProgrammingLanguage
    {
        return ProgrammingLanguage::factory()->make([
            'name' => 'C++',
            'file_extensions' => ['cpp', 'cc', 'cxx'],
        ]);
    }
}
