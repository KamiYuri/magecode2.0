<?php

declare(strict_types=1);

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

/*
| All endpoints live under /api/v1 (U-3). The route table is regenerated
| from docs/api-contracts/openapi.yml as Plan B tasks land; B11 asserts the
| two stay in sync.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');

    Route::prefix('auth')->group(function (): void {
        // Credential endpoints are the ones worth brute-forcing, so they get
        // the tighter per-IP limit from openapi.yml's rate-limit table.
        Route::middleware('throttle:auth')->group(function (): void {
            Route::post('login', [AuthController::class, 'login'])->name('auth.login');
            Route::post('register', [AuthController::class, 'register'])->name('auth.register');
            Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.email');
            Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.store');
        });

        Route::get('email/verify/{id}/{hash}', EmailVerificationController::class)
            ->middleware('signed')
            ->name('verification.verify');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('first-time-setup', [AuthController::class, 'firstTimeSetup'])->name('auth.first-time-setup');
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        // Route parameters are named for implicit binding ({organization}),
        // while openapi.yml spells them {organization_id} — B11's conformance
        // test compares paths with the placeholder names normalised away.
        Route::get('admin/organizations', Admin\OrganizationController::class)->name('admin.organizations.index');

        Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::put('organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
    });
});

// Unversioned alias for container probes: the compose healthcheck and
// Traefik both hit /api/health.
Route::get('health', HealthController::class)->name('health.probe');
