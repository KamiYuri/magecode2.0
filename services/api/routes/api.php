<?php

declare(strict_types=1);

use App\Http\Controllers\Admin;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMemberController;
use App\Http\Controllers\ProblemController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SectionMemberController;
use App\Http\Controllers\SemesterController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\TestCaseController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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

    // Every authenticated endpoint carries the global limit from openapi.yml's
    // rate-limit table; the tighter per-operation limiters (submissions,
    // analysis, uploads) are attached by the tasks that add those routes.
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        // Channel authorisation for Reverb (U-8). Declared here rather than by
        // Broadcast::routes(), which answers GET as well — Pusher clients POST,
        // and an undeclared GET is a route the contract does not know about.
        Route::post('broadcasting/auth', [BroadcastController::class, 'authenticate'])
            ->withoutMiddleware([PreventRequestForgery::class])
            ->name('broadcasting.auth');

        // Route parameters are named for implicit binding ({organization}),
        // while openapi.yml spells them {organization_id} — B11's conformance
        // test compares paths with the placeholder names normalised away.
        Route::get('admin/organizations', Admin\OrganizationController::class)->name('admin.organizations.index');

        Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::put('organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');

        // Scoped bindings resolve {member} through the organization, so a
        // membership id from elsewhere is a 404 rather than a cross-org leak.
        Route::scopeBindings()->group(function (): void {
            Route::get('organizations/{organization}/members', [OrganizationMemberController::class, 'index'])
                ->name('organizations.members.index');
            Route::post('organizations/{organization}/members', [OrganizationMemberController::class, 'store'])
                ->name('organizations.members.store');
            Route::put('organizations/{organization}/members/{member}', [OrganizationMemberController::class, 'update'])
                ->name('organizations.members.update');
            Route::delete('organizations/{organization}/members/{member}', [OrganizationMemberController::class, 'destroy'])
                ->name('organizations.members.destroy');
        });

        Route::get('organizations/{organization}/courses', [CourseController::class, 'index'])
            ->name('organizations.courses.index');
        Route::post('organizations/{organization}/courses', [CourseController::class, 'store'])
            ->name('organizations.courses.store');
        Route::get('courses/{course}', [CourseController::class, 'show'])->name('courses.show');
        Route::put('courses/{course}', [CourseController::class, 'update'])->name('courses.update');

        Route::get('courses/{course}/semesters', [SemesterController::class, 'index'])->name('courses.semesters.index');
        Route::post('courses/{course}/semesters', [SemesterController::class, 'store'])->name('courses.semesters.store');
        Route::get('semesters/{semester}', [SemesterController::class, 'show'])->name('semesters.show');
        Route::put('semesters/{semester}', [SemesterController::class, 'update'])->name('semesters.update');

        Route::get('semesters/{semester}/sections', [SectionController::class, 'index'])
            ->name('semesters.sections.index');
        Route::post('semesters/{semester}/sections', [SectionController::class, 'store'])
            ->name('semesters.sections.store');
        Route::get('sections/{section}', [SectionController::class, 'show'])->name('sections.show');
        Route::put('sections/{section}', [SectionController::class, 'update'])->name('sections.update');

        Route::scopeBindings()->group(function (): void {
            Route::get('sections/{section}/members', [SectionMemberController::class, 'index'])
                ->name('sections.members.index');
            Route::post('sections/{section}/members', [SectionMemberController::class, 'store'])
                ->name('sections.members.store');
            Route::post('sections/{section}/members/import', [SectionMemberController::class, 'import'])
                ->middleware('throttle:uploads')
                ->name('sections.members.import');
            Route::post('sections/{section}/members/{member}/transfer', [SectionMemberController::class, 'transfer'])
                ->name('sections.members.transfer');
            Route::put('sections/{section}/members/{member}', [SectionMemberController::class, 'update'])
                ->name('sections.members.update');
            Route::delete('sections/{section}/members/{member}', [SectionMemberController::class, 'destroy'])
                ->name('sections.members.destroy');
        });

        Route::get('sections/{section}/problems', [ProblemController::class, 'index'])
            ->name('sections.problems.index');
        Route::post('sections/{section}/problems', [ProblemController::class, 'store'])
            ->name('sections.problems.store');
        Route::put('sections/{section}/problems/reorder', [ProblemController::class, 'reorder'])
            ->name('sections.problems.reorder');
        Route::get('problems/{problem}', [ProblemController::class, 'show'])->name('problems.show');
        Route::patch('problems/{problem}/publish', [ProblemController::class, 'publish'])
            ->name('problems.publish');
        Route::patch('problems/{problem}/lock', [ProblemController::class, 'lock'])->name('problems.lock');
        Route::put('problems/{problem}', [ProblemController::class, 'update'])->name('problems.update');
        Route::delete('problems/{problem}', [ProblemController::class, 'destroy'])->name('problems.destroy');

        Route::get('problems/{problem}/test-cases', [TestCaseController::class, 'index'])
            ->name('problems.test-cases.index');
        Route::put('problems/{problem}/test-cases', [TestCaseController::class, 'update'])
            ->name('problems.test-cases.update');

        // Writes carry the tighter `submissions` limiter (U-3); the reads stay
        // on the global one, since a student refreshing a verdict is not the
        // traffic that limit exists for.
        Route::get('problems/{problem}/submissions', [SubmissionController::class, 'index'])
            ->name('problems.submissions.index');
        Route::post('problems/{problem}/submissions', [SubmissionController::class, 'store'])
            ->middleware('throttle:submissions')
            ->name('problems.submissions.store');
        Route::post('problems/{problem}/submissions/upload', [SubmissionController::class, 'upload'])
            ->middleware('throttle:submissions')
            ->name('problems.submissions.upload');
        Route::get('sections/{section}/submissions', [SubmissionController::class, 'section'])
            ->name('sections.submissions.index');
        Route::get('submissions/{submission}', [SubmissionController::class, 'show'])
            ->name('submissions.show');

        // Analysis costs real compute, so it takes the tighter limiter (U-3).
        Route::post('problems/{problem}/analysis', [AnalysisController::class, 'store'])
            ->middleware('throttle:analysis')
            ->name('problems.analysis.store');
        Route::get('problems/{problem}/analysis', [AnalysisController::class, 'latest'])
            ->name('problems.analysis.latest');
        Route::get('problems/{problem}/analysis/history', [AnalysisController::class, 'history'])
            ->name('problems.analysis.history');
        Route::post('analysis/{analysisProblem}/cancel', [AnalysisController::class, 'cancel'])
            ->name('analysis.cancel');
        Route::get('semesters/{semester}/match-groups', [AnalysisController::class, 'matchGroups'])
            ->name('semesters.match-groups.index');
        Route::post('semesters/{semester}/match-groups', [AnalysisController::class, 'storeMatchGroup'])
            ->name('semesters.match-groups.store');
    });
});

// Unversioned alias for container probes: the compose healthcheck and
// Traefik both hit /api/health.
Route::get('health', HealthController::class)->name('health.probe');
