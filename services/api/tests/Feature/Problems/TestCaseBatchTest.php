<?php

declare(strict_types=1);

namespace Tests\Feature\Problems;

use App\Enums\SectionRole;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Submission;
use App\Models\TestCase as TestCaseModel;
use App\Models\User;
use App\Notifications\TestCasesUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * The batch endpoint is the only way test cases move. Its side effects are the
 * point: work already graded under the old set is flagged outdated and its
 * authors are told (D-41), and the 50-case / 1MB ceiling holds (D-45).
 */
class TestCaseBatchTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Section $section;

    private Problem $problem;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->section = $this->sectionIn($this->semesterIn($this->courseIn($this->organizationWithAdmin())));
        $this->problem = Problem::factory()->for($this->section)->create();
        $this->instructor = $this->sectionMember($this->section, SectionRole::Instructor);
        $this->student = $this->sectionMember($this->section, SectionRole::Student);
    }

    public function test_staff_list_every_test_case_in_order(): void
    {
        TestCaseModel::factory()->for($this->problem)->create(['order' => 2, 'input' => 'second']);
        TestCaseModel::factory()->for($this->problem)->create(['order' => 1, 'input' => 'first']);
        Sanctum::actingAs($this->instructor);

        $response = $this->getJson("/api/v1/problems/{$this->problem->id}/test-cases")
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'input', 'expected_output', 'is_active', 'is_visible', 'order']]]);

        $this->assertSame(['first', 'second'], $response->json('data.*.input'));
        $this->assertArrayNotHasKey('meta', $response->json());
    }

    public function test_a_ta_may_read_test_cases_but_a_student_may_not(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Ta));
        $this->getJson("/api/v1/problems/{$this->problem->id}/test-cases")->assertOk();

        Sanctum::actingAs($this->student);
        $this->getJson("/api/v1/problems/{$this->problem->id}/test-cases")->assertForbidden();
    }

    public function test_the_batch_creates_updates_and_deletes_in_one_call(): void
    {
        $kept = TestCaseModel::factory()->for($this->problem)->create(['input' => 'old', 'order' => 0]);
        $removed = TestCaseModel::factory()->for($this->problem)->create(['order' => 1]);
        Sanctum::actingAs($this->instructor);

        $response = $this->putJson("/api/v1/problems/{$this->problem->id}/test-cases", [
            'test_cases' => [
                ['id' => $kept->id, 'input' => 'new', 'expected_output' => 'out', 'order' => 0],
                ['id' => $removed->id, '_delete' => true],
                ['input' => 'added', 'expected_output' => 'out', 'is_visible' => true, 'order' => 1],
            ],
        ])->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame('new', $kept->fresh()?->input);
        $this->assertDatabaseMissing('test_cases', ['id' => $removed->id]);
        $this->assertDatabaseHas('test_cases', ['problem_id' => $this->problem->id, 'input' => 'added']);
    }

    public function test_the_batch_stamps_testcases_updated_at(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$this->problem->id}/test-cases", [
            'test_cases' => [['input' => '1', 'expected_output' => '1']],
        ])->assertOk();

        $this->assertNotNull($this->problem->fresh()?->testcases_updated_at);
    }

    public function test_existing_submissions_are_flagged_and_their_authors_notified(): void
    {
        Notification::fake();
        $mine = Submission::factory()->for($this->problem)->create(['creator_id' => $this->student->id]);
        $classmate = $this->sectionMember($this->section, SectionRole::Student);
        Submission::factory()->for($this->problem)->create(['creator_id' => $classmate->id]);
        Submission::factory()->for($this->problem)->create(['creator_id' => $classmate->id]);
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$this->problem->id}/test-cases", [
            'test_cases' => [['input' => '1', 'expected_output' => '1']],
        ])
            ->assertOk()
            ->assertJsonPath('meta.outdated_submissions_count', 3)
            // Two students submitted three times between them; nobody is told twice.
            ->assertJsonPath('meta.notifications_sent', 2);

        $this->assertTrue($mine->fresh()?->is_outdated);
        Notification::assertSentTo([$this->student, $classmate], TestCasesUpdated::class);
    }

    public function test_a_problem_without_submissions_notifies_nobody(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$this->problem->id}/test-cases", [
            'test_cases' => [['input' => '1', 'expected_output' => '1']],
        ])
            ->assertOk()
            ->assertJsonPath('meta.outdated_submissions_count', 0)
            ->assertJsonPath('meta.notifications_sent', 0);

        Notification::assertNothingSent();
    }

    public function test_the_batch_is_refused_while_the_problem_is_locked(): void
    {
        $this->problem->update(['lock_time' => now()->subHour()]);
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$this->problem->id}/test-cases", [
            'test_cases' => [['input' => '1', 'expected_output' => '1']],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROBLEM_LOCKED');

        $this->assertDatabaseCount('test_cases', 0);
    }

    public function test_the_batch_rejects_more_than_fifty_surviving_test_cases(): void
    {
        TestCaseModel::factory()->for($this->problem)->count(50)->create();
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$this->problem->id}/test-cases", [
            'test_cases' => [['input' => 'fifty-first', 'expected_output' => 'x']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['test_cases']);

        $this->assertDatabaseCount('test_cases', 50);
    }

    public function test_deletions_count_against_the_ceiling_before_additions(): void
    {
        // Swapping one case for another must be allowed at exactly 50.
        $existing = TestCaseModel::factory()->for($this->problem)->count(50)->create();
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$this->problem->id}/test-cases", [
            'test_cases' => [
                ['id' => $existing->first()?->id, '_delete' => true],
                ['input' => 'replacement', 'expected_output' => 'x'],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('test_cases', 50);
    }

    public function test_a_field_larger_than_one_megabyte_is_rejected(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$this->problem->id}/test-cases", [
            'test_cases' => [['input' => str_repeat('x', 1_048_577), 'expected_output' => 'x']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['test_cases.0.input']);
    }

    public function test_a_test_case_from_another_problem_is_rejected(): void
    {
        $foreign = TestCaseModel::factory()->for(Problem::factory()->for($this->section))->create();
        Sanctum::actingAs($this->instructor);

        $this->putJson("/api/v1/problems/{$this->problem->id}/test-cases", [
            'test_cases' => [['id' => $foreign->id, 'input' => 'hijack', 'expected_output' => 'x']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['test_cases.0.id']);
    }

    public function test_a_ta_may_not_change_test_cases(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Ta));

        $this->putJson("/api/v1/problems/{$this->problem->id}/test-cases", [
            'test_cases' => [['input' => '1', 'expected_output' => '1']],
        ])->assertForbidden();
    }
}
