<?php

declare(strict_types=1);

namespace Tests\Feature\Problems;

use App\Enums\Difficulty;
use App\Enums\PublishMode;
use App\Enums\SectionRole;
use App\Models\Problem;
use App\Models\ProgrammingLanguage;
use App\Models\Section;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\Tag;
use App\Models\TestCase as TestCaseModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

class ProblemCrudTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Semester $semester;

    private Section $section;

    private User $instructor;

    private User $student;

    private ProgrammingLanguage $python;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()), [
            'publish_mode' => PublishMode::Auto,
            'lock_mode' => PublishMode::Auto,
            'allow_publish_override' => true,
            'allow_lock_override' => true,
        ]);
        $this->section = $this->sectionIn($this->semester);
        $this->instructor = $this->sectionMember($this->section, SectionRole::Instructor);
        $this->student = $this->sectionMember($this->section, SectionRole::Student);
        $this->python = ProgrammingLanguage::factory()->create();
    }

    public function test_staff_list_every_problem_of_the_section(): void
    {
        Problem::factory()->for($this->section)->count(2)->create();
        Sanctum::actingAs($this->instructor);

        $response = $this->getJson("/api/v1/sections/{$this->section->id}/problems")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'section_id', 'name', 'description', 'difficulty', 'group_label', 'order',
                    'max_submissions', 'time_limit', 'memory_limit', 'activation_time', 'lock_time',
                    'publish_mode_override', 'lock_mode_override', 'is_published', 'is_locked',
                    'is_visible', 'is_submittable', 'has_submissions', 'submissions_count',
                    'sample_test_cases', 'created_at', 'updated_at']],
            ]);

        $this->assertCount(2, $response->json('data'));
        $this->assertArrayNotHasKey('meta', $response->json());
    }

    public function test_a_student_lists_only_problems_that_are_open(): void
    {
        $open = Problem::factory()->for($this->section)->create(['activation_time' => now()->subHour()]);
        Problem::factory()->for($this->section)->create(['activation_time' => now()->addHour()]);
        Problem::factory()->for($this->section)->create();
        Sanctum::actingAs($this->student);

        $response = $this->getJson("/api/v1/sections/{$this->section->id}/problems")->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($open->id, $response->json('data.0.id'));
        $this->assertTrue($response->json('data.0.is_visible'));
    }

    public function test_a_student_never_receives_hidden_test_cases(): void
    {
        $problem = Problem::factory()->for($this->section)->create(['activation_time' => now()->subHour()]);
        TestCaseModel::factory()->for($problem)->create(['is_visible' => true, 'input' => 'sample']);
        TestCaseModel::factory()->for($problem)->create(['is_visible' => false, 'input' => 'secret']);
        Sanctum::actingAs($this->student);

        $response = $this->getJson("/api/v1/problems/{$problem->id}?include=test_cases")->assertOk();

        $this->assertCount(1, $response->json('data.sample_test_cases'));
        $this->assertSame('sample', $response->json('data.sample_test_cases.0.input'));
        // include=test_cases is honoured for staff only; a student asking for
        // it still receives the Problem shape.
        $this->assertArrayNotHasKey('test_cases', $response->json('data'));
        $this->assertArrayNotHasKey('edit_rules', $response->json('data'));
    }

    public function test_staff_receive_the_detail_shape(): void
    {
        $problem = Problem::factory()->for($this->section)->create();
        TestCaseModel::factory()->for($problem)->count(2)->create();
        Sanctum::actingAs($this->instructor);

        $response = $this->getJson("/api/v1/problems/{$problem->id}?include=test_cases")
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['test_cases' => [['id', 'input', 'expected_output', 'is_active', 'is_visible', 'order']],
                    'manual_match_group_id', 'testcases_updated_at',
                    'edit_rules' => ['can_edit_description', 'can_edit_test_cases', 'can_edit_limits',
                        'can_edit_languages', 'can_publish', 'can_lock']],
            ]);

        $this->assertCount(2, $response->json('data.test_cases'));
    }

    public function test_listing_filters_by_group_label_and_difficulty(): void
    {
        Problem::factory()->for($this->section)->create(['group_label' => 'Week 5', 'difficulty' => Difficulty::Easy]);
        Problem::factory()->for($this->section)->create(['group_label' => 'Week 6', 'difficulty' => Difficulty::Hard]);
        Sanctum::actingAs($this->instructor);

        $byGroup = $this->getJson("/api/v1/sections/{$this->section->id}/problems?group_label=Week+5")->assertOk();
        $this->assertCount(1, $byGroup->json('data'));

        $byDifficulty = $this->getJson("/api/v1/sections/{$this->section->id}/problems?difficulty=hard")->assertOk();
        $this->assertCount(1, $byDifficulty->json('data'));
        $this->assertSame('Week 6', $byDifficulty->json('data.0.group_label'));
    }

    public function test_relations_are_loaded_only_when_included(): void
    {
        $problem = Problem::factory()->for($this->section)->create();
        $problem->programmingLanguages()->attach($this->python);
        Sanctum::actingAs($this->instructor);

        $minimal = $this->getJson("/api/v1/sections/{$this->section->id}/problems")->assertOk();
        $this->assertArrayNotHasKey('programming_languages', $minimal->json('data.0'));

        $expanded = $this->getJson("/api/v1/sections/{$this->section->id}/problems?include=programming_languages,creator")
            ->assertOk();
        $this->assertSame($this->python->id, $expanded->json('data.0.programming_languages.0.id'));
        $this->assertSame($problem->creator_id, $expanded->json('data.0.creator.id'));
    }

    public function test_an_unknown_include_is_rejected(): void
    {
        Problem::factory()->for($this->section)->create();
        Sanctum::actingAs($this->instructor);

        $this->getJson("/api/v1/sections/{$this->section->id}/problems?include=submissions")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['include.0']);
    }

    public function test_listing_is_denied_to_someone_outside_the_section(): void
    {
        Sanctum::actingAs($this->sectionMember($this->sectionIn($this->semester, ['name' => 'L02'])));

        $this->getJson("/api/v1/sections/{$this->section->id}/problems")->assertForbidden();
    }

    public function test_an_instructor_creates_a_problem_with_test_cases_and_languages(): void
    {
        $tag = Tag::factory()->create(['course_id' => $this->semester->course_id]);
        Sanctum::actingAs($this->instructor);

        $response = $this->postJson("/api/v1/sections/{$this->section->id}/problems", $this->payload([
            'tag_ids' => [$tag->id],
            'test_cases' => [
                ['input' => '1 2', 'expected_output' => '3', 'is_visible' => true, 'order' => 0],
                ['input' => '2 3', 'expected_output' => '5'],
            ],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Two Sum')
            ->assertJsonPath('data.section_id', $this->section->id)
            ->assertJsonPath('data.difficulty', Difficulty::Easy->value)
            ->assertJsonPath('data.is_visible', false)
            ->assertJsonPath('data.has_submissions', false);

        $problem = Problem::findOrFail($response->json('data.id'));
        $this->assertSame($this->instructor->id, $problem->creator_id);
        $this->assertCount(2, $problem->testCases);
        $this->assertSame([$this->python->id], $problem->programmingLanguages->pluck('id')->all());
        $this->assertSame([$tag->id], $problem->tags->pluck('id')->all());
        $this->assertNotNull($problem->testcases_updated_at);
    }

    public function test_creation_validates_the_payload(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/problems", [
            'time_limit' => 50,
            'memory_limit' => 999999999,
            'programming_language_ids' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'description', 'time_limit', 'memory_limit', 'programming_language_ids']);
    }

    public function test_creation_rejects_a_language_that_does_not_exist(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/problems", $this->payload([
            'programming_language_ids' => [404404],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['programming_language_ids.0']);
    }

    public function test_creation_rejects_a_tag_from_another_course(): void
    {
        // Tags are course-scoped (D-15), so a tag from elsewhere would leak a
        // vocabulary the section does not own.
        $foreign = Tag::factory()->create();
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/problems", $this->payload(['tag_ids' => [$foreign->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tag_ids.0']);
    }

    public function test_creation_rejects_more_than_fifty_test_cases(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/problems", $this->payload([
            'test_cases' => array_fill(0, 51, ['input' => 'x', 'expected_output' => 'y']),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['test_cases']);
    }

    public function test_a_ta_may_not_create_a_problem(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Ta));

        $this->postJson("/api/v1/sections/{$this->section->id}/problems", $this->payload())->assertForbidden();
    }

    public function test_a_student_may_not_create_a_problem(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson("/api/v1/sections/{$this->section->id}/problems", $this->payload())->assertForbidden();
    }

    public function test_an_instructor_updates_a_problem(): void
    {
        $problem = Problem::factory()->for($this->section)->create(['name' => 'Old']);
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$problem->id}", ['name' => 'New', 'difficulty' => 'hard'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.difficulty', Difficulty::Hard->value)
            ->assertJsonPath('meta.outdated_submissions_count', 0);
    }

    public function test_every_changed_field_is_written_to_the_edit_log(): void
    {
        $problem = Problem::factory()->for($this->section)->create(['name' => 'Old', 'time_limit' => 1000]);
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$problem->id}", [
            'name' => 'New',
            'time_limit' => 2000,
            'difficulty' => $problem->difficulty->value,
        ])->assertOk();

        $logs = $problem->editLogs()->get()->keyBy('field_changed');
        $this->assertSame(['name', 'time_limit'], $logs->keys()->sort()->values()->all());
        $this->assertSame('Old', $logs['name']->old_value);
        $this->assertSame('New', $logs['name']->new_value);
        $this->assertSame($this->instructor->id, $logs['name']->edited_by);
    }

    public function test_core_fields_are_frozen_once_the_problem_is_locked(): void
    {
        $problem = Problem::factory()->for($this->section)->create(['lock_time' => now()->subHour()]);
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$problem->id}", ['time_limit' => 2000])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROBLEM_LOCKED');

        $this->assertSame(1000, $problem->fresh()?->time_limit);
    }

    public function test_descriptive_fields_stay_editable_while_locked(): void
    {
        $problem = Problem::factory()->for($this->section)->create(['lock_time' => now()->subHour()]);
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$problem->id}", ['name' => 'Renamed', 'lock_time' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_changing_core_fields_flags_existing_submissions_as_outdated(): void
    {
        $problem = Problem::factory()->for($this->section)->create();
        $submission = Submission::factory()->for($problem)->create(['creator_id' => $this->student->id]);
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$problem->id}", ['time_limit' => 3000])
            ->assertOk()
            ->assertJsonPath('meta.outdated_submissions_count', 1);

        $this->assertTrue($submission->fresh()?->is_outdated);
    }

    public function test_a_descriptive_change_leaves_submissions_alone(): void
    {
        $problem = Problem::factory()->for($this->section)->create();
        $submission = Submission::factory()->for($problem)->create(['creator_id' => $this->student->id]);
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$problem->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('meta.outdated_submissions_count', 0);

        $this->assertFalse($submission->fresh()?->is_outdated);
    }

    public function test_an_instructor_of_another_section_may_not_touch_the_problem(): void
    {
        $problem = Problem::factory()->for($this->section)->create();
        $other = $this->sectionIn($this->semester, ['name' => 'L02']);
        Sanctum::actingAs($this->sectionMember($other, SectionRole::Instructor));

        $this->getJson("/api/v1/problems/{$problem->id}")->assertForbidden();
        $this->putJson("/api/v1/problems/{$problem->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->deleteJson("/api/v1/problems/{$problem->id}")->assertForbidden();
    }

    public function test_deleting_a_problem_soft_deletes_it(): void
    {
        $problem = Problem::factory()->for($this->section)->create();
        Submission::factory()->for($problem)->create(['creator_id' => $this->student->id]);
        Sanctum::actingAs($this->instructor);

        $this->deleteJson("/api/v1/problems/{$problem->id}")->assertNoContent();

        $this->assertSoftDeleted('problems', ['id' => $problem->id]);
        // Submissions are never deleted (D-52).
        $this->assertDatabaseCount('submissions', 1);
        $this->getJson("/api/v1/problems/{$problem->id}")->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Two Sum',
            'description' => 'Cho một mảng số nguyên, tìm hai phần tử có tổng bằng target.',
            'difficulty' => 'easy',
            'time_limit' => 1000,
            'memory_limit' => 65536,
            'programming_language_ids' => [$this->python->id],
        ], $overrides);
    }
}
