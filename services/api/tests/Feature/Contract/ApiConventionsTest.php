<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * The conventions openapi.yml states once and every endpoint then owes:
 * the `{data}` / `{data, meta}` envelope, the two error shapes, and the
 * rate-limit table. Endpoint suites assert their own payloads; this one
 * asserts the frame around them.
 */
class ApiConventionsTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('api');
    }

    public function test_a_single_resource_is_wrapped_in_data(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $body = $this->getJson("/api/v1/organizations/{$organization->id}")->assertOk()->json();

        $this->assertSame(['data'], array_keys($body));
    }

    public function test_a_paginated_list_carries_data_and_meta_only(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $body = $this->getJson('/api/v1/organizations')->assertOk()->json();

        $this->assertSame(['data', 'meta'], array_keys($body));
        $this->assertSame(
            ['next_cursor', 'prev_cursor', 'per_page', 'has_more'],
            array_keys($body['meta'])
        );
    }

    public function test_a_small_collection_carries_no_meta(): void
    {
        // Sections, problems and test cases are bounded sets; the contract
        // returns them whole rather than paginating a handful of rows.
        $semester = $this->semesterIn($this->courseIn($organization = $this->organizationWithAdmin()));
        $this->sectionIn($semester);
        Sanctum::actingAs($organization->creator);

        $body = $this->getJson("/api/v1/semesters/{$semester->id}/sections")->assertOk()->json();

        $this->assertSame(['data'], array_keys($body));
    }

    public function test_an_unauthenticated_request_reports_the_error_shape(): void
    {
        $body = $this->getJson('/api/v1/organizations')->assertUnauthorized()->json();

        $this->assertArrayHasKey('message', $body);
        $this->assertArrayNotHasKey('errors', $body);
    }

    public function test_a_denied_request_reports_the_error_shape(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs(User::factory()->create());

        $body = $this->getJson("/api/v1/organizations/{$organization->id}")->assertForbidden()->json();

        $this->assertArrayHasKey('message', $body);
    }

    public function test_a_missing_resource_reports_the_error_shape(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $body = $this->getJson('/api/v1/organizations/404404')->assertNotFound()->json();

        $this->assertArrayHasKey('message', $body);
    }

    public function test_a_validation_failure_reports_message_and_errors(): void
    {
        Sanctum::actingAs(User::factory()->systemAdmin()->create());

        $body = $this->postJson('/api/v1/organizations', [])->assertUnprocessable()->json();

        $this->assertSame(['message', 'errors'], array_keys($body));
        $this->assertIsArray($body['errors']['name']);
    }

    public function test_a_business_conflict_reports_message_and_code(): void
    {
        $organization = $this->organizationWithAdmin();
        $this->courseIn($organization, ['code' => 'IT3080']);
        Sanctum::actingAs($organization->creator);

        $body = $this->postJson("/api/v1/organizations/{$organization->id}/courses", [
            'code' => 'IT3080',
            'name' => 'Computer Networks',
        ])->assertConflict()->json();

        $this->assertSame(['message', 'code'], array_keys($body));
        $this->assertSame('DUPLICATE_COURSE_CODE', $body['code']);
    }

    public function test_the_authenticated_routes_carry_the_global_rate_limit(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $response = $this->getJson('/api/v1/organizations')->assertOk();

        $this->assertSame('120', $response->headers->get('X-RateLimit-Limit'));
    }

    public function test_the_health_probe_is_reachable_without_a_token(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.service', 'api');
        $this->getJson('/api/health')->assertOk();
    }

    /**
     * The submission, analysis and upload limiters are defined before their
     * endpoints exist so C2, D1, B7 and B12 attach a name rather than invent
     * a number (rate-limit table in openapi.yml, U-3).
     */
    public function test_the_documented_rate_limiters_are_registered(): void
    {
        $expected = ['auth' => 10, 'api' => 120, 'submissions' => 10, 'analysis' => 5, 'uploads' => 10];

        foreach ($expected as $name => $perMinute) {
            $limiter = RateLimiter::limiter($name);
            $this->assertNotNull($limiter, "The `{$name}` rate limiter is not registered.");

            $limit = $limiter($this->requestFrom(User::factory()->create()));
            $this->assertInstanceOf(Limit::class, $limit);
            $this->assertSame($perMinute, $limit->maxAttempts, "`{$name}` should allow {$perMinute} per minute.");
        }
    }

    public function test_the_per_user_limiters_key_on_the_caller(): void
    {
        // Two students share an IP in a lab; one hitting the submission limit
        // must not lock out the other (U-3).
        $limiter = RateLimiter::limiter('submissions');
        $this->assertNotNull($limiter);

        $first = $limiter($this->requestFrom(User::factory()->create()));
        $second = $limiter($this->requestFrom(User::factory()->create()));

        $this->assertInstanceOf(Limit::class, $first);
        $this->assertInstanceOf(Limit::class, $second);
        $this->assertNotSame($first->key, $second->key);
    }

    private function requestFrom(User $user): Request
    {
        $request = Request::create('/api/v1/health');
        $request->setUserResolver(fn (): User => $user);

        return $request;
    }
}
