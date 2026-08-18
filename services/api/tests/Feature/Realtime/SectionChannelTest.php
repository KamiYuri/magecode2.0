<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Enums\OrganizationRole;
use App\Enums\SectionRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Section;
use App\Models\Semester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * Who may listen on `private-section.{id}` for `analysis.progress` and
 * `analysis.completed` (U-8).
 *
 * `openapi.yml` says "Section instructors", and the 2026-08-12 amendment
 * already removed a TA's read access to analysis results — so the live push is
 * exactly as wide as the HTTP surface it mirrors, and no wider.
 */
class SectionChannelTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Organization $organization;

    private Semester $semester;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organizationWithAdmin();
        $this->semester = $this->semesterIn($this->courseIn($this->organization));
        $this->section = $this->sectionIn($this->semester);
    }

    public function test_an_instructor_of_the_section_may_listen(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Instructor));

        $this->authorise()->assertOk();
    }

    /** The Org Admin sees everything (D-05/D-06's Full tier). */
    public function test_an_organization_admin_may_listen(): void
    {
        $admin = $this->sectionMember($this->sectionIn($this->semester), SectionRole::Student);
        OrganizationMember::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $admin->id,
            'role' => OrganizationRole::Admin,
        ]);

        Sanctum::actingAs($admin);

        $this->authorise()->assertOk();
    }

    /** Analysis results name students; TAs are excluded from the surface entirely. */
    public function test_a_teaching_assistant_may_not_listen(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Ta));

        $this->authorise()->assertForbidden();
    }

    public function test_a_student_may_not_listen(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Student));

        $this->authorise()->assertForbidden();
    }

    /**
     * The batch is semester-wide, but the channel is not: an instructor hears
     * the sections they teach, which is what the frame is addressed to.
     */
    public function test_an_instructor_of_another_section_may_not_listen(): void
    {
        Sanctum::actingAs($this->sectionMember($this->sectionIn($this->semester), SectionRole::Instructor));

        $this->authorise()->assertForbidden();
    }

    public function test_a_guest_may_not_listen(): void
    {
        $this->authorise()->assertUnauthorized();
    }

    public function test_a_section_that_does_not_exist_is_refused(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Instructor));

        $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-section.999999',
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    /** @return TestResponse<Response> */
    private function authorise(): TestResponse
    {
        return $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-section.'.$this->section->id,
            'socket_id' => '1234.5678',
        ]);
    }
}
