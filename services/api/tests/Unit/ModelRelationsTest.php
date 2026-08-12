<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AnalysisStatus;
use App\Enums\BankProblemStatus;
use App\Enums\Difficulty;
use App\Enums\ExecutionStatus;
use App\Enums\MatchType;
use App\Enums\OrganizationRole;
use App\Enums\PublishMode;
use App\Enums\SectionRole;
use App\Enums\ServiceStatus;
use App\Models\AiDetectionResult;
use App\Models\AnalysisProblem;
use App\Models\AnalysisSubmission;
use App\Models\BankProblem;
use App\Models\BankProblemTestCase;
use App\Models\CodeExecutionResult;
use App\Models\Course;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Problem;
use App\Models\ProblemEditLog;
use App\Models\ProgrammingLanguage;
use App\Models\Section;
use App\Models\SectionMember;
use App\Models\SectionTransferLog;
use App\Models\Semester;
use App\Models\SimilarityResult;
use App\Models\Submission;
use App\Models\Tag;
use App\Models\TestCase as TestCaseModel;
use App\Models\User;
use App\Models\VulnerabilityResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke-tests every model's factory and relationships. A model is "done" when
 * its factory produces a persistable row and every relation resolves to the
 * right related record.
 */
class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_relations(): void
    {
        $user = User::factory()->create();
        Organization::factory()->create(['creator_id' => $user->id]);

        $this->assertCount(1, $user->createdOrganizations);
        $this->assertSame([], $user->organizationMemberships->all());
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    public function test_organization_relations(): void
    {
        $organization = Organization::factory()->create();
        Course::factory()->create(['organization_id' => $organization->id]);
        OrganizationMember::factory()->create(['organization_id' => $organization->id]);

        $this->assertInstanceOf(User::class, $organization->creator);
        $this->assertCount(1, $organization->courses);
        $this->assertCount(1, $organization->members);
    }

    public function test_course_relations(): void
    {
        $course = Course::factory()->create();
        Semester::factory()->create(['course_id' => $course->id]);
        BankProblem::factory()->create(['course_id' => $course->id]);
        Tag::factory()->create(['course_id' => $course->id]);

        $this->assertInstanceOf(Organization::class, $course->organization);
        $this->assertInstanceOf(User::class, $course->creator);
        $this->assertCount(1, $course->semesters);
        $this->assertCount(1, $course->bankProblems);
        $this->assertCount(1, $course->tags);
        $this->assertFalse($course->require_bank_approval);
    }

    public function test_semester_relations_and_casts(): void
    {
        $semester = Semester::factory()->create();
        Section::factory()->create(['semester_id' => $semester->id]);

        $this->assertInstanceOf(Course::class, $semester->course);
        $this->assertInstanceOf(User::class, $semester->creator);
        $this->assertCount(1, $semester->sections);
        $this->assertTrue($semester->allow_publish_override);
        $this->assertSame(PublishMode::Auto, $semester->publish_mode);
    }

    public function test_section_relations(): void
    {
        $section = Section::factory()->create();
        Problem::factory()->create(['section_id' => $section->id]);
        SectionMember::factory()->create(['section_id' => $section->id]);

        $this->assertInstanceOf(Semester::class, $section->semester);
        $this->assertInstanceOf(User::class, $section->creator);
        $this->assertCount(1, $section->problems);
        $this->assertCount(1, $section->members);
    }

    public function test_problem_relations(): void
    {
        $problem = Problem::factory()->create();
        TestCaseModel::factory()->create(['problem_id' => $problem->id]);
        Submission::factory()->create(['problem_id' => $problem->id]);
        ProblemEditLog::factory()->create(['problem_id' => $problem->id]);
        $problem->programmingLanguages()->attach(ProgrammingLanguage::factory()->create());
        $problem->tags()->attach(Tag::factory()->create());

        $this->assertInstanceOf(Section::class, $problem->section);
        $this->assertInstanceOf(User::class, $problem->creator);
        $this->assertCount(1, $problem->testCases);
        $this->assertCount(1, $problem->submissions);
        $this->assertCount(1, $problem->editLogs);
        $this->assertCount(1, $problem->programmingLanguages);
        $this->assertCount(1, $problem->tags);
        $this->assertInstanceOf(Difficulty::class, $problem->difficulty);
    }

    public function test_problem_soft_deletes(): void
    {
        $problem = Problem::factory()->create();
        $problem->delete();

        $this->assertSoftDeleted($problem);
        $this->assertCount(0, Problem::all());
        $this->assertCount(1, Problem::withTrashed()->get());
    }

    public function test_bank_problem_relations_and_version_chain(): void
    {
        $original = BankProblem::factory()->create();
        $version = BankProblem::factory()->create(['original_id' => $original->id, 'version' => 2]);
        BankProblemTestCase::factory()->create(['bank_problem_id' => $original->id]);
        $original->programmingLanguages()->attach(ProgrammingLanguage::factory()->create());
        $original->tags()->attach(Tag::factory()->create());

        $this->assertInstanceOf(Course::class, $original->course);
        $this->assertInstanceOf(User::class, $original->author);
        $this->assertCount(1, $original->versions);
        $this->assertTrue($version->original->is($original));
        $this->assertCount(1, $original->testCases);
        $this->assertCount(1, $original->programmingLanguages);
        $this->assertCount(1, $original->tags);
        $this->assertInstanceOf(BankProblemStatus::class, $original->status);
    }

    public function test_bank_problem_soft_deletes(): void
    {
        $bankProblem = BankProblem::factory()->create();
        $bankProblem->delete();

        $this->assertSoftDeleted($bankProblem);
    }

    public function test_test_case_relations(): void
    {
        $testCase = TestCaseModel::factory()->create();

        $this->assertInstanceOf(Problem::class, $testCase->problem);
        $this->assertTrue($testCase->is_active);
        $this->assertFalse($testCase->is_visible);
    }

    public function test_bank_problem_test_case_relations(): void
    {
        $testCase = BankProblemTestCase::factory()->create();

        $this->assertInstanceOf(BankProblem::class, $testCase->bankProblem);
    }

    public function test_programming_language_relations(): void
    {
        $language = ProgrammingLanguage::factory()->create();
        $language->problems()->attach(Problem::factory()->create());
        $language->bankProblems()->attach(BankProblem::factory()->create());

        $this->assertCount(1, $language->problems);
        $this->assertCount(1, $language->bankProblems);
    }

    public function test_submission_relations_and_defaults(): void
    {
        $submission = Submission::factory()->create();
        CodeExecutionResult::factory()->create(['submission_id' => $submission->id]);
        AnalysisSubmission::factory()->create(['submission_id' => $submission->id]);

        $this->assertInstanceOf(Problem::class, $submission->problem);
        $this->assertInstanceOf(User::class, $submission->creator);
        $this->assertInstanceOf(ProgrammingLanguage::class, $submission->programmingLanguage);
        $this->assertCount(1, $submission->executionResults);
        $this->assertCount(1, $submission->analysisSubmissions);
        $this->assertInstanceOf(ExecutionStatus::class, $submission->execution_status);
        $this->assertFalse($submission->is_outdated);
    }

    public function test_code_execution_result_relations(): void
    {
        $result = CodeExecutionResult::factory()->create();

        $this->assertInstanceOf(Submission::class, $result->submission);
        $this->assertInstanceOf(TestCaseModel::class, $result->testCase);
        $this->assertTrue($result->created_at->isToday());
        $this->assertFalse($result->usesTimestamps());
    }

    public function test_analysis_problem_relations(): void
    {
        $analysis = AnalysisProblem::factory()->create();
        AnalysisSubmission::factory()->create(['analysis_problem_id' => $analysis->id]);

        $this->assertInstanceOf(Semester::class, $analysis->semester);
        $this->assertInstanceOf(BankProblem::class, $analysis->bankProblem);
        $this->assertInstanceOf(Problem::class, $analysis->triggeredByProblem);
        $this->assertInstanceOf(User::class, $analysis->analyst);
        $this->assertCount(1, $analysis->analysisSubmissions);
        $this->assertSame(['plagiarism-checker'], $analysis->services);
        $this->assertInstanceOf(AnalysisStatus::class, $analysis->status);
        $this->assertTrue($analysis->is_latest);
    }

    public function test_analysis_submission_relations(): void
    {
        $analysisSubmission = AnalysisSubmission::factory()->create();
        AiDetectionResult::factory()->create(['analysis_submission_id' => $analysisSubmission->id]);
        VulnerabilityResult::factory()->create(['analysis_submission_id' => $analysisSubmission->id]);

        $this->assertInstanceOf(Submission::class, $analysisSubmission->submission);
        $this->assertInstanceOf(AnalysisProblem::class, $analysisSubmission->analysisProblem);
        $this->assertInstanceOf(AiDetectionResult::class, $analysisSubmission->aiDetectionResult);
        $this->assertCount(1, $analysisSubmission->vulnerabilityResults);
        $this->assertInstanceOf(ServiceStatus::class, $analysisSubmission->plagiarism_status);
    }

    public function test_similarity_result_relations_and_pair_ordering(): void
    {
        $result = SimilarityResult::factory()->create();

        $this->assertInstanceOf(AnalysisProblem::class, $result->analysisProblem);
        $this->assertInstanceOf(Submission::class, $result->submissionA);
        $this->assertInstanceOf(Submission::class, $result->submissionB);
        $this->assertLessThan(
            $result->submission_b_id,
            $result->submission_a_id,
            'The factory must respect chk_similarity_pair_order'
        );
        $this->assertInstanceOf(MatchType::class, $result->match_type);
    }

    public function test_ai_detection_result_relations(): void
    {
        $result = AiDetectionResult::factory()->create();

        $this->assertInstanceOf(AnalysisSubmission::class, $result->analysisSubmission);
        $this->assertFalse($result->usesTimestamps());
    }

    public function test_vulnerability_result_relations(): void
    {
        $result = VulnerabilityResult::factory()->create();

        $this->assertInstanceOf(AnalysisSubmission::class, $result->analysisSubmission);
        $this->assertFalse($result->usesTimestamps());
    }

    public function test_organization_member_relations(): void
    {
        $member = OrganizationMember::factory()->create();

        $this->assertInstanceOf(Organization::class, $member->organization);
        $this->assertInstanceOf(User::class, $member->user);
        $this->assertInstanceOf(OrganizationRole::class, $member->role);
        $this->assertFalse($member->usesTimestamps());
    }

    public function test_section_member_relations(): void
    {
        $member = SectionMember::factory()->create();

        $this->assertInstanceOf(Section::class, $member->section);
        $this->assertInstanceOf(User::class, $member->user);
        $this->assertInstanceOf(SectionRole::class, $member->role);
    }

    public function test_tag_relations(): void
    {
        $tag = Tag::factory()->create();
        $tag->problems()->attach(Problem::factory()->create());
        $tag->bankProblems()->attach(BankProblem::factory()->create());

        $this->assertInstanceOf(Course::class, $tag->course);
        $this->assertCount(1, $tag->problems);
        $this->assertCount(1, $tag->bankProblems);
    }

    public function test_problem_edit_log_relations(): void
    {
        $log = ProblemEditLog::factory()->create();

        $this->assertInstanceOf(Problem::class, $log->problem);
        $this->assertInstanceOf(User::class, $log->editor);
        $this->assertTrue($log->edited_at->isToday());
        $this->assertFalse($log->usesTimestamps());
    }

    public function test_section_transfer_log_relations(): void
    {
        $log = SectionTransferLog::factory()->create();

        $this->assertInstanceOf(User::class, $log->user);
        $this->assertInstanceOf(Section::class, $log->fromSection);
        $this->assertInstanceOf(Section::class, $log->toSection);
        $this->assertInstanceOf(User::class, $log->transferredBy);
        $this->assertTrue($log->fromSection->isNot($log->toSection));
    }

    public function test_every_model_has_a_working_factory(): void
    {
        $models = [
            User::class, Organization::class, Course::class, Semester::class, Section::class,
            Problem::class, TestCaseModel::class, Submission::class, ProgrammingLanguage::class,
            BankProblem::class, BankProblemTestCase::class, AnalysisProblem::class,
            AnalysisSubmission::class, CodeExecutionResult::class, SimilarityResult::class,
            AiDetectionResult::class, VulnerabilityResult::class, OrganizationMember::class,
            SectionMember::class, Tag::class, ProblemEditLog::class, SectionTransferLog::class,
        ];

        $this->assertCount(22, $models, 'Schema doc §1.3 defines 22 models');

        foreach ($models as $model) {
            $instance = $model::factory()->create();
            $this->assertTrue($instance->exists, "{$model} factory did not persist");
        }
    }
}
