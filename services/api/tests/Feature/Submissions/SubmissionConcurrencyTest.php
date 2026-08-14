<?php

declare(strict_types=1);

namespace Tests\Feature\Submissions;

use App\Enums\ExecutionStatus;
use App\Enums\PublishMode;
use App\Enums\SectionRole;
use App\Exceptions\UnprocessableException;
use App\Models\Problem;
use App\Models\ProgrammingLanguage;
use App\Models\Submission;
use App\Models\User;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * D-36/D-39: the quota must hold when two requests race.
 *
 * Real processes, not a simulated interleaving — the guarantee lives in
 * Postgres, so a test that never opens a second connection would prove
 * nothing. That rules out RefreshDatabase: its wrapping transaction is
 * invisible to another connection, so the children would find no fixtures.
 */
class SubmissionConcurrencyTest extends TestCase
{
    use CreatesAcademicFixtures;
    use DatabaseTruncation;

    private const CHILD_ACCEPTED = 0;

    private const CHILD_REFUSED = 1;

    private const CHILD_UNEXPECTED = 2;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to submit from two processes at once.');
        }

        Storage::fake('minio');
    }

    /**
     * These rows are committed, not held in a rolled-back transaction, so they
     * would still be there for the next test — and every other suite runs on
     * RefreshDatabase, which begins a transaction over whatever it finds
     * instead of clearing it.
     */
    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function test_two_parallel_submits_at_the_last_slot_admit_exactly_one(): void
    {
        [$problem, $student, $language] = $this->problemWithOneSlotLeft();

        $outcomes = $this->submitConcurrently($problem, $student, $language, processes: 2);

        $this->assertSame(1, count(array_keys($outcomes, self::CHILD_ACCEPTED, true)), 'exactly one submit must win');
        $this->assertSame(1, count(array_keys($outcomes, self::CHILD_REFUSED, true)), 'the loser must get MAX_SUBMISSIONS');
        $this->assertNotContains(self::CHILD_UNEXPECTED, $outcomes, 'no child may fail for another reason');

        $this->assertSame(3, Submission::where('problem_id', $problem->id)->count());
    }

    /** The same race one step earlier: an empty history and a quota of one. */
    public function test_two_parallel_first_submits_at_a_quota_of_one_admit_exactly_one(): void
    {
        [$problem, $student, $language] = $this->problemWithOneSlotLeft(limit: 1, existing: 0);

        $outcomes = $this->submitConcurrently($problem, $student, $language, processes: 2);

        $this->assertSame(1, count(array_keys($outcomes, self::CHILD_ACCEPTED, true)));
        $this->assertSame(1, Submission::where('problem_id', $problem->id)->count());
    }

    /** @return array{Problem, User, ProgrammingLanguage} */
    private function problemWithOneSlotLeft(int $limit = 3, int $existing = 2): array
    {
        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()), [
            'publish_mode' => PublishMode::Auto,
            'lock_mode' => PublishMode::Auto,
        ]);
        $section = $this->sectionIn($semester);
        $student = $this->sectionMember($section, SectionRole::Student);
        $language = ProgrammingLanguage::factory()->create();

        $problem = Problem::factory()->for($section)->create([
            'activation_time' => now()->subDay(),
            'lock_time' => now()->addDay(),
            'max_submissions' => $limit,
        ]);
        $problem->programmingLanguages()->attach($language);

        if ($existing > 0) {
            Submission::factory()->count($existing)->create([
                'problem_id' => $problem->id,
                'creator_id' => $student->id,
                // Graded, or the in-flight gate would refuse before the quota does.
                'execution_status' => ExecutionStatus::Accepted,
            ]);
        }

        return [$problem, $student, $language];
    }

    /**
     * Fork `processes` children that all submit at once, and return their exit
     * codes. Each child reconnects first: a forked process inherits the
     * parent's socket, and two processes talking over one connection corrupt
     * the protocol rather than racing.
     *
     * @return list<int>
     */
    private function submitConcurrently(
        Problem $problem,
        User $student,
        ProgrammingLanguage $language,
        int $processes,
    ): array {
        $pids = [];

        for ($i = 0; $i < $processes; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('could not fork a submitting process');
            }

            if ($pid === 0) {
                exit($this->submitInChild($problem, $student, $language, $i));
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

    private function submitInChild(
        Problem $problem,
        User $student,
        ProgrammingLanguage $language,
        int $index,
    ): int {
        DB::purge();

        try {
            app(SubmissionService::class)->submitSource(
                $problem->fresh(),
                $student,
                $language,
                "print({$index})\n",
            );

            return self::CHILD_ACCEPTED;
        } catch (UnprocessableException) {
            return self::CHILD_REFUSED;
        } catch (\Throwable) {
            return self::CHILD_UNEXPECTED;
        }
    }
}
