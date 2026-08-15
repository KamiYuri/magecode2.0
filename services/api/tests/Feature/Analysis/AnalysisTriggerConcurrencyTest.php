<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use App\Enums\AnalysisService;
use App\Enums\PublishMode;
use App\Enums\SectionRole;
use App\Exceptions\ApiException;
use App\Models\AnalysisProblem;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use App\Services\Analysis\AnalysisTriggerService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * D-53: exactly one `is_latest` batch per scope, even when two instructors
 * press the button at the same moment.
 *
 * Real processes, like C2's quota race: the guarantee lives in the partial
 * unique indexes B2 created, so a test that never opens a second connection
 * would prove nothing. That rules out RefreshDatabase — its wrapping
 * transaction is invisible to another connection.
 */
class AnalysisTriggerConcurrencyTest extends TestCase
{
    use CreatesAcademicFixtures;
    use DatabaseTruncation;

    private const CHILD_CREATED = 0;

    private const CHILD_REFUSED = 1;

    private const CHILD_UNEXPECTED = 2;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to trigger from two processes at once.');
        }
    }

    /** Committed rows would otherwise survive into the RefreshDatabase suites. */
    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function test_two_parallel_triggers_create_exactly_one_batch(): void
    {
        [$problem, $instructor] = $this->scopeWithSubmissions();

        $outcomes = $this->triggerConcurrently($problem, $instructor, processes: 2);

        $this->assertNotContains(self::CHILD_UNEXPECTED, $outcomes,
            'a lost race must answer 409 or 200, never a server error');
        $this->assertSame(1, count(array_keys($outcomes, self::CHILD_CREATED, true)),
            'exactly one trigger may create a batch');

        $this->assertSame(1, AnalysisProblem::count());
        $this->assertSame(1, AnalysisProblem::where('is_latest', true)->count(),
            'D-53: one latest batch per scope');
    }

    /**
     * The scope is generated on first trigger, so a race here decides which
     * UUID the problem keeps — and both processes must end up agreeing.
     */
    public function test_two_parallel_triggers_agree_on_one_match_group(): void
    {
        [$problem, $instructor] = $this->scopeWithSubmissions();

        $this->triggerConcurrently($problem, $instructor, processes: 2);

        $groupId = $problem->fresh()->manual_match_group_id;

        $this->assertNotNull($groupId);
        $this->assertSame(
            $groupId,
            AnalysisProblem::firstOrFail()->manual_match_group_id,
            'the batch must belong to the group the problem ended up carrying',
        );
    }

    /** @return array{Problem, User} */
    private function scopeWithSubmissions(): array
    {
        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()), [
            'publish_mode' => PublishMode::Auto,
            'lock_mode' => PublishMode::Auto,
        ]);
        $section = $this->sectionIn($semester);
        $instructor = $this->sectionMember($section, SectionRole::Instructor);

        $problem = Problem::factory()->for($section)->create([
            'activation_time' => now()->subMonth(),
            'lock_time' => now()->subDay(),
        ]);

        Submission::factory()->create([
            'problem_id' => $problem->id,
            'creator_id' => $this->sectionMember($section, SectionRole::Student)->id,
        ]);

        return [$problem, $instructor];
    }

    /** @return list<int> */
    private function triggerConcurrently(Problem $problem, User $instructor, int $processes): array
    {
        $pids = [];

        for ($i = 0; $i < $processes; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('could not fork a triggering process');
            }

            if ($pid === 0) {
                exit($this->triggerInChild($problem, $instructor));
            }

            $pids[] = $pid;
        }

        $outcomes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $outcomes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : self::CHILD_UNEXPECTED;
        }

        DB::purge();

        return $outcomes;
    }

    private function triggerInChild(Problem $problem, User $instructor): int
    {
        // A forked process inherits the parent's socket; two processes sharing
        // one connection corrupt the protocol rather than racing.
        DB::purge();

        try {
            app(AnalysisTriggerService::class)->trigger(
                $problem->fresh(),
                $instructor,
                [AnalysisService::PlagiarismChecker],
            );

            return self::CHILD_CREATED;
        } catch (ApiException) {
            // 409 in progress, or 200 results-exist — both are the loser
            // learning the winner already did the work.
            return self::CHILD_REFUSED;
        } catch (\Throwable) {
            return self::CHILD_UNEXPECTED;
        }
    }
}
