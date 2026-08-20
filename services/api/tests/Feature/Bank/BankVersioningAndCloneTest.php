<?php

declare(strict_types=1);

namespace Tests\Feature\Bank;

use App\Enums\BankProblemStatus;
use App\Enums\OrganizationRole;
use App\Enums\SectionRole;
use App\Models\BankProblem;
use App\Models\Course;
use App\Models\Organization;
use App\Models\Problem;
use App\Models\ProgrammingLanguage;
use App\Models\Section;
use App\Models\Tag;
use App\Models\TestCase as TestCaseModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * D-63/D-64 (the version chain), D-66 (publishing makes a version, never an
 * overwrite) and D-65 (the deep copy into a section).
 */
class BankVersioningAndCloneTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Organization $organization;

    private Course $course;

    private Section $section;

    private User $instructor;

    private ProgrammingLanguage $language;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organizationWithAdmin();
        $this->course = $this->courseIn($this->organization, ['require_bank_approval' => false]);
        $this->section = $this->sectionIn($this->semesterIn($this->course));
        $this->instructor = $this->organizationMember($this->organization, OrganizationRole::Instructor);
        $this->sectionMember($this->section, SectionRole::Instructor, $this->instructor);
        $this->language = ProgrammingLanguage::factory()->create();
    }

    public function test_publishing_a_section_problem_creates_the_first_version(): void
    {
        $problem = $this->problemWithTestCases();

        $this->actingAs($this->instructor)
            ->postJson("/api/v1/courses/{$this->course->id}/bank/publish", ['problem_id' => $problem->id])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.original_id', null)
            ->assertJsonCount(2, 'data.test_cases');
    }

    /**
     * D-66: republishing a problem that came *from* the bank extends that
     * chain. Starting a second chain would fork the history, and `versions`
     * would answer with one row for ever.
     */
    public function test_republishing_a_cloned_problem_extends_the_same_chain(): void
    {
        $first = $this->bankEntry();

        $clone = $this->actingAs($this->instructor)
            ->postJson("/api/v1/sections/{$this->section->id}/problems/clone", [
                'bank_problem_id' => $first->id,
            ])->assertCreated()->json('data.id');

        $second = $this->actingAs($this->instructor)
            ->postJson("/api/v1/courses/{$this->course->id}/bank/publish", ['problem_id' => $clone])
            ->assertCreated();

        $second->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.original_id', $first->id);

        // And the first version is untouched -- a section teaching v1 keeps v1.
        $this->assertSame(1, $first->fresh()->version);
        $this->assertSame('Tổng hai số', $first->fresh()->name);
    }

    public function test_the_version_listing_returns_the_whole_chain_oldest_first(): void
    {
        $first = $this->bankEntry();
        $second = BankProblem::factory()->create([
            'course_id' => $this->course->id,
            'author_id' => $this->instructor->id,
            'original_id' => $first->id,
            'version' => 2,
            'status' => BankProblemStatus::Approved->value,
        ]);

        foreach ([$first->id, $second->id] as $asked) {
            $this->actingAs($this->instructor)
                ->getJson("/api/v1/bank-problems/{$asked}/versions")
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('data.0.version', 1)
                ->assertJsonPath('data.1.version', 2);
        }
    }

    /** D-65: metadata, test cases, languages and tags all come across. */
    public function test_a_clone_is_a_deep_copy(): void
    {
        $entry = $this->bankEntry();
        $tag = Tag::factory()->for($this->course)->create();
        $entry->tags()->attach($tag);
        $entry->programmingLanguages()->attach($this->language);
        $entry->testCases()->create([
            'input' => '1 2', 'expected_output' => '3',
            'is_active' => true, 'is_visible' => true, 'order' => 1,
        ]);
        $entry->testCases()->create([
            'input' => '4 5', 'expected_output' => '9',
            'is_active' => true, 'is_visible' => false, 'order' => 2,
        ]);

        $response = $this->actingAs($this->instructor)
            ->postJson("/api/v1/sections/{$this->section->id}/problems/clone", [
                'bank_problem_id' => $entry->id,
                'group_label' => 'Tuần 1',
            ])
            ->assertCreated();

        $problemId = (int) $response->json('data.id');
        $problem = Problem::query()->findOrFail($problemId);

        $this->assertSame($entry->name, $problem->name);
        $this->assertSame('Tuần 1', $problem->group_label);
        // Its origin is recorded so the UI can offer "a newer version exists".
        $this->assertSame($entry->id, $problem->bank_problem_id);
        $this->assertSame(2, $problem->testCases()->count());
        $this->assertSame(1, $problem->tags()->count());
        $this->assertSame(1, $problem->programmingLanguages()->count());
        // The hidden test case stays hidden.
        $this->assertFalse((bool) $problem->testCases()->where('order', 2)->first()?->is_visible);
    }

    /** Nothing propagates: the instructor owns their copy. */
    public function test_editing_the_bank_entry_afterwards_leaves_the_clone_alone(): void
    {
        $entry = $this->bankEntry();
        $entry->programmingLanguages()->attach($this->language);

        $problemId = (int) $this->actingAs($this->instructor)
            ->postJson("/api/v1/sections/{$this->section->id}/problems/clone", [
                'bank_problem_id' => $entry->id,
            ])->assertCreated()->json('data.id');

        $this->actingAs($this->instructor)->putJson("/api/v1/bank-problems/{$entry->id}", [
            'name' => 'Tên mới trong ngân hàng',
            'description' => 'khác',
            'time_limit' => 2000,
            'memory_limit' => 65536,
            'programming_language_ids' => [$this->language->id],
        ])->assertOk();

        $this->assertSame('Tổng hai số', Problem::query()->findOrFail($problemId)->name);
    }

    /** openapi names this one: BANK_COURSE_MISMATCH. */
    public function test_cloning_across_courses_is_refused(): void
    {
        $elsewhere = $this->courseIn($this->organization, ['require_bank_approval' => false]);
        $foreign = BankProblem::factory()->create([
            'course_id' => $elsewhere->id,
            'author_id' => $this->instructor->id,
            'status' => BankProblemStatus::Approved->value,
        ]);

        $this->actingAs($this->instructor)
            ->postJson("/api/v1/sections/{$this->section->id}/problems/clone", [
                'bank_problem_id' => $foreign->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_problem_id');
    }

    /**
     * An unapproved entry is not part of the shared vocabulary yet (D-25) --
     * not even for its author, who could otherwise route around the gate.
     */
    public function test_an_unapproved_entry_cannot_be_taught_from(): void
    {
        $pending = BankProblem::factory()->create([
            'course_id' => $this->course->id,
            'author_id' => $this->instructor->id,
            'status' => BankProblemStatus::Pending->value,
        ]);

        $this->actingAs($this->instructor)
            ->postJson("/api/v1/sections/{$this->section->id}/problems/clone", [
                'bank_problem_id' => $pending->id,
            ])
            ->assertStatus(422);
    }

    public function test_publishing_another_courses_problem_is_refused(): void
    {
        $elsewhere = $this->courseIn($this->organization, ['require_bank_approval' => false]);
        $problem = $this->problemWithTestCases();

        $this->actingAs($this->instructor)
            ->postJson("/api/v1/courses/{$elsewhere->id}/bank/publish", ['problem_id' => $problem->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('problem_id');
    }

    private function bankEntry(): BankProblem
    {
        return BankProblem::factory()->create([
            'course_id' => $this->course->id,
            'author_id' => $this->instructor->id,
            'name' => 'Tổng hai số',
            'version' => 1,
            'original_id' => null,
            'status' => BankProblemStatus::Approved->value,
        ]);
    }

    private function problemWithTestCases(): Problem
    {
        $problem = Problem::factory()->for($this->section)->create(['name' => 'Tổng hai số']);
        $problem->programmingLanguages()->attach($this->language);

        foreach ([['1 2', '3', 1], ['4 5', '9', 2]] as [$input, $output, $order]) {
            TestCaseModel::query()->create([
                'problem_id' => $problem->id,
                'input' => $input,
                'expected_output' => $output,
                'is_active' => true,
                'is_visible' => true,
                'order' => $order,
            ]);
        }

        return $problem;
    }
}
