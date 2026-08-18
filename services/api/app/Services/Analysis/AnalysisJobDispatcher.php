<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\ServiceStatus;
use App\Messaging\JobPublisher;
use App\Messaging\Jobs\PlagiarismCheckerJob;
use App\Messaging\PublishFailed;
use App\Models\AnalysisProblem;
use App\Services\SubmissionStorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only publisher of analysis jobs.
 *
 * Called **after** the trigger's transaction commits and only for a batch that
 * request created: a message naming rows that were rolled back is a job that
 * can never succeed (the reason C3 recorded), and the 200-with-existing branch
 * of D1 re-runs nothing, so it publishes nothing.
 *
 * Two things happen here for every service: the submissions it can serve become
 * messages, and the submissions it cannot are written `not_applicable` — D-55's
 * completion check waits on `in_queue`, so a row nobody will ever answer for
 * has to be closed here or the batch never finishes.
 */
class AnalysisJobDispatcher
{
    /** @var array<string, string> object key → pre-signed URL, for one dispatch */
    private array $signed = [];

    public function __construct(
        private readonly JobPublisher $publisher,
        private readonly SubmissionStorageService $storage,
    ) {}

    public function dispatch(AnalysisProblem $batch): void
    {
        $this->signed = [];

        $this->dispatchPlagiarismChecks($batch);
    }

    /**
     * SIM: one message per language group of two or more (`rabbitmq-schemas.md`
     * §3.2). When SIM was not requested, D1 already wrote `not_applicable`
     * across the batch, so the query below returns nothing and this is a no-op.
     */
    private function dispatchPlagiarismChecks(AnalysisProblem $batch): void
    {
        $plan = SimJobPlan::build($this->plagiarismRefs($batch));

        if ($plan->notApplicableIds !== []) {
            $this->park($plan->notApplicableIds, 'plagiarism_status');
        }

        foreach ($plan->unrecognisedLanguages as $language) {
            // Not a gap in the data but a mis-seeded `programming_languages`
            // row: those submissions are silently never compared, and nothing
            // else in the system would surface it.
            Log::warning('dolos_language is not a language the plagiarism-checker schema declares', [
                'analysis_problem_id' => $batch->id,
                'dolos_language' => $language,
            ]);
        }

        foreach ($plan->groups as $index => $group) {
            $job = PlagiarismCheckerJob::for(
                $batch->id,
                $group->language,
                $index,
                $plan->total(),
                array_map(fn (SimSubmissionRef $ref): array => [
                    'submission_id' => $ref->submissionId,
                    'analysis_submission_id' => $ref->analysisSubmissionId,
                    'file_url' => $this->fileUrl($ref->filePath),
                ], $group->submissions),
            );

            $this->publish($job, $batch, ['language' => $group->language->value]);
        }
    }

    /**
     * The batch's submissions that still wait on SIM, with everything the
     * message needs: both ids, the object key, and the raw `dolos_language`
     * the grouping rule decides on.
     *
     * @return list<SimSubmissionRef>
     */
    private function plagiarismRefs(AnalysisProblem $batch): array
    {
        $rows = DB::table('analysis_submissions as entry')
            ->join('submissions as submission', 'submission.id', '=', 'entry.submission_id')
            ->leftJoin('programming_languages as language', 'language.id', '=', 'submission.programming_language_id')
            ->where('entry.analysis_problem_id', $batch->id)
            ->where('entry.plagiarism_status', ServiceStatus::InQueue->value)
            ->orderBy('entry.id')
            ->get([
                'entry.id as analysis_submission_id',
                'submission.id as submission_id',
                'submission.file_path',
                'language.dolos_language',
            ]);

        return $rows->map(fn (object $row): SimSubmissionRef => new SimSubmissionRef(
            analysisSubmissionId: (int) $row->analysis_submission_id,
            submissionId: (int) $row->submission_id,
            filePath: (string) $row->file_path,
            dolosLanguage: $row->dolos_language === null ? null : (string) $row->dolos_language,
        ))->all();
    }

    /**
     * @param  list<int>  $analysisSubmissionIds
     * @param  'plagiarism_status'|'ai_detection_status'|'vuln_scan_status'  $column
     */
    private function park(array $analysisSubmissionIds, string $column): void
    {
        DB::table('analysis_submissions')
            ->whereIn('id', $analysisSubmissionIds)
            ->update([$column => ServiceStatus::NotApplicable->value, 'updated_at' => now()]);
    }

    /**
     * A pre-signed GET per object, D-85's 6h TTL, signed once per dispatch: the
     * same submission is sent to SIM, AID and VUL, and signing is pure
     * computation but not free.
     */
    private function fileUrl(string $path): string
    {
        return $this->signed[$path] ??= $this->storage->temporaryUrl($path);
    }

    /**
     * A broker that refuses is not a reason to abandon the rest of the batch:
     * the rows are already durable, the submissions stay `in_queue`, and D7's
     * sweeper is what eventually closes them (v3 §7, 2026-08-14).
     *
     * @param  array<string, mixed>  $context
     */
    private function publish(PlagiarismCheckerJob $job, AnalysisProblem $batch, array $context = []): void
    {
        try {
            $this->publisher->publish($job);
        } catch (PublishFailed $failure) {
            Log::error('could not queue an analysis job', $context + [
                'analysis_problem_id' => $batch->id,
                'queue' => $job->queue(),
                'trace_id' => $job->traceId(),
                'error' => $failure->getMessage(),
            ]);
        }
    }
}
