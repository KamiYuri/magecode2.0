<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExecutionStatus;
use App\Exceptions\UnprocessableException;
use App\Messaging\JobPublisher;
use App\Messaging\Jobs\CodeExecutorJob;
use App\Messaging\PublishFailed;
use App\Models\Problem;
use App\Models\ProgrammingLanguage;
use App\Models\Submission;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The write path for submissions: the four refusals, then one row and one
 * object that must both exist or neither.
 *
 * The object key needs the submission id, so the MinIO write happens inside
 * the transaction. That is why the lock is an advisory lock keyed on
 * (problem, student) rather than a row lock — a class sharing a deadline must
 * not queue behind one student's storage round-trip (v3 §7, 2026-08-14).
 */
class SubmissionService
{
    public function __construct(
        private readonly ProblemVisibilityService $visibility,
        private readonly SubmissionStorageService $storage,
        private readonly JobPublisher $publisher,
    ) {}

    public function submitSource(
        Problem $problem,
        User $student,
        ProgrammingLanguage $language,
        string $source,
    ): Submission {
        return $this->submit(
            $problem,
            $student,
            $language,
            fn (int $id): StoredSubmissionFile => $this->storage->storeSource($problem->id, $id, $source, $language),
        );
    }

    public function submitFile(
        Problem $problem,
        User $student,
        ProgrammingLanguage $language,
        UploadedFile $file,
    ): Submission {
        return $this->submit(
            $problem,
            $student,
            $language,
            fn (int $id): StoredSubmissionFile => $this->storage->storeUpload($problem->id, $id, $file, $language),
        );
    }

    /** @param  Closure(int): StoredSubmissionFile  $write */
    private function submit(
        Problem $problem,
        User $student,
        ProgrammingLanguage $language,
        Closure $write,
    ): Submission {
        // Checked before the lock: neither depends on what other submissions
        // exist, so a student who cannot submit at all never contends.
        if (! $this->visibility->isSubmittable($problem)) {
            throw UnprocessableException::deadlinePassed();
        }

        if (! $problem->programmingLanguages()->whereKey($language->id)->exists()) {
            throw UnprocessableException::languageNotAllowed();
        }

        $stored = null;

        try {
            $submission = DB::transaction(function () use ($problem, $student, $language, $write, &$stored): Submission {
                $this->serialiseSubmitter($problem, $student);
                $this->assertNothingInFlight($problem, $student);
                $this->assertQuotaRemains($problem, $student);

                // Reserve the id first: file_path is NOT NULL and the key
                // embeds the id, so the object cannot be written after the row
                // without a placeholder nobody would ever clean up.
                $id = $this->reserveId();
                $stored = $write($id);

                $submission = new Submission([
                    'problem_id' => $problem->id,
                    'creator_id' => $student->id,
                    'programming_language_id' => $language->id,
                    'file_path' => $stored->path,
                    'file_name' => $stored->name,
                ]);
                $submission->id = $id;
                $submission->save();

                return $submission;
            });
        } catch (Throwable $failure) {
            // The row is gone with the transaction; the object is not, and
            // nothing else knows its key (C1 left delete() for exactly this).
            if ($stored !== null) {
                $this->storage->delete($stored->path);
            }

            throw $failure;
        }

        $this->requestExecution($submission);

        return $submission;
    }

    /**
     * Wake CES — after the commit, never inside it. A message names an id
     * (D-84), and an id from a rolled-back transaction is a job that can never
     * succeed.
     *
     * A broker that refuses is not the student's problem: the submission is
     * already durable in Postgres and MinIO, so the failure is logged with the
     * id a later sweep needs and the row waits at `in_queue` (v3 §7,
     * 2026-08-14). Re-publishing it belongs to C7/D7.
     */
    private function requestExecution(Submission $submission): void
    {
        $job = CodeExecutorJob::for($submission->id);

        try {
            $this->publisher->publish($job);
        } catch (PublishFailed $failure) {
            Log::error('could not queue submission for execution', [
                'submission_id' => $submission->id,
                'trace_id' => $job->traceId(),
                'queue' => $job->queue(),
                'error' => $failure->getMessage(),
            ]);
        }
    }

    /**
     * D-36/D-39. Two int4 arguments rather than a row lock: no row exists to
     * lock before a student's first submission, which is the case a quota of 1
     * turns into a race. Transaction-scoped because D-89 pools by transaction —
     * a session-scoped lock would outlive the connection's owner.
     */
    private function serialiseSubmitter(Problem $problem, User $student): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [$problem->id, $student->id]);
    }

    private function assertNothingInFlight(Problem $problem, User $student): void
    {
        $inFlight = Submission::query()
            ->where('problem_id', $problem->id)
            ->where('creator_id', $student->id)
            ->whereIn('execution_status', ExecutionStatus::unfinishedValues())
            ->exists();

        if ($inFlight) {
            throw UnprocessableException::submissionProcessing();
        }
    }

    /** A null `max_submissions` is unlimited; rows count, verdicts do not. */
    private function assertQuotaRemains(Problem $problem, User $student): void
    {
        $limit = $problem->max_submissions;

        if ($limit === null) {
            return;
        }

        $used = Submission::query()
            ->where('problem_id', $problem->id)
            ->where('creator_id', $student->id)
            ->count();

        if ($used >= $limit) {
            throw UnprocessableException::maxSubmissions($limit);
        }
    }

    private function reserveId(): int
    {
        /** @var object{nextval: int|string} $row */
        $row = DB::selectOne("SELECT nextval('submissions_id_seq') AS nextval");

        return (int) $row->nextval;
    }
}
