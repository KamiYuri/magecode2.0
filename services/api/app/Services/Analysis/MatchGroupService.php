<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\AnalysisStatus;
use App\Exceptions\UnprocessableException;
use App\Models\AnalysisProblem;
use App\Models\Problem;
use App\Models\Semester;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * D-58's manual match groups: problems written independently in different
 * sections, declared equivalent so SIM compares them as one group.
 *
 * The auto path (`bank_problem_id`) needs none of this — cloning from the bank
 * already says the problems are the same. This is for a course whose sections
 * each wrote their own version of the same exercise.
 */
class MatchGroupService
{
    /**
     * Give every named problem the same fresh UUID.
     *
     * @param  list<int>  $problemIds
     * @return array{manual_match_group_id: string, problems_linked: int}
     *
     * @throws UnprocessableException
     */
    public function link(Semester $semester, array $problemIds): array
    {
        $problems = Problem::query()
            ->whereIn('id', $problemIds)
            ->with('section')
            ->get();

        $this->assertLinkable($semester, $problems, $problemIds);

        $groupId = (string) Str::uuid();

        DB::transaction(function () use ($problems, $groupId): void {
            Problem::query()
                ->whereIn('id', $problems->pluck('id'))
                ->update(['manual_match_group_id' => $groupId]);
        });

        return ['manual_match_group_id' => $groupId, 'problems_linked' => $problems->count()];
    }

    /**
     * Every manual group in the semester, with the problems that carry it.
     *
     * A group of one is included: the trigger generates those for a problem
     * with no scope of its own (v3 §7, 2026-08-14), and hiding them would make
     * the listing disagree with what a batch actually covers.
     *
     * @return list<array{manual_match_group_id: string, problems: list<array{problem_id: int, problem_name: string, section_id: int, section_name: string}>}>
     */
    public function listFor(Semester $semester): array
    {
        $problems = Problem::query()
            ->whereNotNull('manual_match_group_id')
            ->whereHas('section', fn ($query) => $query->where('semester_id', $semester->id))
            ->with('section')
            ->orderBy('manual_match_group_id')
            ->orderBy('id')
            ->get();

        return $problems
            ->groupBy('manual_match_group_id')
            ->map(fn (Collection $group, string $groupId): array => [
                'manual_match_group_id' => $groupId,
                'problems' => $group->map(fn (Problem $problem): array => [
                    'problem_id' => $problem->id,
                    'problem_name' => $problem->name,
                    'section_id' => $problem->section_id,
                    'section_name' => $problem->section->name,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Problem>  $problems
     * @param  list<int>  $requestedIds
     *
     * @throws UnprocessableException
     */
    private function assertLinkable(Semester $semester, Collection $problems, array $requestedIds): void
    {
        // A problem that does not exist and one in another semester are the
        // same answer to the caller: it is not theirs to group.
        if ($problems->count() !== count($requestedIds)) {
            throw UnprocessableException::matchGroupOutsideSemester();
        }

        foreach ($problems as $problem) {
            if ($problem->section->semester_id !== $semester->id) {
                throw UnprocessableException::matchGroupOutsideSemester();
            }

            if ($problem->bank_problem_id !== null) {
                throw UnprocessableException::matchGroupBankProblem();
            }
        }

        $this->assertNothingRunning($problems);
    }

    /**
     * A batch reads its scope from these identifiers, so moving a problem into
     * another group mid-run would leave the running batch pointing at a set
     * that no longer matches what it enrolled.
     *
     * @param  Collection<int, Problem>  $problems
     *
     * @throws UnprocessableException
     */
    private function assertNothingRunning(Collection $problems): void
    {
        $groupIds = $problems->pluck('manual_match_group_id')->filter()->unique()->all();

        if ($groupIds === []) {
            return;
        }

        $running = AnalysisProblem::query()
            ->whereIn('manual_match_group_id', $groupIds)
            ->where('status', AnalysisStatus::Processing->value)
            ->exists();

        if ($running) {
            throw UnprocessableException::matchGroupAnalysisInProgress();
        }
    }
}
