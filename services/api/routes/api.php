<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
| All endpoints live under /api/v1 (U-3). The route table is regenerated
| from docs/api-contracts/openapi.yml as Plan B tasks land; B11 asserts the
| two stay in sync.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');
});

// Unversioned alias for container probes: the compose healthcheck and
// Traefik both hit /api/health.
Route::get('health', HealthController::class)->name('health.probe');
