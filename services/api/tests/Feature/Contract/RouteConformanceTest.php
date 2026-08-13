<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Http\Requests\Problem\ListProblemsRequest;
use App\Http\Requests\Problem\ShowProblemRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The route table against openapi.yml, at (method, path) level.
 *
 * Two directions, and they are not symmetric while Plan B–F are in flight:
 *
 * - **No undeclared route.** Anything reachable under `/api/v1` must be in the
 *   contract. This is enforced absolutely — an endpoint clients cannot know
 *   about is either an accident or a leak.
 * - **No missing operation.** Every declared operation must be routed, except
 *   the ones on PENDING, each tagged with the task that will add it. The list
 *   is checked for staleness too: implementing an operation without striking
 *   it fails, so the list can only shrink.
 *
 * Placeholder *names* are normalised away: routes use `{organization}` for
 * implicit binding, the contract writes `{organization_id}`. Only the shape
 * is compared.
 */
class RouteConformanceTest extends TestCase
{
    private const SPEC_RELATIVE_PATH = 'docs/api-contracts/openapi.yml';

    /**
     * Guards the parser below: openapi.yml is read with a narrow reader rather
     * than a YAML library, so a formatting change that made it silently see
     * fewer operations would otherwise weaken every assertion in this file.
     */
    private const EXPECTED_PATHS = 61;

    private const EXPECTED_OPERATIONS = 88;

    /**
     * Declared but not yet routed, by the task that owns them. Strike an entry
     * when its route lands — test_the_pending_list_has_no_stale_entries fails
     * if you forget.
     *
     * @var array<string, array<int, string>>
     */
    private const PENDING = [
        'B7 section roster' => [
            'POST /sections/{}/members/import',
            'POST /sections/{}/members/{}/transfer',
        ],
        'B9 tags' => [
            'GET /courses/{}/tags',
            'POST /courses/{}/tags',
            'PUT /tags/{}',
            'DELETE /tags/{}',
        ],
        'B10 problem bank' => [
            'GET /courses/{}/bank',
            'POST /courses/{}/bank',
            'POST /courses/{}/bank/publish',
            'GET /bank-problems/{}',
            'PUT /bank-problems/{}',
            'DELETE /bank-problems/{}',
            'PATCH /bank-problems/{}/approve',
            'GET /bank-problems/{}/versions',
            'POST /sections/{}/problems/clone',
        ],
        'B12 profile and notifications' => [
            'GET /profile',
            'PUT /profile',
            'PUT /profile/password',
            'POST /profile/avatar',
            'DELETE /profile/avatar',
            'GET /notifications',
            'POST /notifications/mark-read',
            'POST /organizations/{}/avatar',
            'DELETE /organizations/{}/avatar',
        ],
        'C2 submissions' => [
            'GET /problems/{}/submissions',
            'POST /problems/{}/submissions',
            'POST /problems/{}/submissions/upload',
            'GET /submissions/{}',
            'GET /sections/{}/submissions',
        ],
        'D1-D8 analysis' => [
            'POST /problems/{}/analysis',
            'GET /problems/{}/analysis',
            'GET /problems/{}/analysis/history',
            'POST /analysis/{}/cancel',
            'GET /analysis/{}/submissions',
            'GET /analysis/{}/similarity',
            'GET /analysis/{}/similarity/{}',
            'GET /analysis/{}/ai-detection',
            'GET /analysis/{}/vulnerabilities',
            'GET /semesters/{}/analysis-overview',
            'GET /semesters/{}/match-groups',
            'POST /semesters/{}/match-groups',
        ],
        'F4 dashboards and reference reads' => [
            'GET /me/sections',
            'GET /programming-languages',
        ],
        'G-phase export' => [
            'GET /sections/{}/export/grades',
            'GET /problems/{}/export/submissions',
        ],
    ];

    public function test_the_spec_parses_to_the_documented_size(): void
    {
        $operations = $this->specOperations();
        $paths = array_unique(array_map(
            fn (string $operation): string => Str::after($operation, ' '),
            $operations
        ));

        $this->assertCount(self::EXPECTED_OPERATIONS, $operations);
        $this->assertCount(self::EXPECTED_PATHS, $paths);
    }

    public function test_every_registered_route_is_declared_in_the_contract(): void
    {
        $undeclared = array_diff($this->registeredOperations(), $this->specOperations());

        $this->assertSame([], array_values($undeclared), sprintf(
            "These routes are not in openapi.yml:\n  %s",
            implode("\n  ", $undeclared)
        ));
    }

    public function test_every_declared_operation_is_routed_or_pending(): void
    {
        $missing = array_diff($this->specOperations(), $this->registeredOperations(), $this->pendingOperations());

        $this->assertSame([], array_values($missing), sprintf(
            "These operations are declared but neither routed nor listed as pending:\n  %s",
            implode("\n  ", $missing)
        ));
    }

