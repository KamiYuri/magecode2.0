<?php

declare(strict_types=1);

namespace Tests\Feature\Courses;

use App\Enums\SectionRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

class CourseCrudTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    public function test_org_staff_list_every_course_of_the_organization(): void
    {
        $organization = $this->organizationWithAdmin();
        $this->courseIn($organization);
        $this->courseIn($organization);
        $this->courseIn($this->organizationWithAdmin());
        Sanctum::actingAs($this->organizationMember($organization));

        $response = $this->getJson("/api/v1/organizations/{$organization->id}/courses")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'organization_id', 'code', 'name', 'description',
                    'require_bank_approval', 'creator', 'semesters_count', 'created_at', 'updated_at']],
                'meta' => ['next_cursor', 'prev_cursor', 'per_page', 'has_more'],
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_a_student_lists_only_courses_they_are_enrolled_in(): void
    {
        $organization = $this->organizationWithAdmin();
        $enrolled = $this->courseIn($organization);
        $this->courseIn($organization);
        $student = $this->sectionMember($this->sectionIn($this->semesterIn($enrolled)), SectionRole::Student);
        Sanctum::actingAs($student);

        $response = $this->getJson("/api/v1/organizations/{$organization->id}/courses")->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($enrolled->id, $response->json('data.0.id'));
    }

    public function test_listing_filters_by_search(): void
    {
        $organization = $this->organizationWithAdmin();
        $this->courseIn($organization, ['code' => 'IT3080', 'name' => 'Computer Networks']);
        $this->courseIn($organization, ['code' => 'IT4409', 'name' => 'Web Technologies']);
        Sanctum::actingAs($organization->creator);

        $byCode = $this->getJson("/api/v1/organizations/{$organization->id}/courses?search=IT3080")->assertOk();
        $this->assertCount(1, $byCode->json('data'));

        $byName = $this->getJson("/api/v1/organizations/{$organization->id}/courses?search=web")->assertOk();
        $this->assertCount(1, $byName->json('data'));
        $this->assertSame('IT4409', $byName->json('data.0.code'));
    }

    public function test_listing_is_denied_to_an_outsider(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/organizations/{$organization->id}/courses")->assertForbidden();
    }

    public function test_an_org_admin_creates_a_course(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/organizations/{$organization->id}/courses", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'IT3080')
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.require_bank_approval', true)
            ->assertJsonPath('data.semesters_count', 0)
            ->assertJsonPath('data.creator.id', $organization->creator_id);

        $this->assertDatabaseHas('courses', ['organization_id' => $organization->id, 'code' => 'IT3080']);
    }

    public function test_require_bank_approval_defaults_to_false(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/organizations/{$organization->id}/courses", [
            'code' => 'IT1110',
            'name' => 'Introduction to Programming',
        ])->assertCreated()->assertJsonPath('data.require_bank_approval', false);
    }

    public function test_a_duplicate_code_in_the_same_organization_is_a_conflict(): void
    {
        $organization = $this->organizationWithAdmin();
        $this->courseIn($organization, ['code' => 'IT3080']);
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/organizations/{$organization->id}/courses", $this->payload())
            ->assertConflict()
            ->assertJsonPath('code', 'DUPLICATE_COURSE_CODE');
    }

    public function test_the_same_code_in_another_organization_is_allowed(): void
    {
        $this->courseIn($this->organizationWithAdmin(), ['code' => 'IT3080']);
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/organizations/{$organization->id}/courses", $this->payload())->assertCreated();
    }

    public function test_creation_validates_the_payload(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/organizations/{$organization->id}/courses", ['code' => str_repeat('X', 21)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name']);
    }

    public function test_creation_is_denied_to_an_instructor(): void
    {
        $organization = $this->organizationWithAdmin();
        Sanctum::actingAs($this->organizationMember($organization));

        $this->postJson("/api/v1/organizations/{$organization->id}/courses", $this->payload())->assertForbidden();
    }

    public function test_a_member_reads_a_course_and_an_outsider_does_not(): void
    {
        $course = $this->courseIn($organization = $this->organizationWithAdmin());

        Sanctum::actingAs($this->organizationMember($organization));
        $this->getJson("/api/v1/courses/{$course->id}")->assertOk()->assertJsonPath('data.id', $course->id);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/courses/{$course->id}")->assertForbidden();
    }

    public function test_an_org_admin_updates_a_course(): void
    {
        $organization = $this->organizationWithAdmin();
        $course = $this->courseIn($organization, ['code' => 'IT3080']);
        Sanctum::actingAs($organization->creator);

        $this->putJson("/api/v1/courses/{$course->id}", ['name' => 'Renamed', 'require_bank_approval' => true])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.require_bank_approval', true);
    }

    public function test_the_course_code_is_not_updatable(): void
    {
        // openapi's UpdateCourseRequest omits `code`: submissions and bank
        // problems are addressed by it, so it is create-only.
        $organization = $this->organizationWithAdmin();
        $course = $this->courseIn($organization, ['code' => 'IT3080']);
        Sanctum::actingAs($organization->creator);

        $this->putJson("/api/v1/courses/{$course->id}", ['code' => 'IT9999'])->assertOk();

        $this->assertSame('IT3080', $course->fresh()?->code);
    }

    public function test_an_instructor_may_not_update_a_course(): void
    {
        $organization = $this->organizationWithAdmin();
        $course = $this->courseIn($organization);
        Sanctum::actingAs($this->organizationMember($organization));

        $this->putJson("/api/v1/courses/{$course->id}", ['name' => 'Renamed'])->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'IT3080',
            'name' => 'Computer Networks',
            'description' => 'Mạng máy tính',
            'require_bank_approval' => true,
        ], $overrides);
    }
}
