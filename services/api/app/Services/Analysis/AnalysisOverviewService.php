<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Models\Semester;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The semester-level dashboard: how much of the semester has been analysed and
 * how much of it is flagged.
 *
 * Counted over the **latest** batch of each scope only. Re-running an analysis
 * replaces its predecessor (D-53), so counting every batch ever run would
 * inflate the numbers by exactly the number of re-runs.
 *
 * `flagged` uses the semester's own thresholds (D-62), the same bar the row
 * resources apply, so a total here always matches what a reader would count by
 * hand on the detail pages.
 */
class AnalysisOverviewService
{
    /** @return array<string, mixed> */
    public function build(Semester $semester): array
    {
        $batchIds = DB::table('analysis_problems')
            ->where('semester_id', $semester->id)
            ->where('is_latest', true)
            ->pluck('id');

        $similarityThreshold = (float) $semester->similarity_threshold;
        $aiThreshold = (float) $semester->ai_detection_threshold;

        return [
            'semester_id' => $semester->id,
            'total_problems' => $this->totalProblems($semester),
            'analyzed_problems' => $this->analysedProblems($batchIds->all()),
            'total_submissions_analyzed' => DB::table('analysis_submissions')
                ->whereIn('analysis_problem_id', $batchIds)->count(),
            'similarity_flagged_count' => DB::table('similarity_results')
                ->whereIn('analysis_problem_id', $batchIds)
                ->where('similarity', '>=', $similarityThreshold)
                ->count(),
            'ai_detection_flagged_count' => $this->aiFlagged($batchIds->all(), $aiThreshold),
            'vulnerability_count' => DB::table('vulnerability_results')
                ->whereIn('analysis_submission_id', $this->entryIds($batchIds->all()))
                ->count(),
            'per_section' => $this->perSection($semester, $batchIds->all(), $similarityThreshold, $aiThreshold),
        ];
    }

    private function totalProblems(Semester $semester): int
    {
        return DB::table('problems')
            ->join('sections', 'sections.id', '=', 'problems.section_id')
            ->where('sections.semester_id', $semester->id)
            ->whereNull('problems.deleted_at')
            ->count();
    }

    /**
     * Problems covered by a current batch — the scope's problems, not just the
     * one that was clicked, since a batch analyses every equivalent problem.
     *
     * @param  list<int>  $batchIds
     */
    private function analysedProblems(array $batchIds): int
    {
        if ($batchIds === []) {
            return 0;
        }

        return DB::table('analysis_submissions as entry')
            ->join('submissions as submission', 'submission.id', '=', 'entry.submission_id')
            ->whereIn('entry.analysis_problem_id', $batchIds)
            ->distinct()
            ->count('submission.problem_id');
    }

    /** @param list<int> $batchIds */
    private function aiFlagged(array $batchIds, float $threshold): int
    {
        return DB::table('ai_detection_results')
            ->whereIn('analysis_submission_id', $this->entryIds($batchIds))
            ->where('probability', '>=', $threshold)
            ->count();
    }

    /**
     * @param  list<int>  $batchIds
     * @return Collection<int, int>
     */
    private function entryIds(array $batchIds): Collection
    {
        /** @var Collection<int, int> */
        return DB::table('analysis_submissions')->whereIn('analysis_problem_id', $batchIds)->pluck('id');
    }

    /**
     * @param  list<int>  $batchIds
     * @return list<array<string, mixed>>
     */
    private function perSection(Semester $semester, array $batchIds, float $similarity, float $ai): array
    {
        $sections = DB::table('sections')->where('semester_id', $semester->id)->orderBy('id')->get(['id', 'name']);

        return $sections->map(function (object $section) use ($batchIds, $similarity, $ai): array {
            $entryIds = DB::table('analysis_submissions as entry')
                ->join('submissions as submission', 'submission.id', '=', 'entry.submission_id')
                ->join('problems as problem', 'problem.id', '=', 'submission.problem_id')
                ->whereIn('entry.analysis_problem_id', $batchIds)
                ->where('problem.section_id', $section->id)
                ->pluck('entry.id');

            $submissionIds = DB::table('analysis_submissions')->whereIn('id', $entryIds)->pluck('submission_id');

            return [
                'section_id' => (int) $section->id,
                'section_name' => (string) $section->name,
                'analyzed_count' => $entryIds->count(),
                // A pair is counted for a section when either side sits in it,
                // which is what makes a cross-section match visible to both.
                'sim_flagged' => DB::table('similarity_results')
                    ->whereIn('analysis_problem_id', $batchIds)
                    ->where('similarity', '>=', $similarity)
                    ->where(fn ($query) => $query
                        ->whereIn('submission_a_id', $submissionIds)
                        ->orWhereIn('submission_b_id', $submissionIds))
                    ->count(),
                'aid_flagged' => DB::table('ai_detection_results')
                    ->whereIn('analysis_submission_id', $entryIds)
                    ->where('probability', '>=', $ai)
                    ->count(),
            ];
        })->all();
    }
}