    public function test_the_pending_list_has_no_stale_entries(): void
    {
        $done = array_intersect($this->pendingOperations(), $this->registeredOperations());

        $this->assertSame([], array_values($done), sprintf(
            "These operations are routed and can be struck from PENDING:\n  %s",
            implode("\n  ", $done)
        ));
    }

    public function test_the_pending_list_only_names_declared_operations(): void
    {
        $unknown = array_diff($this->pendingOperations(), $this->specOperations());

        $this->assertSame([], array_values($unknown), sprintf(
            "PENDING names operations that openapi.yml does not declare:\n  %s",
            implode("\n  ", $unknown)
        ));
    }

    /**
     * `?include=` is enumerated per operation rather than shared (D-91), so
     * the allowlist in each request class is a copy of the contract's enum and
     * has to be checked against it. Only three operations declare one; the
     * third belongs to D8 and has no request class yet.
     */
    public function test_the_include_allowlists_match_the_contract(): void
    {
        $declared = $this->specIncludeEnums();

        $this->assertCount(3, $declared, 'The number of operations declaring `include` changed.');
        $this->assertContains(ListProblemsRequest::INCLUDABLE, $declared);
        $this->assertContains(ShowProblemRequest::INCLUDABLE, $declared);
    }

    public function test_every_api_route_lives_under_the_version_prefix(): void
    {
        $unversioned = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->filter(fn (string $uri): bool => Str::startsWith($uri, 'api/'))
            ->reject(fn (string $uri): bool => Str::startsWith($uri, 'api/v1/'))
            // The probe alias is deliberate: the compose healthcheck and
            // Traefik hit /api/health, and it must survive a version bump.
            ->reject(fn (string $uri): bool => $uri === 'api/health')
            ->values()
            ->all();

        $this->assertSame([], $unversioned);
    }

    /** @return array<int, string> */
    private function pendingOperations(): array
    {
        return array_merge(...array_values(self::PENDING));
    }

    /**
     * Registered `/api/v1` routes as "METHOD /path", placeholders blanked.
     *
     * @return array<int, string>
     */
    private function registeredOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! Str::startsWith($uri, 'api/v1/')) {
                continue;
            }

            $path = $this->normalise(Str::after($uri, 'api/v1'));

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $operations[] = "{$method} {$path}";
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }

    /**
     * Operations declared in openapi.yml, same shape.
     *
     * Read with a narrow reader rather than a YAML parser: the api image
     * carries no YAML extension, and pulling a dependency in to read one
     * predictable block would be a heavier commitment than the block deserves.
     * test_the_spec_parses_to_the_documented_size is the tripwire.
     *
     * @return array<int, string>
     */
    private function specOperations(): array
    {
        $spec = $this->specPath();
        $lines = file($spec, FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines, "openapi.yml is unreadable at {$spec}");

        $operations = [];
        $inPaths = false;
        $path = null;

        foreach ($lines as $line) {
            if ($line === 'paths:') {
                $inPaths = true;

                continue;
            }

            if (! $inPaths) {
                continue;
            }

            // A non-indented, non-comment line ends the paths block.
            if ($line !== '' && ! Str::startsWith($line, [' ', '#'])) {
                break;
            }

            if (preg_match('/^  (\/\S*):\s*$/', $line, $matches) === 1) {
                $path = $this->normalise($matches[1]);

                continue;
            }

            if ($path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $matches) === 1) {
                $operations[] = strtoupper($matches[1])." {$path}";
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }

    /**
     * The enum of every inline `include` parameter, in declaration order. The
     * deprecated shared `QueryInclude` component carries no enum and so does
     * not appear.
     *
     * @return array<int, array<int, string>>
     */
    private function specIncludeEnums(): array
    {
        $lines = file($this->specPath(), FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines);

        $enums = [];
        $pending = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*-\s*name: include\s*$/', $line) === 1) {
                $pending = true;

                continue;
            }

            if ($pending && preg_match('/enum:\s*\[([^\]]+)\]/', $line, $matches) === 1) {
                $enums[] = array_map('trim', explode(',', $matches[1]));
                $pending = false;
            }
        }

        return $enums;
    }

    /**
     * The contract lives in the monorepo, not in this service, so it is found
     * by walking up from the app root — which works both on the host and in
     * the test image, where the Makefile mounts it one level above.
     */
    private function specPath(): string
    {
        $directory = base_path();

        for ($depth = 0; $depth < 5; $depth++) {
            $candidate = $directory.'/'.self::SPEC_RELATIVE_PATH;

            if (is_file($candidate)) {
                return $candidate;
            }

            $directory = dirname($directory);
        }

        $this->fail('Could not find '.self::SPEC_RELATIVE_PATH.' above '.base_path());
    }

    /** `{organization_id}` and `{organization}` are the same shape. */
    private function normalise(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $path);
    }
}
