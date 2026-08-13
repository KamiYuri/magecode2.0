<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Gates the tests that talk to a real MinIO, mirroring the Go side's
 * `-tags integration` + `MINIO_TEST_*` convention (`shared/go/storage`).
 *
 * The variables are deliberately named apart from the runtime `MINIO_*` ones:
 * PHPUnit's `<env>` never overrides a real process variable, so a developer's
 * `MINIO_ENDPOINT=http://127.0.0.1:9000` would otherwise leak into a suite that
 * runs inside the compose network, where the host loopback is not MinIO. Read
 * through `getenv()` for the same reason — this gate is about the process
 * environment, not about application config.
 */
trait RequiresMinio
{
    protected function requireMinio(): void
    {
        $endpoint = $this->minioEndpoint();

        if ($endpoint === '') {
            $this->markTestSkipped(
                'MINIO_TEST_ENDPOINT is unset. Bring the stack up and run: '
                .'set -a && . ./.env && set +a && make test-api'
            );
        }

        config([
            'filesystems.disks.minio' => [
                'driver' => 's3',
                'key' => $this->minioEnv('MINIO_TEST_ACCESS_KEY'),
                'secret' => $this->minioEnv('MINIO_TEST_SECRET_KEY'),
                'region' => 'us-east-1',
                'bucket' => $this->minioBucket(),
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'throw' => true,
                'report' => false,
            ],
        ]);

        // The manager caches the adapter it already built from the old config.
        Storage::forgetDisk('minio');
    }

    protected function minioEndpoint(): string
    {
        return rtrim($this->minioEnv('MINIO_TEST_ENDPOINT'), '/');
    }

    protected function minioBucket(): string
    {
        return $this->minioEnv('MINIO_TEST_BUCKET') ?: 'magecode';
    }

    private function minioEnv(string $name): string
    {
        $value = getenv($name);

        return $value === false ? '' : $value;
    }
}
