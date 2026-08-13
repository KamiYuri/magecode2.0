<?php

declare(strict_types=1);

namespace Tests\Feature\Sections;

use App\Enums\SectionRole;
use App\Models\Section;
use App\Models\SectionMember;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * D-50: moving a student between sections is a move plus an audit row. The
 * log is why the transfer exists as its own endpoint rather than a remove
 * followed by an add — those two would leave no trace of where the student
 * came from.
 */
class SectionTransferTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Semester $semester;

    private Section $origin;

    private Section $target;

    private User $orgAdmin;

    private User $student;

    private SectionMember $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = $this->organizationWithAdmin();
        $this->orgAdmin = $organization->creator;
        $this->semester = $this->semesterIn($this->courseIn($organization));
        $this->origin = $this->sectionIn($this->semester, ['name' => 'L01']);
        $this->target = $this->sectionIn($this->semester, ['name' => 'L02']);
        $this->student = $this->sectionMember($this->origin, SectionRole::Student);
        $this->membership = SectionMember::where('user_id', $this->student->id)->firstOrFail();
    }

    public function test_a_student_is_moved_and_the_move_is_logged(): void
    {
        Sanctum::actingAs($this->orgAdmin);

        $this->postJson(
            "/api/v1/sections/{$this->origin->id}/members/{$this->membership->id}/transfer",
            ['to_section_id' => $this->target->id]
        )->assertOk()->assertJsonPath('data.user.id', $this->student->id);

        $this->assertDatabaseMissing('section_members', ['id' => $this->membership->id]);
        $this->assertDatabaseHas('section_members', [
            'section_id' => $this->target->id,
            'user_id' => $this->student->id,
            'role' => SectionRole::Student->value,
            'added_by' => $this->orgAdmin->id,
        ]);
        $this->assertDatabaseHas('section_transfer_logs', [
            'user_id' => $this->student->id,
            'from_section_id' => $this->origin->id,
            'to_section_id' => $this->target->id,
            'transferred_by' => $this->orgAdmin->id,
        ]);
    }

    public function test_a_transfer_outside_the_semester_is_refused(): void
    {
        $elsewhere = $this->sectionIn(
            $this->semesterIn($this->semester->course, ['name' => '20241']),
            ['name' => 'L01']
        );
        Sanctum::actingAs($this->orgAdmin);

        $this->postJson(
            "/api/v1/sections/{$this->origin->id}/members/{$this->membership->id}/transfer",
            ['to_section_id' => $elsewhere->id]
        )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'TRANSFER_OUTSIDE_SEMESTER');

        $this->assertDatabaseHas('section_members', ['id' => $this->membership->id]);
        $this->assertDatabaseCount('section_transfer_logs', 0);
    }

    public function test_a_transfer_into_the_same_section_is_refused(): void
    {
        Sanctum::actingAs($this->orgAdmin);

        $this->postJson(
            "/api/v1/sections/{$this->origin->id}/members/{$this->membership->id}/transfer",
            ['to_section_id' => $this->origin->id]
        )->assertUnprocessable()->assertJsonValidationErrors(['to_section_id']);
    }

    public function test_the_target_section_must_exist(): void
    {
        Sanctum::actingAs($this->orgAdmin);

        $this->postJson(
            "/api/v1/sections/{$this->origin->id}/members/{$this->membership->id}/transfer",
            ['to_section_id' => 404404]
        )->assertUnprocessable()->assertJsonValidationErrors(['to_section_id']);
    }

    public function test_only_a_student_membership_can_be_transferred(): void
    {
        $ta = $this->sectionMember($this->origin, SectionRole::Ta);
        $membership = SectionMember::where('user_id', $ta->id)->firstOrFail();
        Sanctum::actingAs($this->orgAdmin);

        $this->postJson(
            "/api/v1/sections/{$this->origin->id}/members/{$membership->id}/transfer",
            ['to_section_id' => $this->target->id]
        )->assertUnprocessable();
    }

    public function test_an_instructor_may_not_transfer_a_student(): void
    {
        // D-50 puts transfers with the Org Admin: the move crosses a section
        // boundary, so no instructor owns both sides of it.
        Sanctum::actingAs($this->sectionMember($this->origin, SectionRole::Instructor));

        $this->postJson(
            "/api/v1/sections/{$this->origin->id}/members/{$this->membership->id}/transfer",
            ['to_section_id' => $this->target->id]
        )->assertForbidden();
    }

    public function test_a_membership_of_another_section_is_not_found(): void
    {
        $foreign = SectionMember::factory()->create(['section_id' => $this->target->id]);
        Sanctum::actingAs($this->orgAdmin);

        $this->postJson(
            "/api/v1/sections/{$this->origin->id}/members/{$foreign->id}/transfer",
            ['to_section_id' => $this->target->id]
        )->assertNotFound();
    }
}
