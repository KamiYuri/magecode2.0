<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Models\ProgrammingLanguage;
use App\Services\StoredSubmissionFile;
use App\Services\SubmissionStorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RequiresMinio;
use Tests\TestCase;

/**
 * The half of C1 a fake disk cannot prove: that the credentials, the
 * path-style addressing and the bucket are right, and that a pre-signed URL is
 * fetchable with no credentials at all — the contract SIM/AID/VUL depend on
 * (D-80/D-85).
 *
 * Skipped unless MINIO_TEST_ENDPOINT is set; see the RequiresMinio trait.
 */
class MinioStorageTest extends TestCase
{
    use RequiresMinio;

    private SubmissionStorageService $storage;

    /** @var list<string> */
    private array $written = [];

    private int $submissionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireMinio();
        $this->storage = app(SubmissionStorageService::class);
        // Unique per run: the bucket outlives the suite.
        $this->submissionId = random_int(100_000, 999_999);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            Storage::disk('minio')->delete($path);
        }

        parent::tearDown();
    }

    public function test_an_uploaded_object_lands_in_the_bucket_intact(): void
    {
        $source = "print('xin chào')\n";

        $stored = $this->store($source);

        $this->assertTrue(
            Storage::disk('minio')->exists($stored->path),
            'Object missing from the bucket. If this is a NoSuchBucket error, the stack was '
            .'started before MINIO_BUCKET was set — minio-init only runs at `docker compose up`.'
        );
        $this->assertSame($source, Storage::disk('minio')->get($stored->path));
    }

    public function test_a_pre_signed_url_can_be_fetched_without_credentials(): void
    {
        $source = "print('presigned')\n";
        $stored = $this->store($source);

        $response = Http::withoutRedirecting()->get($this->storage->temporaryUrl($stored->path));

        $this->assertSame(200, $response->status());
        $this->assertSame($source, $response->body());
    }

    public function test_the_same_object_is_forbidden_without_a_signature(): void
    {
        $stored = $this->store("print('private')\n");

        $response = Http::get("{$this->minioEndpoint()}/{$this->minioBucket()}/{$stored->path}");

        $this->assertSame(403, $response->status(), 'The bucket must stay private (D-79c)');
    }

    public function test_an_expired_url_is_rejected(): void
    {
        $stored = $this->store("print('expired')\n");
        $url = $this->storage->temporaryUrl($stored->path, 1);

        sleep(2);

        $this->assertSame(403, Http::get($url)->status());
    }

    public function test_a_deleted_object_is_gone(): void
    {
        $stored = $this->store("print('deleted')\n");

        $this->storage->delete($stored->path);

        $this->assertFalse(Storage::disk('minio')->exists($stored->path));
    }

    private function store(string $source): StoredSubmissionFile
    {
        $language = ProgrammingLanguage::factory()->make(['file_extensions' => ['py']]);

        $stored = $this->storage->storeSource(1, $this->submissionId, $source, $language);
        $this->written[] = $stored->path;

        return $stored;
    }
}
