<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\Problem;
use App\Models\User;
use App\Notifications\TestCasesUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/** B12: the bell icon. */
class NotificationTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private User $user;

    private Problem $problem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $section = $this->sectionIn($this->semesterIn($this->courseIn($this->organizationWithAdmin())));
        $this->problem = Problem::factory()->for($section)->create(['name' => 'Tổng hai số']);
    }

    public function test_it_lists_the_callers_notifications_with_an_unread_count(): void
    {
        $this->notify(2);

        $this->actingAs($this->user)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('unread_count', 2)
            ->assertJsonStructure(['data', 'meta' => ['has_more'], 'unread_count']);
    }

    /**
     * Laravel stores the notification class in `type`; openapi declares a
     * dotted enum. The mapping is the contract.
     */
    public function test_the_type_is_the_contracts_spelling_not_the_class_name(): void
    {
        $this->notify(1);

        $this->actingAs($this->user)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'problem.test_cases_updated')
            ->assertJsonPath('data.0.data.problem_name', 'Tổng hai số');
    }

    public function test_nobody_reads_anyone_elses(): void
    {
        $this->notify(1);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('unread_count', 0);
    }

    public function test_unread_only_narrows_the_listing(): void
    {
        $this->notify(2);
        $this->user->unreadNotifications()->limit(1)->update(['read_at' => now()]);

        $this->actingAs($this->user)->getJson('/api/v1/notifications?unread_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('unread_count', 1);
    }

    public function test_named_ids_are_marked_read(): void
    {
        $this->notify(2);
        $first = (string) $this->user->unreadNotifications()->first()?->id;

        $this->actingAs($this->user)
            ->postJson('/api/v1/notifications/mark-read', ['ids' => [$first]])
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->assertNotNull($this->user->notifications()->find($first)?->read_at);
    }

    /** openapi: "Empty = mark all". The button that clears the bell. */
    public function test_an_empty_list_marks_everything_read(): void
    {
        $this->notify(3);

        $this->actingAs($this->user)
            ->postJson('/api/v1/notifications/mark-read', ['ids' => []])
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $this->user->unreadNotifications()->count());
    }

    public function test_marking_cannot_reach_another_persons_notifications(): void
    {
        $this->notify(1);
        $mine = (string) $this->user->unreadNotifications()->first()?->id;

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/notifications/mark-read', ['ids' => [$mine]])
            ->assertOk();

        $this->assertNull($this->user->notifications()->find($mine)?->read_at);
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    private function notify(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->user->notify(new TestCasesUpdated($this->problem));
        }
    }
}
