<?php

declare(strict_types=1);

namespace Tests\Feature\Problems;

use App\Enums\PublishMode;
use App\Enums\SectionRole;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * Manual publish/lock and bulk reorder. The interesting rule is D-16's:
 * a semester may pin the mode for everyone below the Org Admin, which is how
 * a final exam closes at one time across every section.
 */
class ProblemLifecycleTest extends TestCase
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
        $this->semester = $this->semesterIn($this->courseIn($organization), [
            'publish_mode' => PublishMode::Manual,
            'lock_mode' => PublishMode::Manual,
            'allow_publish_override' => true,
            'allow_lock_override' => true,
        ]);
        $this->section = $this->sectionIn($this->semester);
        $this->instructor = $this->sectionMember($this->section, SectionRole::Instructor);
    }

    public function test_an_instructor_publishes_and_unpublishes_a_problem(): void
    {
        $problem = Problem::factory()->for($this->section)->create();
        Sanctum::actingAs($this->instructor);

        $this->patchJson("/api/v1/problems/{$problem->id}/publish", ['is_published' => true])
            ->assertOk()
            ->assertJsonPath('data.is_published', true)
            ->assertJsonPath('data.is_visible', true);

        $this->patchJson("/api/v1/problems/{$problem->id}/publish", ['is_published' => false])
            ->assertOk()
            ->assertJsonPath('data.is_visible', false);
    }

    public function test_an_instructor_locks_and_unlocks_a_problem(): void
    {
        $problem = Problem::factory()->for($this->section)->published()->create();
        Sanctum::actingAs($this->instructor);

        $this->patchJson("/api/v1/problems/{$problem->id}/lock", ['is_locked' => true])
            ->assertOk()
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.is_submittable', false);

        $this->patchJson("/api/v1/problems/{$problem->id}/lock", ['is_locked' => false])
            ->assertOk()
            ->assertJsonPath('data.is_submittable', true);
    }

    public function test_an_instructor_may_not_publish_when_the_semester_pins_the_mode(): void
    {
        $this->semester->update(['allow_publish_override' => false]);
        $problem = Problem::factory()->for($this->section)->create();
        Sanctum::actingAs($this->instructor);

        $this->patchJson("/api/v1/problems/{$problem->id}/publish", ['is_published' => true])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'POLICY_OVERRIDE_DENIED');

        $this->assertFalse($problem->fresh()?->is_published);
    }

    public function test_an_instructor_may_not_lock_when_the_semester_pins_the_mode(): void
    {
        $this->semester->update(['allow_lock_override' => false]);
        $problem = Problem::factory()->for($this->section)->create();
        Sanctum::actingAs($this->instructor);

        $this->patchJson("/api/v1/problems/{$problem->id}/lock", ['is_locked' => true])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'POLICY_OVERRIDE_DENIED');
    }

    public function test_an_org_admin_bypasses_the_semester_policy(): void
    {
        $this->semester->update(['allow_publish_override' => false, 'allow_lock_override' => false]);
        $problem = Problem::factory()->for($this->section)->create();
        Sanctum::actingAs($this->orgAdmin);

        $this->patchJson("/api/v1/problems/{$problem->id}/publish", ['is_published' => true])->assertOk();
        $this->patchJson("/api/v1/problems/{$problem->id}/lock", ['is_locked' => true])->assertOk();

        $problem->refresh();
        $this->assertTrue($problem->is_published);
        $this->assertTrue($problem->is_locked);
    }

    public function test_edit_rules_report_what_the_semester_allows(): void
    {
        $this->semester->update(['allow_publish_override' => false]);
        $problem = Problem::factory()->for($this->section)->create();

        Sanctum::actingAs($this->instructor);
        $this->getJson("/api/v1/problems/{$problem->id}")
            ->assertOk()
            ->assertJsonPath('data.edit_rules.can_publish', false)
            ->assertJsonPath('data.edit_rules.can_lock', true);

        Sanctum::actingAs($this->orgAdmin);
        $this->getJson("/api/v1/problems/{$problem->id}")
            ->assertOk()
            ->assertJsonPath('data.edit_rules.can_publish', true);
    }

    public function test_toggling_requires_the_flag(): void
    {
        $problem = Problem::factory()->for($this->section)->create();
        Sanctum::actingAs($this->instructor);

        $this->patchJson("/api/v1/problems/{$problem->id}/publish", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_published']);
    }

    public function test_a_ta_may_not_publish(): void
    {
        $problem = Problem::factory()->for($this->section)->create();
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Ta));

        $this->patchJson("/api/v1/problems/{$problem->id}/publish", ['is_published' => true])->assertForbidden();
    }

    public function test_publishing_is_recorded_in_the_edit_log(): void
    {
        $problem = Problem::factory()->for($this->section)->create();
        Sanctum::actingAs($this->instructor);

        $this->patchJson("/api/v1/problems/{$problem->id}/publish", ['is_published' => true])->assertOk();

        $log = $problem->editLogs()->firstOrFail();
        $this->assertSame('is_published', $log->field_changed);
        $this->assertSame('false', $log->old_value);
        $this->assertSame('true', $log->new_value);
    }

    public function test_problems_are_reordered_in_bulk(): void
    {
        $first = Problem::factory()->for($this->section)->create(['order' => 1]);
        $second = Problem::factory()->for($this->section)->create(['order' => 2]);
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/sections/{$this->section->id}/problems/reorder", [
            'order' => [
                ['problem_id' => $second->id, 'order' => 1, 'group_label' => 'Week 1'],
                ['problem_id' => $first->id, 'order' => 2],
            ],
        ])->assertOk();

        $this->assertSame(1, $second->fresh()?->order);
        $this->assertSame('Week 1', $second->fresh()?->group_label);
        $this->assertSame(2, $first->fresh()?->order);
    }

    public function test_the_listing_follows_the_stored_order(): void
    {
        $last = Problem::factory()->for($this->section)->create(['order' => 5, 'name' => 'Last']);
        $first = Problem::factory()->for($this->section)->create(['order' => 1, 'name' => 'First']);
        $unordered = Problem::factory()->for($this->section)->create(['name' => 'Unordered']);
        Sanctum::actingAs($this->instructor);

        $names = $this->getJson("/api/v1/sections/{$this->section->id}/problems")
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame([$first->name, $last->name, $unordered->name], $names);
    }

    public function test_reorder_rejects_a_problem_from_another_section(): void
    {
        $foreign = Problem::factory()->for($this->sectionIn($this->semester, ['name' => 'L02']))->create();
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/sections/{$this->section->id}/problems/reorder", [
            'order' => [['problem_id' => $foreign->id, 'order' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['order.0.problem_id']);
    }

    public function test_a_student_may_not_reorder(): void
    {
        $problem = Problem::factory()->for($this->section)->create();
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Student));

        $this->putJson("/api/v1/sections/{$this->section->id}/problems/reorder", [
            'order' => [['problem_id' => $problem->id, 'order' => 1]],
        ])->assertForbidden();
    }
}
