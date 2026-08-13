<?php

declare(strict_types=1);

namespace Tests\Feature\Semesters;

use App\Enums\PublishMode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * Semesters carry the publish/lock policy (D-16) and the analysis thresholds
 * (D-62), so this suite watches the policy fields as closely as the CRUD.
 */
class SemesterCrudTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    public function test_listing_returns_the_semesters_of_the_course(): void
    {
        $course = $this->courseIn($organization = $this->organizationWithAdmin());
        $this->semesterIn($course);
        $this->semesterIn($course);
        $this->semesterIn($this->courseIn($organization));
        Sanctum::actingAs($organization->creator);

        $response = $this->getJson("/api/v1/courses/{$course->id}/semesters")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'course_id', 'name', 'description', 'publish_mode', 'lock_mode',
                    'allow_publish_override', 'allow_lock_override', 'similarity_threshold',
                    'ai_detection_threshold', 'start_date', 'end_date', 'creator', 'sections_count',
                    'created_at', 'updated_at']],
                'meta' => ['next_cursor', 'prev_cursor', 'per_page', 'has_more'],
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_listing_is_denied_to_an_outsider(): void
    {
        $course = $this->courseIn($this->organizationWithAdmin());
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/courses/{$course->id}/semesters")->assertForbidden();
    }

    public function test_thresholds_are_serialised_as_numbers(): void
    {
        // The model casts them as decimal:2, which returns a string; the
        // contract types them as `number`.
        $course = $this->courseIn($organization = $this->organizationWithAdmin());
        $this->semesterIn($course, ['similarity_threshold' => 0.7, 'ai_detection_threshold' => 0.8]);
        Sanctum::actingAs($organization->creator);

        $response = $this->getJson("/api/v1/courses/{$course->id}/semesters")->assertOk();

        $this->assertSame(0.7, $response->json('data.0.similarity_threshold'));
        $this->assertSame(0.8, $response->json('data.0.ai_detection_threshold'));
    }

    public function test_an_org_admin_creates_a_semester_with_the_policy_fields(): void
    {
        $course = $this->courseIn($organization = $this->organizationWithAdmin());
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/courses/{$course->id}/semesters", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', '20252')
            ->assertJsonPath('data.publish_mode', PublishMode::Manual->value)
            ->assertJsonPath('data.lock_mode', PublishMode::Manual->value)
            ->assertJsonPath('data.allow_publish_override', true)
            ->assertJsonPath('data.allow_lock_override', false)
            ->assertJsonPath('data.start_date', '2026-09-01')
            ->assertJsonPath('data.end_date', '2027-01-15')
            ->assertJsonPath('data.sections_count', 0);

        $this->assertDatabaseHas('semesters', ['course_id' => $course->id, 'name' => '20252']);
    }

    public function test_creation_applies_the_documented_defaults(): void
    {
        $course = $this->courseIn($organization = $this->organizationWithAdmin());
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/courses/{$course->id}/semesters", ['name' => '20251'])
            ->assertCreated()
            ->assertJsonPath('data.publish_mode', PublishMode::Auto->value)
            ->assertJsonPath('data.lock_mode', PublishMode::Auto->value)
            ->assertJsonPath('data.allow_publish_override', true)
            ->assertJsonPath('data.allow_lock_override', true)
            ->assertJsonPath('data.similarity_threshold', 0.7)
            ->assertJsonPath('data.ai_detection_threshold', 0.8)
            ->assertJsonPath('data.start_date', null);
    }

    public function test_a_duplicate_name_in_the_same_course_is_a_conflict(): void
    {
        $course = $this->courseIn($organization = $this->organizationWithAdmin());
        $this->semesterIn($course, ['name' => '20252']);
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/courses/{$course->id}/semesters", ['name' => '20252'])
            ->assertConflict()
            ->assertJsonPath('code', 'DUPLICATE_SEMESTER_NAME');
    }

    public function test_thresholds_outside_zero_to_one_are_rejected(): void
    {
        $course = $this->courseIn($organization = $this->organizationWithAdmin());
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/courses/{$course->id}/semesters", [
            'name' => '20252',
            'similarity_threshold' => 1.5,
            'ai_detection_threshold' => -0.1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['similarity_threshold', 'ai_detection_threshold']);
    }

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $course = $this->courseIn($organization = $this->organizationWithAdmin());
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/courses/{$course->id}/semesters", [
            'name' => '20252',
            'start_date' => '2026-09-01',
            'end_date' => '2026-08-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_a_partial_update_compares_the_end_date_against_the_stored_start_date(): void
    {
        // The payload carries no start_date, so the rule has to reach for the
        // persisted one rather than pass by default.
        $course = $this->courseIn($organization = $this->organizationWithAdmin());
        $semester = $this->semesterIn($course, ['start_date' => '2026-09-01', 'end_date' => '2027-01-15']);
        Sanctum::actingAs($organization->creator);

        $this->putJson("/api/v1/semesters/{$semester->id}", ['end_date' => '2026-08-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);

        $this->putJson("/api/v1/semesters/{$semester->id}", ['end_date' => '2027-02-01'])->assertOk();
    }

    public function test_creation_is_denied_to_an_instructor(): void
    {
        $course = $this->courseIn($organization = $this->organizationWithAdmin());
        Sanctum::actingAs($this->organizationMember($organization));

        $this->postJson("/api/v1/courses/{$course->id}/semesters", ['name' => '20252'])->assertForbidden();
    }

    public function test_a_member_reads_a_semester_and_an_outsider_does_not(): void
    {
        $semester = $this->semesterIn($this->courseIn($organization = $this->organizationWithAdmin()));

        Sanctum::actingAs($this->organizationMember($organization));
        $this->getJson("/api/v1/semesters/{$semester->id}")->assertOk()->assertJsonPath('data.id', $semester->id);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/semesters/{$semester->id}")->assertForbidden();
    }

    public function test_an_org_admin_updates_the_policy_fields(): void
    {
        $semester = $this->semesterIn($this->courseIn($organization = $this->organizationWithAdmin()));
        Sanctum::actingAs($organization->creator);

        $this->putJson("/api/v1/semesters/{$semester->id}", [
            'lock_mode' => 'manual',
            'allow_lock_override' => false,
            'similarity_threshold' => 0.55,
        ])
            ->assertOk()
            ->assertJsonPath('data.lock_mode', PublishMode::Manual->value)
            ->assertJsonPath('data.allow_lock_override', false)
            ->assertJsonPath('data.similarity_threshold', 0.55);
    }

    public function test_an_instructor_may_not_update_a_semester(): void
    {
        $semester = $this->semesterIn($this->courseIn($organization = $this->organizationWithAdmin()));
        Sanctum::actingAs($this->organizationMember($organization));

        $this->putJson("/api/v1/semesters/{$semester->id}", ['name' => '20251'])->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => '20252',
            'description' => 'Học kỳ 2 năm học 2025-2026',
            'publish_mode' => 'manual',
            'lock_mode' => 'manual',
            'allow_publish_override' => true,
            'allow_lock_override' => false,
            'start_date' => '2026-09-01',
            'end_date' => '2027-01-15',
        ], $overrides);
    }
}
