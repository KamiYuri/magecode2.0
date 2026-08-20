<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ExecutionStatus;
use App\Events\ExecutionCompleted;
use App\Models\Submission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The execution half of D-82's idea: a submission nobody answers for has to
 * end anyway (v3 §7, 2026-08-20).
 *
 * Both unfinished states were terminal in practice. A grading job whose three
 * retries are spent dead-letters and leaves its row at `processing`; a publish
 * the broker refuses is swallowed by C3 -- on purpose, the submission is
 * already durable -- and leaves it at `in_queue`. Neither had anything that
 * would ever move it again, so the student's verdict strip waits for an
 * `execution.completed` that is not coming.
 *
 * Polling rather than a per-submission timer, for D-82's reason: the failures
 * this catches are the ones a timer would not survive either -- a worker that
 * died mid-job, a message lost to a dead-letter queue, an api restart.
 *
 * Counters are left alone (D-59's spirit): a run that graded two of five test
 * cases before dying still shows those two.
 */
class SweepExecutionTimeouts extends Command
{
    protected $signature = 'submissions:sweep-timeouts';

    protected $description = 'Mark submissions that have been unfinished too long as timed out (v3 §7)';

    /**
     * Longer than D-82's thirty on purpose. A problem may hold 50 test cases
     * (D-45), each with a time limit of up to 30s (openapi), so 25 minutes of
     * legitimate execution is reachable before Judge0's own queueing -- and a
     * sweeper that contradicts a run still in progress is worse than one that
     * is late.
     */
    private const TIMEOUT_MINUTES = 60;

    private const WARN_MINUTES = 30;

    public function handle(): int
    {
        $this->warnAboutSlowSubmissions();

        $expired = Submission::query()
            ->whereIn('execution_status', ExecutionStatus::unfinishedValues())
            ->where('updated_at', '<=', now()->subMinutes(self::TIMEOUT_MINUTES))
            ->get();

        $swept = 0;

        foreach ($expired as $submission) {
            if (! $this->timeOut($submission)) {
                continue;
            }

            $swept++;

            Log::warning('submission timed out', [
                'submission_id' => $submission->id,
                'was' => $submission->execution_status->value,
                'stuck_since' => $submission->updated_at?->toIso8601String(),
                'minutes' => self::TIMEOUT_MINUTES,
            ]);

            // The frame the strip is waiting for. Without it the row is
            // correct and the browser still spins.
            ExecutionCompleted::dispatch(
                $submission->id,
                ExecutionStatus::Timeout->value,
                $submission->testcases_passed,
                $submission->testcases_total,
            );
        }

        $this->info("swept {$swept} submission(s)");

        return self::SUCCESS;
    }

    /**
     * D7's conditional close, in another table: the update only lands while
     * the row is still unfinished, so a run that reached its verdict between
     * the query above and this line keeps it instead of being overwritten
     * with a timeout it never had.
     */
    private function timeOut(Submission $submission): bool
    {
        return Submission::query()
            ->whereKey($submission->id)
            ->whereIn('execution_status', ExecutionStatus::unfinishedValues())
            ->update([
                'execution_status' => ExecutionStatus::Timeout->value,
                'updated_at' => now(),
            ]) > 0;
    }

    /**
     * The half-way complaint, mirroring D-82's: loud enough to find in Loki
     * before anyone loses a result, and it changes nothing.
     */
    private function warnAboutSlowSubmissions(): void
    {
        $slow = Submission::query()
            ->whereIn('execution_status', ExecutionStatus::unfinishedValues())
            ->whereBetween('updated_at', [
                now()->subMinutes(self::TIMEOUT_MINUTES),
                now()->subMinutes(self::WARN_MINUTES),
            ])
            ->pluck('id');

        foreach ($slow as $id) {
            Log::warning('submission is still unfinished after 30 minutes', [
                'submission_id' => $id,
            ]);
        }
    }
}
