<?php

declare(strict_types=1);

namespace Tests\Feature\Tags;

use App\Enums\OrganizationRole;
use App\Models\Course;
use App\Models\Organization;
use App\Models\Problem;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * B9: tags are course-scoped metadata (D-15).
 *
 * The scoping is the whole point of the task -- two courses may each have a
 * "Đệ quy" and they are different tags, while one course may not have two.
 */
class TagCrudTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Organization $organization;

    private Course $course;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->organization = $this->organizationWithAdmin($this->admin);
        $this->course = $this->courseIn($this->organization);
    }

    public function test_staff_create_a_tag(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/courses/{$this->course->id}/tags", [
                'name' => 'Đệ quy',
                'color' => '#ff8800',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Đệ quy')
            ->assertJsonPath('data.color', '#ff8800')
            ->assertJsonPath('data.course_id', $this->course->id);

        $this->assertDatabaseHas('tags', ['course_id' => $this->course->id, 'name' => 'Đệ quy']);
    }

    public function test_the_listing_is_scoped_to_its_course(): void
    {
        Tag::factory()->for($this->course)->create(['name' => 'Đệ quy']);
        Tag::factory()->for($this->courseIn($this->organization))->create(['name' => 'Quy hoạch động']);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/courses/{$this->course->id}/tags")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Đệ quy');
    }

    public function test_a_duplicate_name_in_the_same_course_is_a_conflict(): void
    {
        Tag::factory()->for($this->course)->create(['name' => 'Đệ quy']);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/courses/{$this->course->id}/tags", ['name' => 'Đệ quy'])
            ->assertConflict()
            ->assertJsonPath('code', 'DUPLICATE_TAG_NAME');
    }

    /** D-15's scoping, from the other side: the same name in another course is fine. */
    public function test_the_same_name_in_another_course_is_allowed(): void
    {
        Tag::factory()->for($this->course)->create(['name' => 'Đệ quy']);
        $other = $this->courseIn($this->organization);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/courses/{$other->id}/tags", ['name' => 'Đệ quy'])
            ->assertCreated();
    }

    public function test_staff_rename_a_tag(): void
    {
        $tag = Tag::factory()->for($this->course)->create(['name' => 'Đệ quy']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/tags/{$tag->id}", ['name' => 'Đệ quy nâng cao', 'color' => '#112233'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Đệ quy nâng cao');
    }

    public function test_renaming_onto_a_sibling_is_a_conflict(): void
    {
        Tag::factory()->for($this->course)->create(['name' => 'Đệ quy']);
        $tag = Tag::factory()->for($this->course)->create(['name' => 'Quy hoạch động']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/tags/{$tag->id}", ['name' => 'Đệ quy'])
            ->assertConflict()
            ->assertJsonPath('code', 'DUPLICATE_TAG_NAME');
    }

    /** Renaming a tag to what it already is must not collide with itself. */
    public function test_a_tag_may_keep_its_own_name(): void
    {
        $tag = Tag::factory()->for($this->course)->create(['name' => 'Đệ quy']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/tags/{$tag->id}", ['name' => 'Đệ quy', 'color' => '#000000'])
            ->assertOk()
            ->assertJsonPath('data.color', '#000000');
    }

    public function test_staff_delete_a_tag(): void
    {
        $tag = Tag::factory()->for($this->course)->create();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/tags/{$tag->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    /**
     * The pivot goes with it. `problem_tags` is the attachment, not the
     * problem -- deleting a tag must not take a problem with it, and must not
     * be refused because someone used it.
     */
    public function test_deleting_a_tag_detaches_it_and_leaves_the_problem(): void
    {
        $tag = Tag::factory()->for($this->course)->create();
        $section = $this->sectionIn($this->semesterIn($this->course));
        $problem = Problem::factory()->for($section)->create();
        $problem->tags()->attach($tag);

        $this->actingAs($this->admin)->deleteJson("/api/v1/tags/{$tag->id}")->assertNoContent();

        $this->assertDatabaseMissing('problem_tags', ['tag_id' => $tag->id]);
        $this->assertDatabaseHas('problems', ['id' => $problem->id]);
    }

    public function test_someone_outside_the_organization_is_refused(): void
    {
        $outsider = User::factory()->create();
        $tag = Tag::factory()->for($this->course)->create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/courses/{$this->course->id}/tags")
            ->assertForbidden();

        $this->actingAs($outsider)
            ->putJson("/api/v1/tags/{$tag->id}", ['name' => 'nope'])
            ->assertForbidden();
    }

    /** D-15: any organization staff curate the vocabulary, not only admins. */
    public function test_an_instructor_of_the_organization_may_curate(): void
    {
        $instructor = $this->organizationMember($this->organization, OrganizationRole::Instructor);

        $this->actingAs($instructor)
            ->postJson("/api/v1/courses/{$this->course->id}/tags", ['name' => 'Đồ thị'])
            ->assertCreated();
    }

    public function test_a_guest_is_refused(): void
    {
        $this->postJson("/api/v1/courses/{$this->course->id}/tags", ['name' => 'x'])
            ->assertUnauthorized();
    }

    public function test_the_name_is_required_and_bounded(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/courses/{$this->course->id}/tags", ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/courses/{$this->course->id}/tags", ['name' => str_repeat('a', 51)])
            ->assertStatus(422);
    }
}
