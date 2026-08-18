<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\MatchType;
use App\Enums\ServiceStatus;
use App\Models\AnalysisProblem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes one SIM language group's result: the pairs Dolos found, the
 * per-submission statuses, and the group's tick on the batch's completion set.
 *
 * `match_type` is assigned here rather than by SIM (D-61) — the worker has no
 * database and no idea which section a submission belongs to, and the two-tier
 * privacy rule (D-05/D-06) that D8 enforces reads exactly this column.
 */
class SimResultIngestor
{
    /** Prefix of the completion key D6 reads (U-9). */
    public const COMPLETION_KEY = 'sim_completion:';

    /**
     * Long enough to outlive D-82's 30-minute timeout by a wide margin, short
     * enough that an abandoned batch does not keep a row in the cache table
     * for good.
     */
    private const COMPLETION_TTL_SECONDS = 86400;

    public function __construct(private readonly AnalysisCompletionService $completion) {}

    /** @param array<string, mixed> $message */
    public function ingest(array $message): void
    {
        $batchId = (int) ($message['analysis_problem_id'] ?? 0);
        $batch = AnalysisProblem::find($batchId);

        if ($batch === null) {
            // Not retryable and not fatal: the batch is gone, so there is
            // nowhere to put the result and no later delivery will change it.
            Log::warning('sim result names an unknown analysis batch', [
                'analysis_problem_id' => $batchId,
                'trace_id' => $message['trace_id'] ?? null,
            ]);

            return;
        }

        $members = $this->members($batchId);

        DB::transaction(function () use ($batchId, $message, $members): void {
            $this->writePairs($batchId, $message, $members);
            $this->writeStatuses($batchId, $message, $members);
        });

        $this->recordGroup($batchId, $message);

        // After the write, never inside it: the frame tells a browser to read
        // rows, and a transaction that later rolls back would have promised
        // data nobody can fetch.
        $this->completion->settle($batch);
    }

