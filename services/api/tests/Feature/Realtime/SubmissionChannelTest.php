<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Enums\SectionRole;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * Who may listen on `private-submission.{id}`.
 *
 * v3 §7 (2026-08-14): the creator and nobody else. `openapi.yml` says
 * "Submission creator" and outranks the roadmap's "ownership/staff"; staff
 * still read the same submission through `GET /submissions/{id}`, so this
 * decides who gets a live push, not who may see the work.
 */
class SubmissionChannelTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Section $section;

    private Submission $submission;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()));
        $this->section = $this->sectionIn($semester);
        $this->student = $this->sectionMember($this->section, SectionRole::Student);
        $problem = Problem::factory()->for($this->section)->create();

        $this->submission = Submission::factory()->create([
            'problem_id' => $problem->id,
            'creator_id' => $this->student->id,
        ]);
    }

    public function test_the_creator_may_listen(): void
    {
        Sanctum::actingAs($this->student);

        $this->authorise()->assertOk();
    }

    public function test_a_classmate_may_not_listen(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Student));

        $this->authorise()->assertForbidden();
    }

    /** Narrower than SubmissionPolicy::view on purpose (v3 §7). */
    public function test_section_staff_may_not_listen(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Instructor));

        $this->authorise()->assertForbidden();
    }

    public function test_a_guest_may_not_listen(): void
    {
        $this->authorise()->assertUnauthorized();
    }

    public function test_a_submission_that_does_not_exist_is_refused(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-submission.999999',
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    /** @return TestResponse<Response> */
    private function authorise(): TestResponse
    {
        return $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-submission.'.$this->submission->id,
            'socket_id' => '1234.5678',
        ]);
    }
}
