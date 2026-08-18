<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\AiDetectorLanguage;
use App\Enums\CodeqlLanguage;
use App\Enums\ServiceStatus;
use App\Messaging\JobPublisher;
use App\Messaging\Jobs\AiDetectorJob;
use App\Messaging\Jobs\PlagiarismCheckerJob;
use App\Messaging\Jobs\VulnScannerJob;
use App\Messaging\PublishFailed;
use App\Messaging\QueueMessage;
use App\Models\AnalysisProblem;
use App\Services\SubmissionStorageService;
use Illuminate\Support\Collection;
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
        $this->dispatchAiDetection($batch);
        $this->dispatchVulnerabilityScans($batch);
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
     * AID: one message per submission (`rabbitmq-schemas.md` §3.3), routed on
     * `monaco_language` (v3 §7, 2026-08-18). A value the job schema's enum does
     * not carry cannot be published, so that submission parks instead.
     */
    private function dispatchAiDetection(AnalysisProblem $batch): void
    {
        $rows = $this->pendingRows($batch, 'ai_detection_status', 'monaco_language');

        /** @var list<array{0: \stdClass, 1: AiDetectorLanguage}> $publishable */
        $publishable = [];
        /** @var list<int> $parked */
        $parked = [];

        foreach ($rows as $row) {
            $language = $row->language === null ? null : AiDetectorLanguage::tryFrom((string) $row->language);

            if ($language === null) {
                $parked[] = (int) $row->analysis_submission_id;

                continue;
            }

            $publishable[] = [$row, $language];
        }

        // Parked before published: a broker that hangs must not leave rows
        // that were never going to be sent looking like rows still in flight.
        if ($parked !== []) {
            $this->park($parked, 'ai_detection_status');
        }

        foreach ($publishable as [$row, $language]) {
            $this->publish(AiDetectorJob::for(
                (int) $row->analysis_submission_id,
                (int) $row->submission_id,
                $this->fileUrl((string) $row->file_path),
                $language,
            ), $batch);
        }
    }

    /**
     * VUL: one message per submission, gated on `codeql_language`. Null there
     * means CodeQL has no analyser for the language at all, which the roadmap
     * makes an explicit `not_applicable` rather than a job.
     */
    private function dispatchVulnerabilityScans(AnalysisProblem $batch): void
    {
        $rows = $this->pendingRows($batch, 'vuln_scan_status', 'codeql_language');

        /** @var list<array{0: \stdClass, 1: CodeqlLanguage}> $publishable */
        $publishable = [];
        /** @var list<int> $parked */
        $parked = [];

        foreach ($rows as $row) {
            $language = $row->language === null ? null : CodeqlLanguage::tryFrom((string) $row->language);

            if ($language === null) {
                $parked[] = (int) $row->analysis_submission_id;

                continue;
            }

            $publishable[] = [$row, $language];
        }

        if ($parked !== []) {
            $this->park($parked, 'vuln_scan_status');
        }

        foreach ($publishable as [$row, $language]) {
            $this->publish(VulnScannerJob::for(
                (int) $row->analysis_submission_id,
                (int) $row->submission_id,
                $this->fileUrl((string) $row->file_path),
                $language,
            ), $batch);
        }
    }

    /**
     * The batch's submissions still waiting on one service, with the language
     * column that service routes on aliased to `language`.
     *
     * A service that was not requested has already been written
     * `not_applicable` by D1, so its query returns nothing and its dispatch is
     * a no-op.
     *
     * @param  'plagiarism_status'|'ai_detection_status'|'vuln_scan_status'  $statusColumn
     * @param  'dolos_language'|'monaco_language'|'codeql_language'  $languageColumn
     * @return Collection<int, \stdClass>
     */
    private function pendingRows(AnalysisProblem $batch, string $statusColumn, string $languageColumn): Collection
    {
        return DB::table('analysis_submissions as entry')
            ->join('submissions as submission', 'submission.id', '=', 'entry.submission_id')
            ->leftJoin('programming_languages as language', 'language.id', '=', 'submission.programming_language_id')
            ->where('entry.analysis_problem_id', $batch->id)
            ->where("entry.{$statusColumn}", ServiceStatus::InQueue->value)
            ->orderBy('entry.id')
            ->get([
                'entry.id as analysis_submission_id',
                'submission.id as submission_id',
                'submission.file_path',
                "language.{$languageColumn} as language",
            ]);
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
        return $this->pendingRows($batch, 'plagiarism_status', 'dolos_language')
            ->map(fn (\stdClass $row): SimSubmissionRef => new SimSubmissionRef(
                analysisSubmissionId: (int) $row->analysis_submission_id,
                submissionId: (int) $row->submission_id,
                filePath: (string) $row->file_path,
                dolosLanguage: $row->language === null ? null : (string) $row->language,
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
    private function publish(QueueMessage $job, AnalysisProblem $batch, array $context = []): void
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