    /**
     * The batch's submissions, by `submissions.id`: which `analysis_submission`
     * row is theirs and which section they were written in.
     *
     * One query for the whole message, because a 150-submission group carries
     * roughly eleven thousand pairs and a lookup apiece would be eleven
     * thousand round trips.
     *
     * @return array<int, array{analysis_submission_id: int, section_id: int}>
     */
    private function members(int $batchId): array
    {
        $rows = DB::table('analysis_submissions as entry')
            ->join('submissions as submission', 'submission.id', '=', 'entry.submission_id')
            ->join('problems as problem', 'problem.id', '=', 'submission.problem_id')
            ->where('entry.analysis_problem_id', $batchId)
            ->get(['entry.id as analysis_submission_id', 'submission.id as submission_id', 'problem.section_id']);

        $members = [];

        foreach ($rows as $row) {
            $members[(int) $row->submission_id] = [
                'analysis_submission_id' => (int) $row->analysis_submission_id,
                'section_id' => (int) $row->section_id,
            ];
        }

        return $members;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<int, array{analysis_submission_id: int, section_id: int}>  $members
     */
    private function writePairs(int $batchId, array $message, array $members): void
    {
        /** @var list<array<string, mixed>> $pairs */
        $pairs = $message['pairs'] ?? [];
        $rows = [];

        foreach ($pairs as $pair) {
            $a = (int) ($pair['submission_a_id'] ?? 0);
            $b = (int) ($pair['submission_b_id'] ?? 0);

            if (! isset($members[$a], $members[$b]) || $a === $b) {
                // The foreign keys are RESTRICT, so an id from outside the
                // batch would abort the whole message and every later delivery
                // of it identically. Dropping the pair keeps the group's other
                // results, and the warning is what says the worker misbehaved
                // (v3 §7, 2026-08-18).
                Log::warning('sim result pair names a submission outside the batch', [
                    'analysis_problem_id' => $batchId,
                    'submission_a_id' => $a,
                    'submission_b_id' => $b,
                    'trace_id' => $message['trace_id'] ?? null,
                ]);

                continue;
            }

            $rows[] = $this->pairRow($batchId, $pair, $a, $b, $members);
        }

        if ($rows === []) {
            return;
        }

        // Upsert on the unique triple: a redelivered message must leave one
        // row per pair, which the roadmap asks for and the index enforces.
        DB::table('similarity_results')->upsert(
            $rows,
            ['analysis_problem_id', 'submission_a_id', 'submission_b_id'],
            ['similarity', 'longest_fragment', 'total_overlap', 'match_type', 'a_regions', 'b_regions', 'created_at'],
        );
    }

    /**
     * @param  array<string, mixed>  $pair
     * @param  array<int, array{analysis_submission_id: int, section_id: int}>  $members
     * @return array<string, mixed>
     */
    private function pairRow(int $batchId, array $pair, int $a, int $b, array $members): array
    {
        $aRegions = $pair['a_regions'] ?? null;
        $bRegions = $pair['b_regions'] ?? null;

        // Schema §5.5 calls `submission_a_id < submission_b_id` critical, but
        // nothing in the database enforces it — and the unique index would let
        // the same pair in twice if a producer ever sent it both ways round.
        // Normalising here is what makes the invariant true; the regions
        // travel with their own side.
        if ($a > $b) {
            [$a, $b] = [$b, $a];
            [$aRegions, $bRegions] = [$bRegions, $aRegions];
        }

        return [
            'analysis_problem_id' => $batchId,
            'submission_a_id' => $a,
            'submission_b_id' => $b,
            'similarity' => (float) ($pair['similarity'] ?? 0),
            'longest_fragment' => $pair['longest_fragment'] ?? null,
            'total_overlap' => $pair['total_overlap'] ?? null,
            'match_type' => $this->matchType($members[$a]['section_id'], $members[$b]['section_id'])->value,
            'a_regions' => $aRegions,
            'b_regions' => $bRegions,
            'created_at' => now(),
        ];
    }

    /** D-61: the two sections decide, and api is the only side that knows them. */
    private function matchType(int $sectionA, int $sectionB): MatchType
    {
        return $sectionA === $sectionB ? MatchType::WithinSection : MatchType::CrossSection;
    }

    /**
     * `submission_statuses[]` is authoritative per submission. Anything in the
     * group it does not name takes the group-level status, so a group that
     * answered never leaves a row at `in_queue` waiting for a message that has
     * already arrived.
     *
     * The group is re-derived from the message's `language`, which is the same
     * rule `SimJobPlan` grouped by — the batch does not record which
     * submissions went into which message.
     *
     * @param  array<string, mixed>  $message
     * @param  array<int, array{analysis_submission_id: int, section_id: int}>  $members
     */
    private function writeStatuses(int $batchId, array $message, array $members): void
    {
        $known = array_column($members, 'analysis_submission_id');

        /** @var list<array<string, mixed>> $statuses */
        $statuses = $message['submission_statuses'] ?? [];
        $named = [];

        foreach ($statuses as $entry) {
            $id = (int) ($entry['analysis_submission_id'] ?? 0);

            if (! in_array($id, $known, true)) {
                Log::warning('sim result status names a submission outside the batch', [
                    'analysis_problem_id' => $batchId,
                    'analysis_submission_id' => $id,
                    'trace_id' => $message['trace_id'] ?? null,
                ]);

                continue;
            }

            $named[$id] = $this->statusFor((string) ($entry['status'] ?? 'error'));
        }

        foreach ($this->byStatus($named) as $status => $ids) {
            $this->apply($ids, $status);
        }

        $remaining = $this->groupRemainder($batchId, (string) ($message['language'] ?? ''), array_keys($named));

        if ($remaining !== []) {
            $this->apply($remaining, $this->statusFor((string) $message['status']));
        }
    }

    /**
     * The group's submissions this message did not name, found the same way
     * they were grouped in the first place: by `dolos_language`, among the rows
     * still waiting on SIM.
     *
     * @param  list<int>  $named
     * @return list<int>
     */
    private function groupRemainder(int $batchId, string $language, array $named): array
    {
        if ($language === '') {
            return [];
        }

        $query = DB::table('analysis_submissions as entry')
            ->join('submissions as submission', 'submission.id', '=', 'entry.submission_id')
            ->join('programming_languages as pl', 'pl.id', '=', 'submission.programming_language_id')
            ->where('entry.analysis_problem_id', $batchId)
            ->where('entry.plagiarism_status', ServiceStatus::InQueue->value)
            ->where('pl.dolos_language', $language);

        if ($named !== []) {
            $query->whereNotIn('entry.id', $named);
        }

        /** @var list<int> */
        return $query->pluck('entry.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @param  array<int, ServiceStatus>  $named
     * @return array<string, list<int>>
     */
    private function byStatus(array $named): array
    {
        $grouped = [];

        foreach ($named as $id => $status) {
            $grouped[$status->value][] = $id;
        }

        return $grouped;
    }

    /** @param list<int> $analysisSubmissionIds */
    private function apply(array $analysisSubmissionIds, ServiceStatus|string $status): void
    {
        DB::table('analysis_submissions')
            ->whereIn('id', $analysisSubmissionIds)
            ->update([
                'plagiarism_status' => $status instanceof ServiceStatus ? $status->value : $status,
                'updated_at' => now(),
            ]);
    }

    /** The result schema allows `completed` and `error`; anything else is an error. */
    private function statusFor(string $status): ServiceStatus
    {
        return $status === 'completed' ? ServiceStatus::Completed : ServiceStatus::Error;
    }

    /**
     * U-9, refined: the *set* of group indexes seen, not a running count.
     *
     * A counter would advance on a redelivery and report a batch complete
     * before its last group ever arrived — the one case a queue guarantees will
     * happen eventually. The set makes a repeat delivery a no-op. Held under a
     * cache lock because two consumers would otherwise read-modify-write over
     * each other, losing a group; D-82's sweeper remains the backstop if the
     * key is lost entirely.
     *
     * @param  array<string, mixed>  $message
     */
    private function recordGroup(int $batchId, array $message): void
    {
        $key = self::COMPLETION_KEY.$batchId;
        $index = (int) ($message['language_group_index'] ?? 0);
        $total = (int) ($message['language_group_total'] ?? 1);

        Cache::lock($key.':lock', 5)->block(3, function () use ($key, $index, $total): void {
            /** @var array{total: int, received: list<int>} $state */
            $state = Cache::get($key, ['total' => $total, 'received' => []]);

            $received = $state['received'];

            if (! in_array($index, $received, true)) {
                $received[] = $index;
                sort($received);
            }

            Cache::put($key, ['total' => $total, 'received' => $received], self::COMPLETION_TTL_SECONDS);
        });
    }
}
