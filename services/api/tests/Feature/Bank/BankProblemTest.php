<?php

declare(strict_types=1);

namespace Tests\Feature\Bank;

use App\Enums\BankProblemStatus;
use App\Enums\OrganizationRole;
use App\Models\BankProblem;
use App\Models\Course;
use App\Models\Organization;
use App\Models\ProgrammingLanguage;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\BankProblemApprovalRequired;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/** B10: the course-scoped bank, its approval gate (D-25) and its versions (D-63/64). */
class BankProblemTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Organization $organization;

    private Course $course;

    private User $admin;

    private User $author;

    private ProgrammingLanguage $language;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->organization = $this->organizationWithAdmin($this->admin);
        $this->course = $this->courseIn($this->organization, ['require_bank_approval' => false]);
        $this->author = $this->organizationMember($this->organization, OrganizationRole::Instructor);
        $this->language = ProgrammingLanguage::factory()->create();
    }

    public function test_an_entry_is_created_with_its_languages_tags_and_test_cases(): void
    {
        $tag = Tag::factory()->for($this->course)->create();

        $response = $this->actingAs($this->author)
            ->postJson("/api/v1/courses/{$this->course->id}/bank", $this->payload([
                'tag_ids' => [$tag->id],
                'test_cases' => [
                    ['input' => '1 2', 'expected_output' => '3'],
                    ['input' => '2 3', 'expected_output' => '5'],
                ],
            ]))
            ->assertCreated();

        $response->assertJsonPath('data.name', 'Tổng hai số')
            ->assertJsonPath('data.version', 1)
            // D-63: the first version is its own identity.
            ->assertJsonPath('data.original_id', null)
            ->assertJsonCount(2, 'data.test_cases')
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonCount(1, 'data.programming_languages');
    }

    /** D-25/D-70: a course that requires approval admits drafts and tells its admins. */
    public function test_a_course_requiring_approval_admits_a_pending_entry_and_notifies(): void
    {
        Notification::fake();
        $this->course->update(['require_bank_approval' => true]);

        $this->actingAs($this->author)
            ->postJson("/api/v1/courses/{$this->course->id}/bank", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        Notification::assertSentTo($this->admin, BankProblemApprovalRequired::class);
    }

    /** An approval nobody is required to give is a queue that never drains. */
    public function test_a_course_without_the_gate_approves_on_the_spot(): void
    {
        Notification::fake();

        $this->actingAs($this->author)
            ->postJson("/api/v1/courses/{$this->course->id}/bank", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');

        Notification::assertNothingSent();
    }

    public function test_a_pending_entry_is_hidden_from_other_instructors(): void
    {
        $pending = $this->entry(BankProblemStatus::Pending, $this->author);
        $colleague = $this->organizationMember($this->organization, OrganizationRole::Instructor);

        $this->actingAs($colleague)
            ->getJson("/api/v1/courses/{$this->course->id}/bank")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($colleague)
            ->getJson("/api/v1/bank-problems/{$pending->id}")
            ->assertForbidden();
    }

    public function test_its_author_and_the_admin_still_see_it(): void
    {
        $pending = $this->entry(BankProblemStatus::Pending, $this->author);

        $this->actingAs($this->author)
            ->getJson("/api/v1/courses/{$this->course->id}/bank")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin)
            ->getJson("/api/v1/bank-problems/{$pending->id}")
            ->assertOk();
    }

    public function test_an_admin_approves_and_it_becomes_shared(): void
    {
        $pending = $this->entry(BankProblemStatus::Pending, $this->author);
        $colleague = $this->organizationMember($this->organization, OrganizationRole::Instructor);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/bank-problems/{$pending->id}/approve", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAs($colleague)
            ->getJson("/api/v1/courses/{$this->course->id}/bank")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_an_instructor_may_not_approve_their_own_work(): void
    {
        $pending = $this->entry(BankProblemStatus::Pending, $this->author);

        $this->actingAs($this->author)
            ->patchJson("/api/v1/bank-problems/{$pending->id}/approve", ['action' => 'approve'])
            ->assertForbidden();
    }

    public function test_a_rejection_is_recorded(): void
    {
        $pending = $this->entry(BankProblemStatus::Pending, $this->author);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/bank-problems/{$pending->id}/approve", ['action' => 'reject'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_only_the_author_or_an_admin_edits_an_entry(): void
    {
        $entry = $this->entry(BankProblemStatus::Approved, $this->author);
        $colleague = $this->organizationMember($this->organization, OrganizationRole::Instructor);

        $this->actingAs($colleague)
            ->putJson("/api/v1/bank-problems/{$entry->id}", $this->payload())
            ->assertForbidden();

        $this->actingAs($this->author)
            ->putJson("/api/v1/bank-problems/{$entry->id}", $this->payload(['name' => 'Đổi tên']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Đổi tên');
    }

    /**
     * Soft delete: `problems.bank_problem_id` is a RESTRICT foreign key, so a
     * section still teaching a clone must keep the row its origin points at.
     */
    public function test_deleting_keeps_the_row_for_anything_cloned_from_it(): void
    {
        $entry = $this->entry(BankProblemStatus::Approved, $this->author);

        $this->actingAs($this->author)
            ->deleteJson("/api/v1/bank-problems/{$entry->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('bank_problems', ['id' => $entry->id]);
    }

    public function test_a_student_has_no_access_at_all(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/courses/{$this->course->id}/bank")
            ->assertForbidden();
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson("/api/v1/courses/{$this->course->id}/bank")->assertUnauthorized();
    }

    public function test_the_listing_filters_by_difficulty_and_search(): void
    {
        $this->entry(BankProblemStatus::Approved, $this->author, ['name' => 'Đệ quy', 'difficulty' => 'hard']);
        $this->entry(BankProblemStatus::Approved, $this->author, ['name' => 'Mảng', 'difficulty' => 'easy']);

        $this->actingAs($this->author)
            ->getJson("/api/v1/courses/{$this->course->id}/bank?difficulty=hard")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Đệ quy');

        $this->actingAs($this->author)
            ->getJson("/api/v1/courses/{$this->course->id}/bank?search=M".urlencode('ả'))
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Tổng hai số',
            'description' => 'Cho hai số nguyên, in ra tổng.',
            'difficulty' => 'medium',
            'time_limit' => 1000,
            'memory_limit' => 65536,
            'programming_language_ids' => [$this->language->id],
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function entry(BankProblemStatus $status, User $author, array $attributes = []): BankProblem
    {
        return BankProblem::factory()->create($attributes + [
            'course_id' => $this->course->id,
            'author_id' => $author->id,
            'status' => $status->value,
        ]);
    }
}
