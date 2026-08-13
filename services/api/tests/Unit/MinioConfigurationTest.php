<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The disk is only ever built lazily, so a missing region or the wrong
 * addressing style surfaces as a runtime failure on the first upload rather
 * than at boot. These assertions are the early warning.
 */
class MinioConfigurationTest extends TestCase
{
    public function test_the_minio_disk_is_a_path_style_s3_disk(): void
    {
        $disk = config('filesystems.disks.minio');

        $this->assertIsArray($disk);
        $this->assertSame('s3', $disk['driver']);
        $this->assertTrue($disk['use_path_style_endpoint']);
        $this->assertNotEmpty($disk['region']);
        $this->assertNotEmpty($disk['endpoint']);
        $this->assertNotEmpty($disk['bucket']);
        $this->assertTrue($disk['throw'], 'A failed write must raise, not return false');
    }

    public function test_the_pre_signed_url_ttl_is_six_hours(): void
    {
        // D-85 as amended 2026-08-10 (decisions-v3 §7): 2h → 6h.
        $this->assertSame(21600, config('minio.presigned_url_ttl'));
    }
}
