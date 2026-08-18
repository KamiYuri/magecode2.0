<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AnalysisStatus;
use App\Models\AnalysisProblem;
use App\Services\Analysis\AnalysisCompletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * D-82: a batch nobody answers for has to end anyway.
 *
 * Polling rather than a per-batch timer, because the failures this catches are
 * exactly the ones a timer would not survive — a worker that died mid-job, a
 * result message lost to a dead-letter queue, an api restart that took the SIM
 * completion set with it.
 *
 * Per-submission rows are deliberately untouched (D-59): a submission whose
 * results arrived keeps them and keeps its own status, so a batch that timed
 * out with 80 of 100 answers still shows those 80.
 */
class SweepAnalysisTimeouts extends Command
{
    protected $signature = 'analysis:sweep-timeouts';

    protected $description = 'Mark analysis batches that have been processing too long as timed out (D-82)';

    /** D-82: 30 minutes to the verdict, 15 to the first complaint in the log. */
    private const TIMEOUT_MINUTES = 30;

    private const WARN_MINUTES = 15;

    public function handle(AnalysisCompletionService $completion): int
    {
        $this->warnAboutSlowBatches();

        $expired = AnalysisProblem::query()
            ->where('status', AnalysisStatus::Processing->value)
            ->where('started_at', '<=', now()->subMinutes(self::TIMEOUT_MINUTES))
            ->get();

        foreach ($expired as $batch) {
            // Through the same conditional close D6 uses, so a batch that
            // finished between the query and this line is left alone rather
            // than being overwritten with a timeout it did not have.
            if (! $completion->close($batch, AnalysisStatus::Timeout)) {
                continue;
            }

            Log::warning('analysis batch timed out', [
                'analysis_problem_id' => $batch->id,
                'started_at' => $batch->started_at?->toIso8601String(),
                'minutes' => self::TIMEOUT_MINUTES,
            ]);
        }

        $this->info("swept {$expired->count()} batch(es)");

        return self::SUCCESS;
    }

    /**
     * The half-way complaint D-82 asks for: loud enough to find in Loki before
     * anyone loses results, quiet in the sense that it changes nothing.
     */
    private function warnAboutSlowBatches(): void
    {
        $slow = AnalysisProblem::query()
            ->where('status', AnalysisStatus::Processing->value)
            ->whereBetween('started_at', [
                now()->subMinutes(self::TIMEOUT_MINUTES),
                now()->subMinutes(self::WARN_MINUTES),
            ])
            ->pluck('id');

        foreach ($slow as $id) {
            Log::warning('analysis batch is still processing after 15 minutes', [
                'analysis_problem_id' => $id,
            ]);
        }
    }
}
