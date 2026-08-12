<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_versioned_health_endpoint_reports_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.service', 'api')
            ->assertJsonStructure(['data' => ['status', 'service', 'timestamp']]);
    }

    public function test_unversioned_health_endpoint_is_available_for_container_probes(): void
    {
        // docker-compose healthcheck probes /api/health, not /api/v1/health.
        $this->getJson('/api/health')->assertOk()->assertJsonPath('data.status', 'ok');
    }

    public function test_health_endpoint_requires_no_authentication(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
    }

    public function test_database_connectivity_is_reported(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.database', 'ok');
    }
}
