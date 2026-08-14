<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SectionRole;
use App\Models\Problem;
use App\Models\Section;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The read side of submissions: who may appear in a listing, and the three
 * `mode` values openapi declares.
 *
 * `best` and `latest` are one row per student. They are resolved with a
 * `DISTINCT ON` subquery rather than a window function in the outer query,
 * because the outer query still has to be cursor-paginable — and a paginator
 * cannot page over rows it did not order itself.
 */
class SubmissionQueryService
{
    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * A student sees their own work and nothing else, whatever the filters
     * ask for. Staff see the whole problem.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Submission>
     */
    public function forProblem(Problem $problem, User $viewer, array $filters): Builder
    {
        $query = Submission::query()->where('problem_id', $problem->id);

        if ($this->memberships->sectionRole($viewer, $problem->section_id) === SectionRole::Student) {
            // Applied after the caller's filters would have been, so a
            // student_id pointing at someone else narrows to nothing rather
            // than widening to them.
            return $this->finish($query->where('creator_id', $viewer->id), $filters, restrictMode: true);
        }

        return $this->finish($query, $filters, restrictMode: false);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Submission>
     */
    public function forSection(Section $section, array $filters): Builder
    {
        $query = Submission::query()->whereIn(
            'problem_id',
            Problem::query()->select('id')->where('section_id', $section->id)
        );

        return $this->finish($query, $filters, restrictMode: false);
    }

    /**
     * @param  Builder<Submission>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Submission>
     */
    private function finish(Builder $query, array $filters, bool $restrictMode): Builder
    {
        if (isset($filters['problem_id'])) {
            $query->where('problem_id', $filters['problem_id']);
        }

        if (isset($filters['status'])) {
            $query->where('execution_status', $filters['status']);
        }

        if (isset($filters['student_id']) && ! $restrictMode) {
            $query->where('creator_id', $filters['student_id']);
        }

        $mode = $filters['mode'] ?? 'all';

        if ($mode !== 'all') {
            $query->whereIn('id', $this->onePerStudent($query, $mode));
        }

        return $query
            ->with(['creator', 'programmingLanguage'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * The winning submission id per student, under the same filters.
     *
     * `best` ranks by test cases passed and breaks ties with the newer
     * attempt, so a student who matches their own best score later is credited
     * with the attempt they would point at.
     *
     * @param  Builder<Submission>  $filtered
     * @return list<int>
     */
    private function onePerStudent(Builder $filtered, string $mode): array
    {
        $ranking = $mode === 'best'
            ? 'creator_id, testcases_passed DESC, created_at DESC, id DESC'
            : 'creator_id, created_at DESC, id DESC';

        $ids = DB::query()
            ->fromSub(
                $filtered->clone()->getQuery()
                    ->selectRaw('DISTINCT ON (creator_id) id')
                    ->orderByRaw($ranking),
                'winners'
            )
            ->pluck('id');

        /** @var list<int> */
        return $ids->map(fn (mixed $id): int => (int) $id)->all();
    }
}
