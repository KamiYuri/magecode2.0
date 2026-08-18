<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\AnalysisStatus;
use App\Enums\ServiceStatus;
use App\Events\AnalysisCompleted;
use App\Events\AnalysisProgress;
use App\Models\AnalysisProblem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Decides when a submission and then a whole batch is finished, and pushes the
 * two frames the section is waiting for (D-55, U-8).
 *
 * Completion is read from the `analysis_submissions` rows, not from the SIM
 * completion set U-9 keeps in the cache. The rows already carry the answer —
 * a group whose message never arrived leaves its submissions `in_queue` — and
 * a cache key lost to a restart must never hold a finished batch open
 * (v3 §7, 2026-08-18). The set stays a cheap signal and is cleared here.
 */
class AnalysisCompletionService
{
    /**
     * Stamp whatever has finished, announce it, and close the batch if nothing
     * is left. Safe to call after any write that could have finished
     * something, including one that finished nothing.
     */
    public function settle(AnalysisProblem $batch): void
    {
        $sections = $this->sectionsInScope($batch);
        $stamped = $this->stampFinishedSubmissions($batch);

        if ($stamped > 0) {
            AnalysisProgress::dispatch(
                $batch->id,
                $this->completedCount($batch),
                $this->totalCount($batch),
                $sections,
            );
        }

        if ($this->hasUnfinishedSubmissions($batch)) {
            return;
        }

        $this->close($batch, AnalysisStatus::Completed, $sections);
    }

    /**
     * Move a batch to a terminal status once, and announce it once.
     *
     * The status is part of the WHERE, so two consumers finishing the last two
     * submissions at the same instant both see "all done" but only one update
     * changes a row — and only that one broadcasts. D7's sweeper closes
     * batches through the same door.
     *
     * @param  list<int>  $sections
     */
    public function close(AnalysisProblem $batch, AnalysisStatus $status, ?array $sections = null): bool
    {
        $closed = AnalysisProblem::query()
            ->whereKey($batch->id)
            ->where('status', AnalysisStatus::Processing->value)
            ->update(['status' => $status->value, 'completed_at' => now(), 'updated_at' => now()]);

        if ($closed === 0) {
            return false;
        }

        // The batch is over, so the SIM group set has nothing left to say.
        Cache::forget(SimResultIngestor::COMPLETION_KEY.$batch->id);

        AnalysisCompleted::dispatch($batch->id, $status, $sections ?? $this->sectionsInScope($batch));

        return true;
    }

    /**
     * `completed_at` marks a submission whose three services have all answered
     * — `not_applicable` counts, since nothing is coming for a service nobody
     * asked for. It is what `AnalysisProblemResource` counts for
     * `progress_percent`, so it must be written the moment it becomes true.
     */
    private function stampFinishedSubmissions(AnalysisProblem $batch): int
    {
        $pending = ServiceStatus::pendingValues();

        return DB::table('analysis_submissions')
            ->where('analysis_problem_id', $batch->id)
            ->whereNull('completed_at')
            ->whereNotIn('plagiarism_status', $pending)
            ->whereNotIn('ai_detection_status', $pending)
            ->whereNotIn('vuln_scan_status', $pending)
            ->update(['completed_at' => now(), 'updated_at' => now()]);
    }

    private function hasUnfinishedSubmissions(AnalysisProblem $batch): bool
    {
        return DB::table('analysis_submissions')
            ->where('analysis_problem_id', $batch->id)
            ->whereNull('completed_at')
            ->exists();
    }

    private function completedCount(AnalysisProblem $batch): int
    {
        return DB::table('analysis_submissions')
            ->where('analysis_problem_id', $batch->id)
            ->whereNotNull('completed_at')
            ->count();
    }

    private function totalCount(AnalysisProblem $batch): int
    {
        return DB::table('analysis_submissions')->where('analysis_problem_id', $batch->id)->count();
    }

    /**
     * Every section the batch reaches. A batch is semester-wide (D-48), so the
     * frame goes to each section whose students are in it — an instructor
     * watching L02 sees the progress of a run somebody in L01 triggered.
     *
     * @return list<int>
     */
    private function sectionsInScope(AnalysisProblem $batch): array
    {
        /** @var list<int> */
        return DB::table('analysis_submissions as entry')
            ->join('submissions as submission', 'submission.id', '=', 'entry.submission_id')
            ->join('problems as problem', 'problem.id', '=', 'submission.problem_id')
            ->where('entry.analysis_problem_id', $batch->id)
            ->distinct()
            ->orderBy('problem.section_id')
            ->pluck('problem.section_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
