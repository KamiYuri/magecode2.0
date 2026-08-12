<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    private const SERVICE_NAME = 'api';

    private const STATUS_OK = 'ok';

    private const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * Readiness probe: reports 503 when a dependency the API cannot serve
     * without is unreachable, so Traefik and the compose healthcheck stop
     * routing traffic here.
     */
    public function __invoke(): JsonResponse
    {
        $databaseStatus = $this->databaseStatus();
        $healthy = $databaseStatus === self::STATUS_OK;

        return response()->json([
            'data' => [
                'status' => $healthy ? self::STATUS_OK : self::STATUS_UNAVAILABLE,
                'service' => self::SERVICE_NAME,
                'database' => $databaseStatus,
                'timestamp' => now()->utc()->toIso8601String(),
            ],
        ], $healthy ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE);
    }

    private function databaseStatus(): string
    {
        try {
            DB::connection()->select('SELECT 1');

            return self::STATUS_OK;
        } catch (Throwable) {
            return self::STATUS_UNAVAILABLE;
        }
    }
}
