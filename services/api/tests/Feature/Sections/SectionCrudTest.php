<?php

declare(strict_types=1);

namespace Tests\Feature\Sections;

use App\Enums\SectionRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * The section is the isolation boundary (D-04), so the listing is where that
 * boundary is most visible: an instructor of L01 must not learn L02 exists
 * from a list the semester owns.
 */
class SectionCrudTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    public function test_listing_is_a_bare_collection_without_pagination_meta(): void
    {
        // openapi declares listSections without cursor or per_page: a
        // semester holds a handful of sections, so the whole set is returned.
        $semester = $this->semesterIn($this->courseIn($organization = $this->organizationWithAdmin()));
        $this->sectionIn($semester, ['name' => 'L01']);
        Sanctum::actingAs($organization->creator);

        $response = $this->getJson("/api/v1/semesters/{$semester->id}/sections")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'semester_id', 'name', 'description', 'creator', 'my_role',
                    'members_count', 'problems_count', 'created_at', 'updated_at']],
            ]);

        $this->assertArrayNotHasKey('meta', $response->json());
    }

    public function test_an_org_admin_sees_every_section_of_the_semester(): void
    {
        $semester = $this->semesterIn($this->courseIn($organization = $this->organizationWithAdmin()));
        $this->sectionIn($semester, ['name' => 'L01']);
        $this->sectionIn($semester, ['name' => 'L02']);
        Sanctum::actingAs($organization->creator);

        $response = $this->getJson("/api/v1/semesters/{$semester->id}/sections")->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_an_instructor_sees_only_their_own_section(): void
    {
        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()));
        $own = $this->sectionIn($semester, ['name' => 'L01']);
        $this->sectionIn($semester, ['name' => 'L02']);
        Sanctum::actingAs($this->sectionMember($own, SectionRole::Instructor));

        $response = $this->getJson("/api/v1/semesters/{$semester->id}/sections")->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('L01', $response->json('data.0.name'));
        $this->assertSame(SectionRole::Instructor->value, $response->json('data.0.my_role'));
    }

    public function test_listing_is_denied_to_an_outsider(): void
    {
        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()));
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/semesters/{$semester->id}/sections")->assertForbidden();
    }

    public function test_an_org_admin_creates_a_section(): void
    {
        $semester = $this->semesterIn($this->courseIn($organization = $this->organizationWithAdmin()));
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/semesters/{$semester->id}/sections", ['name' => 'L01', 'description' => 'Lớp 01'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'L01')
            ->assertJsonPath('data.semester_id', $semester->id)
            ->assertJsonPath('data.members_count', 0)
            ->assertJsonPath('data.problems_count', 0)
            ->assertJsonPath('data.my_role', null);

        $this->assertDatabaseHas('sections', ['semester_id' => $semester->id, 'name' => 'L01']);
    }

    public function test_a_duplicate_name_in_the_same_semester_is_a_conflict(): void
    {
        $semester = $this->semesterIn($this->courseIn($organization = $this->organizationWithAdmin()));
        $this->sectionIn($semester, ['name' => 'L01']);
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/semesters/{$semester->id}/sections", ['name' => 'L01'])
            ->assertConflict()
            ->assertJsonPath('code', 'DUPLICATE_SECTION_NAME');
    }

    public function test_creation_validates_the_payload(): void
    {
        $semester = $this->semesterIn($this->courseIn($organization = $this->organizationWithAdmin()));
        Sanctum::actingAs($organization->creator);

        $this->postJson("/api/v1/semesters/{$semester->id}/sections", ['name' => str_repeat('L', 51)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_creation_is_denied_to_a_section_instructor(): void
    {
        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()));
        $section = $this->sectionIn($semester, ['name' => 'L01']);
        Sanctum::actingAs($this->sectionMember($section, SectionRole::Instructor));

        $this->postJson("/api/v1/semesters/{$semester->id}/sections", ['name' => 'L02'])->assertForbidden();
    }

    public function test_a_section_member_reads_the_section(): void
    {
        $section = $this->sectionIn($this->semesterIn($this->courseIn($this->organizationWithAdmin())));
        Sanctum::actingAs($this->sectionMember($section, SectionRole::Student));

        $this->getJson("/api/v1/sections/{$section->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $section->id)
            ->assertJsonPath('data.my_role', SectionRole::Student->value);
    }

    public function test_a_member_of_another_section_may_not_read_it(): void
    {
        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()));
        $other = $this->sectionIn($semester, ['name' => 'L02']);
        $section = $this->sectionIn($semester, ['name' => 'L01']);
        Sanctum::actingAs($this->sectionMember($other, SectionRole::Instructor));

        $this->getJson("/api/v1/sections/{$section->id}")->assertForbidden();
    }

    public function test_an_org_admin_updates_a_section(): void
    {
        $section = $this->sectionIn($this->semesterIn($this->courseIn($organization = $this->organizationWithAdmin())));
        Sanctum::actingAs($organization->creator);

        $this->putJson("/api/v1/sections/{$section->id}", ['name' => 'L09'])
            ->assertOk()
            ->assertJsonPath('data.name', 'L09');
    }

    public function test_a_section_instructor_may_not_rename_their_section(): void
    {
        // Renaming is Org Admin territory: the name is how a roster import
        // and a transfer (B7) address the class.
        $section = $this->sectionIn($this->semesterIn($this->courseIn($this->organizationWithAdmin())));
        Sanctum::actingAs($this->sectionMember($section, SectionRole::Instructor));

        $this->putJson("/api/v1/sections/{$section->id}", ['name' => 'L09'])->assertForbidden();
    }
}
