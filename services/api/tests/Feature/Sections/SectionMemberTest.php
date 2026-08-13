<?php

declare(strict_types=1);

namespace Tests\Feature\Sections;

use App\Enums\SectionRole;
use App\Models\Section;
use App\Models\SectionMember;
use App\Models\Semester;
use App\Models\User;
use App\Notifications\SectionInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * The section roster. The rule worth watching is D-51: a student belongs to at
 * most one section of a course in a semester, enforced in the application
 * because no database constraint can span the three tables it needs.
 */
class SectionMemberTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Semester $semester;

    private Section $section;

    private User $instructor;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = $this->organizationWithAdmin();
        $this->orgAdmin = $organization->creator;
        $this->semester = $this->semesterIn($this->courseIn($organization));
        $this->section = $this->sectionIn($this->semester, ['name' => 'L01']);
        $this->instructor = $this->sectionMember($this->section, SectionRole::Instructor);
    }

    public function test_the_roster_is_listed_with_the_cursor_envelope(): void
    {
        $this->sectionMember($this->section, SectionRole::Student);
        Sanctum::actingAs($this->instructor);

        $response = $this->getJson("/api/v1/sections/{$this->section->id}/members")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'user' => ['id', 'username', 'first_name', 'last_name', 'student_id', 'avatar_url'],
                    'role', 'added_by', 'created_at']],
                'meta' => ['next_cursor', 'prev_cursor', 'per_page', 'has_more'],
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_the_roster_filters_by_role_and_search(): void
    {
        $this->sectionMember($this->section, SectionRole::Student, User::factory()->create([
            'first_name' => 'Quỳnh', 'username' => 'quynhnn', 'student_id' => '20210001',
        ]));
        $this->sectionMember($this->section, SectionRole::Student);
        Sanctum::actingAs($this->instructor);

        $byRole = $this->getJson("/api/v1/sections/{$this->section->id}/members?role=instructor")->assertOk();
        $this->assertCount(1, $byRole->json('data'));

        $bySearch = $this->getJson("/api/v1/sections/{$this->section->id}/members?search=20210001")->assertOk();
        $this->assertCount(1, $bySearch->json('data'));
        $this->assertSame('quynhnn', $bySearch->json('data.0.user.username'));
    }

    public function test_a_student_may_not_read_the_roster(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Student));

        $this->getJson("/api/v1/sections/{$this->section->id}/members")->assertForbidden();
    }

    public function test_members_are_added_in_bulk_with_counts(): void
    {
        Notification::fake();
        $existing = User::factory()->student()->create();
        $already = $this->sectionMember($this->section, SectionRole::Student);
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members", [
            'members' => [
                ['email' => $existing->email, 'role' => 'student'],
                ['email' => $already->email, 'role' => 'student'],
                ['email' => 'newcomer@sis.hust.edu.vn', 'role' => 'student'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.invited', 1);

        $this->assertDatabaseHas('section_members', [
            'section_id' => $this->section->id,
            'user_id' => $existing->id,
            'role' => SectionRole::Student->value,
            'added_by' => $this->instructor->id,
        ]);

        $invitee = User::where('email', 'newcomer@sis.hust.edu.vn')->firstOrFail();
        $this->assertTrue($invitee->is_first_time_register);
        Notification::assertSentTo($invitee, SectionInvitation::class);
    }

    public function test_a_student_already_in_a_sibling_section_is_refused(): void
    {
        // D-51: one section per course per semester. The sibling shares the
        // semester, so enrolling here would put the student in two classes of
        // the same course at once.
        $sibling = $this->sectionIn($this->semester, ['name' => 'L02']);
        $enrolled = $this->sectionMember($sibling, SectionRole::Student);
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members", [
            'members' => [['email' => $enrolled->email, 'role' => 'student']],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'DUPLICATE_ENROLLMENT');

        $this->assertDatabaseMissing('section_members', [
            'section_id' => $this->section->id,
            'user_id' => $enrolled->id,
        ]);
    }

    public function test_the_duplicate_rule_only_looks_at_students(): void
    {
        // An instructor may well teach both L01 and L02.
        $sibling = $this->sectionIn($this->semester, ['name' => 'L02']);
        $teacher = $this->sectionMember($sibling, SectionRole::Instructor);
        Sanctum::actingAs($this->orgAdmin);

        $this->postJson("/api/v1/sections/{$this->section->id}/members", [
            'members' => [['email' => $teacher->email, 'role' => 'instructor']],
        ])->assertOk()->assertJsonPath('data.added', 1);
    }

    public function test_the_duplicate_rule_ignores_other_semesters(): void
    {
        $otherSemester = $this->semesterIn($this->semester->course, ['name' => '20241']);
        $past = $this->sectionIn($otherSemester, ['name' => 'L01']);
        $student = $this->sectionMember($past, SectionRole::Student);
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members", [
            'members' => [['email' => $student->email, 'role' => 'student']],
        ])->assertOk()->assertJsonPath('data.added', 1);
    }

    public function test_adding_members_validates_the_payload(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members", [
            'members' => [['email' => 'nope', 'role' => 'admin']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['members.0.email', 'members.0.role']);
    }

    public function test_a_ta_may_not_manage_the_roster(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Ta));

        $this->postJson("/api/v1/sections/{$this->section->id}/members", [
            'members' => [['email' => 'newcomer@sis.hust.edu.vn', 'role' => 'student']],
        ])->assertForbidden();
    }

    public function test_a_member_role_is_changed(): void
    {
        $student = $this->sectionMember($this->section, SectionRole::Student);
        $membership = SectionMember::where('user_id', $student->id)->firstOrFail();
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/sections/{$this->section->id}/members/{$membership->id}", ['role' => 'ta'])
            ->assertOk()
            ->assertJsonPath('data.role', SectionRole::Ta->value);

        $this->assertSame(SectionRole::Ta, $membership->fresh()?->role);
    }

    public function test_promoting_to_student_re_checks_the_duplicate_rule(): void
    {
        $sibling = $this->sectionIn($this->semester, ['name' => 'L02']);
        $teacher = $this->sectionMember($sibling, SectionRole::Instructor);
        $membership = SectionMember::factory()->instructor()->create([
            'section_id' => $this->section->id,
            'user_id' => $teacher->id,
        ]);
        Sanctum::actingAs($this->orgAdmin);

        $this->putJson("/api/v1/sections/{$this->section->id}/members/{$membership->id}", ['role' => 'student'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'DUPLICATE_ENROLLMENT');
    }

    public function test_a_member_is_removed(): void
    {
        $student = $this->sectionMember($this->section, SectionRole::Student);
        $membership = SectionMember::where('user_id', $student->id)->firstOrFail();
        Sanctum::actingAs($this->instructor);

        $this->deleteJson("/api/v1/sections/{$this->section->id}/members/{$membership->id}")->assertNoContent();

        $this->assertDatabaseMissing('section_members', ['id' => $membership->id]);
    }

    public function test_a_membership_of_another_section_is_not_found(): void
    {
        $sibling = $this->sectionIn($this->semester, ['name' => 'L02']);
        $foreign = SectionMember::factory()->create(['section_id' => $sibling->id]);
        Sanctum::actingAs($this->instructor);

        $this->deleteJson("/api/v1/sections/{$this->section->id}/members/{$foreign->id}")->assertNotFound();
    }
}
