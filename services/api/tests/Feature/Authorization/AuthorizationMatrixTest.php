<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\OrganizationRole;
use App\Enums\SectionRole;
use App\Models\AnalysisProblem;
use App\Models\BankProblem;
use App\Models\Course;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Problem;
use App\Models\Section;
use App\Models\SectionMember;
use App\Models\Semester;
use App\Models\Submission;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The authz matrix: every role against every ability on a fixed fixture.
 *
 * The fixture is one organization → course → semester → two sections (L01,
 * L02), so "wrong section" is always expressible. Each case names the actor,
 * the ability, the target and the expected verdict; a wrong verdict fails with
 * the case name, which is what makes this readable as a security document.
 */
class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $world;

    protected function setUp(): void
    {
        parent::setUp();
        $this->world = $this->buildWorld();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function matrix(): array
    {
        // actor, ability, target, expected
        $cases = [
            // ── Organization ───────────────────────────────────────────────
            ['systemAdmin', 'create', 'organization', true],
            ['orgAdmin', 'create', 'organization', false],
            ['instructorL01', 'create', 'organization', false],
            ['student', 'create', 'organization', false],
            ['orgAdmin', 'update', 'organization', true],
            ['instructorL01', 'update', 'organization', false],
            ['orgAdmin', 'view', 'organization', true],
            ['instructorL01', 'view', 'organization', true],
            ['outsider', 'view', 'organization', false],

            // ── Course ─────────────────────────────────────────────────────
            ['orgAdmin', 'create', 'course', true],
            ['instructorL01', 'create', 'course', false],
            ['orgAdmin', 'update', 'course', true],
            ['instructorL01', 'view', 'course', true],
            ['student', 'view', 'course', true],
            ['outsider', 'view', 'course', false],

            // ── Semester (policy fields are Org Admin territory, D-16) ─────
            ['orgAdmin', 'update', 'semester', true],
            ['instructorL01', 'update', 'semester', false],

            // ── Section ────────────────────────────────────────────────────
            ['orgAdmin', 'update', 'section', true],
            ['instructorL01', 'update', 'section', false],
            ['instructorL01', 'view', 'section', true],
            ['instructorL02', 'view', 'section', false],
            ['ta', 'view', 'section', true],
            ['student', 'view', 'section', true],
            ['otherStudent', 'view', 'section', false],

            // ── Problem ────────────────────────────────────────────────────
            ['instructorL01', 'create', 'section', true],
            ['ta', 'create', 'section', false],
            ['student', 'create', 'section', false],
            ['instructorL01', 'update', 'problem', true],
            ['instructorL02', 'update', 'problem', false],
            ['ta', 'update', 'problem', false],
            ['orgAdmin', 'update', 'problem', true],
            ['instructorL01', 'view', 'problem', true],
            ['instructorL02', 'view', 'problem', false],
            ['student', 'view', 'problem', true],
            ['otherStudent', 'view', 'problem', false],
            ['instructorL01', 'viewTestCases', 'problem', true],
            ['ta', 'viewTestCases', 'problem', true],
            ['student', 'viewTestCases', 'problem', false],

            // ── Submission ─────────────────────────────────────────────────
            ['student', 'view', 'ownSubmission', true],
            ['otherStudent', 'view', 'ownSubmission', false],
            ['classmate', 'view', 'ownSubmission', false],
            ['instructorL01', 'view', 'ownSubmission', true],
            ['ta', 'view', 'ownSubmission', true],
            ['instructorL02', 'view', 'ownSubmission', false],
            ['orgAdmin', 'view', 'ownSubmission', true],
            ['student', 'create', 'problem', true],
            ['otherStudent', 'create', 'problem', false],
            ['instructorL01', 'create', 'problem', false],

            // ── Analysis: TA is excluded entirely (openapi roles table) ────
            ['instructorL01', 'create', 'problem.analysis', true],
            ['ta', 'create', 'problem.analysis', false],
            ['student', 'create', 'problem.analysis', false],
            ['orgAdmin', 'create', 'problem.analysis', true],
            ['instructorL02', 'create', 'problem.analysis', false],
            ['instructorL01', 'view', 'analysis', true],
            ['ta', 'view', 'analysis', false],
            ['student', 'view', 'analysis', false],
            ['orgAdmin', 'view', 'analysis', true],

            // ── Problem Bank: no TA, no student (technical-design §3.3) ────
            ['orgAdmin', 'view', 'bankProblem', true],
            ['instructorL01', 'view', 'bankProblem', true],
            ['ta', 'view', 'bankProblem', false],
            ['student', 'view', 'bankProblem', false],
            ['orgAdmin', 'approve', 'bankProblem', true],
            ['instructorL01', 'approve', 'bankProblem', false],
            ['bankAuthor', 'update', 'bankProblem', true],
            ['instructorL01', 'update', 'bankProblem', false],
            ['orgAdmin', 'update', 'bankProblem', true],

            // ── Tags ───────────────────────────────────────────────────────
            ['instructorL01', 'create', 'tag', true],
            ['ta', 'create', 'tag', false],
            ['orgAdmin', 'update', 'tag', true],
            ['instructorL01', 'update', 'tag', true],
            ['student', 'update', 'tag', false],
        ];

        $named = [];
        foreach ($cases as [$actor, $ability, $target, $expected]) {
            $verdict = $expected ? 'may' : 'may not';
            $named["{$actor} {$verdict} {$ability} {$target}"] = [$actor, $ability, $target, $expected];
        }

        return $named;
    }

    #[DataProvider('matrix')]
    public function test_authorization_matrix(string $actor, string $ability, string $target, bool $expected): void
    {
        $user = $this->world[$actor];
        $subject = $this->subjectFor($ability, $target);

        $allowed = Gate::forUser($user)->allows($ability, $subject);

        $this->assertSame(
            $expected,
            $allowed,
            sprintf('%s should %s be able to %s %s', $actor, $expected ? '' : 'NOT', $ability, $target)
        );
    }

    /**
     * `create` abilities take the parent as context, so the matrix can say
     * "instructor of L01 may create a problem in L01" without inventing an
     * unsaved model.
     *
     * @return mixed
     */
    private function subjectFor(string $ability, string $target)
    {
        return match ([$ability, $target]) {
            ['create', 'organization'] => Organization::class,
            ['create', 'course'] => [Course::class, $this->world['organization']],
            ['create', 'section'] => [Problem::class, $this->world['sectionL01']],
            ['create', 'problem'] => [Submission::class, $this->world['problem']],
            ['create', 'problem.analysis'] => [AnalysisProblem::class, $this->world['problem']],
            ['create', 'tag'] => [Tag::class, $this->world['course']],
            default => $this->world[$this->targetKey($target)],
        };
    }

    private function targetKey(string $target): string
    {
        return match ($target) {
            'organization' => 'organization',
            'course' => 'course',
            'semester' => 'semester',
            'section' => 'sectionL01',
            'problem' => 'problem',
            'ownSubmission' => 'submission',
            'analysis' => 'analysisProblem',
            'bankProblem' => 'bankProblem',
            'tag' => 'tag',
            default => $target,
        };
    }

    /** @return array<string, mixed> */
    private function buildWorld(): array
    {
        $systemAdmin = User::factory()->create(['is_system_admin' => true]);
        $orgAdmin = User::factory()->create();
        $instructorL01 = User::factory()->create();
        $instructorL02 = User::factory()->create();
        $ta = User::factory()->create();
        $student = User::factory()->student()->create();
        $classmate = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $bankAuthor = User::factory()->create();
        $outsider = User::factory()->create();

        $organization = Organization::factory()->create(['creator_id' => $orgAdmin->id]);
        $course = Course::factory()->create(['organization_id' => $organization->id, 'creator_id' => $orgAdmin->id]);
        $semester = Semester::factory()->create(['course_id' => $course->id, 'creator_id' => $orgAdmin->id]);
        $sectionL01 = Section::factory()->create(['semester_id' => $semester->id, 'name' => 'L01', 'creator_id' => $orgAdmin->id]);
        $sectionL02 = Section::factory()->create(['semester_id' => $semester->id, 'name' => 'L02', 'creator_id' => $orgAdmin->id]);

        OrganizationMember::factory()->create([
            'organization_id' => $organization->id, 'user_id' => $orgAdmin->id, 'role' => OrganizationRole::Admin,
        ]);
        foreach ([$instructorL01, $instructorL02, $bankAuthor] as $teacher) {
            OrganizationMember::factory()->create([
                'organization_id' => $organization->id, 'user_id' => $teacher->id, 'role' => OrganizationRole::Instructor,
            ]);
        }

        SectionMember::factory()->create(['section_id' => $sectionL01->id, 'user_id' => $instructorL01->id, 'role' => SectionRole::Instructor]);
        SectionMember::factory()->create(['section_id' => $sectionL02->id, 'user_id' => $instructorL02->id, 'role' => SectionRole::Instructor]);
        SectionMember::factory()->create(['section_id' => $sectionL01->id, 'user_id' => $ta->id, 'role' => SectionRole::Ta]);
        SectionMember::factory()->create(['section_id' => $sectionL01->id, 'user_id' => $student->id, 'role' => SectionRole::Student]);
        SectionMember::factory()->create(['section_id' => $sectionL01->id, 'user_id' => $classmate->id, 'role' => SectionRole::Student]);
        SectionMember::factory()->create(['section_id' => $sectionL02->id, 'user_id' => $otherStudent->id, 'role' => SectionRole::Student]);

        $bankProblem = BankProblem::factory()->create(['course_id' => $course->id, 'author_id' => $bankAuthor->id]);
        $problem = Problem::factory()->create(['section_id' => $sectionL01->id, 'bank_problem_id' => $bankProblem->id, 'creator_id' => $instructorL01->id]);
        $submission = Submission::factory()->create(['problem_id' => $problem->id, 'creator_id' => $student->id]);
        $analysisProblem = AnalysisProblem::factory()->create([
            'semester_id' => $semester->id,
            'bank_problem_id' => $bankProblem->id,
            'triggered_by_problem_id' => $problem->id,
            'analyst_id' => $instructorL01->id,
        ]);
        $tag = Tag::factory()->create(['course_id' => $course->id]);

        return compact(
            'systemAdmin', 'orgAdmin', 'instructorL01', 'instructorL02', 'ta', 'student',
            'classmate', 'otherStudent', 'bankAuthor', 'outsider',
            'organization', 'course', 'semester', 'sectionL01', 'sectionL02',
            'bankProblem', 'problem', 'submission', 'analysisProblem', 'tag',
        );
    }
}
