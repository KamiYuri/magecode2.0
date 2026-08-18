<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\AnalysisService;
use App\Enums\AnalysisStatus;
use App\Enums\ServiceStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\UnprocessableException;
use App\Models\AnalysisProblem;
use App\Models\Problem;
use App\Models\Submission;
use App\Models\User;
use App\Services\ProblemVisibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Creates an analysis batch for the scope a problem belongs to.
 *
 * The scope is semester-wide (D-48, Phương án B): one batch covers every
 * equivalent problem across every section, which is why a second instructor
 * pressing the button gets the existing results rather than a second run
 * (v3 §7, 2026-08-14).
 */
class AnalysisTriggerService
{
    public function __construct(
        private readonly AnalysisScopeResolver $scopes,
        private readonly ProblemVisibilityService $visibility,
        private readonly AnalysisJobDispatcher $dispatcher,
    ) {}

    /**
     * @param  list<AnalysisService>  $services
     * @return array{batch: AnalysisProblem, created: bool}
     *
     * @throws ConflictException|UnprocessableException
     */
    public function trigger(Problem $problem, User $analyst, array $services, bool $force = false): array
    {
        try {
            $result = DB::transaction(fn (): array => $this->create($problem, $analyst, $services, $force));
        } catch (QueryException $collision) {
            // Two instructors pressed at once. The partial unique indexes of
            // D-53 let exactly one INSERT through; the loser reads back the
            // winner rather than reporting a server error for a race it lost
            // fairly.
            if (! $this->isUniqueViolation($collision)) {
                throw $collision;
            }

            $scope = $this->scopes->resolve($problem->fresh() ?? $problem);
            $winner = $scope === null ? null : $this->latestFor($scope);

            if ($winner === null) {
                throw $collision;
            }

            throw ConflictException::analysisInProgress($winner->id);
        }

        // After the commit, never inside it: a job naming rows that were rolled
        // back can never succeed (C3). A batch handed back unchanged has
        // nothing to publish — its results already exist.
        if ($result['created']) {
            $this->dispatcher->dispatch($result['batch']);
        }

        return $result;
    }

    /**
     * @param  list<AnalysisService>  $services
     * @return array{batch: AnalysisProblem, created: bool}
     */
    private function create(Problem $problem, User $analyst, array $services, bool $force): array
    {
        // Inside the transaction: this may write a match group, and a group
        // stored by a call that then fails would be adopted by the next one.
        $scope = $this->scopes->resolveAndClaim($problem);
        $existing = $this->latestFor($scope);

        if ($existing !== null && $existing->status === AnalysisStatus::Processing) {
            throw ConflictException::analysisInProgress($existing->id);
        }

        if (! $force && $existing !== null && $this->covers($existing, $services)) {
            return ['batch' => $existing, 'created' => false];
        }

        $submissionIds = $this->submissionsInScope($scope);

        if ($submissionIds === []) {
            throw UnprocessableException::noSubmissions();
        }

        // D-53: only one latest per scope, and the partial unique index will
        // refuse a second if this update loses a race.
        AnalysisProblem::query()
            ->where($this->scopeFilter($scope))
            ->where('is_latest', true)
            ->update(['is_latest' => false]);

        $batch = AnalysisProblem::create($scope->columns() + [
            'triggered_by_problem_id' => $problem->id,
            'analyst_id' => $analyst->id,
            'services' => array_map(fn (AnalysisService $service): string => $service->value, $services),
            'status' => AnalysisStatus::Processing,
            'is_latest' => true,
            'is_partial' => $this->anyProblemStillOpen($scope),
            'started_at' => now(),
        ]);

        $this->enrol($batch, $submissionIds, $services);

        return ['batch' => $batch, 'created' => true];
    }

    /**
     * D-49: one submission per student — the newest — across every problem in
     * scope.
     *
     * Verdicts are not filtered on: SIM and AID read the source, not the
     * grade, and a program that fails every test can still be copied.
     *
     * @return list<int>
     */
    private function submissionsInScope(AnalysisScope $scope): array
    {
        $ids = DB::query()
            ->fromSub(
                Submission::query()
                    ->selectRaw('DISTINCT ON (creator_id) id')
                    ->whereIn('problem_id', $scope->problems()->select('id'))
                    ->orderByRaw('creator_id, created_at DESC, id DESC')
                    ->getQuery(),
                'latest'
            )
            ->pluck('id');

        /** @var list<int> */
        return $ids->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $submissionIds
     * @param  list<AnalysisService>  $services
     */
    private function enrol(AnalysisProblem $batch, array $submissionIds, array $services): void
    {
        // D-54: a service nobody asked for starts as not_applicable, so the
        // completion check (D-55) never waits on a result that is not coming.
        $statuses = [
            'plagiarism_status' => $this->statusFor(AnalysisService::PlagiarismChecker, $services),
            'ai_detection_status' => $this->statusFor(AnalysisService::AiDetector, $services),
            'vuln_scan_status' => $this->statusFor(AnalysisService::VulnScanner, $services),
        ];

        $rows = array_map(fn (int $submissionId): array => $statuses + [
            'analysis_problem_id' => $batch->id,
            'submission_id' => $submissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $submissionIds);

        DB::table('analysis_submissions')->insert($rows);
    }

    /** @param list<AnalysisService> $services */
    private function statusFor(AnalysisService $service, array $services): string
    {
        return in_array($service, $services, true)
            ? ServiceStatus::InQueue->value
            : ServiceStatus::NotApplicable->value;
    }

    /**
     * D-57: students can still submit to an open problem, so a batch covering
     * one is incomplete by construction. The *effective* lock is what counts
     * (amendment 2026-08-13) — a problem past its `lock_time` in auto mode is
     * closed even with the manual flag clear.
     */
    private function anyProblemStillOpen(AnalysisScope $scope): bool
    {
        foreach ($scope->problems()->with('section.semester')->get() as $problem) {
            if (! $this->visibility->isLocked($problem)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<AnalysisService> $services */
    private function covers(AnalysisProblem $batch, array $services): bool
    {
        if ($batch->status !== AnalysisStatus::Completed) {
            return false;
        }

        $asked = array_map(fn (AnalysisService $service): string => $service->value, $services);
        sort($asked);

        $ran = $batch->services;
        sort($ran);

        return $asked === $ran;
    }

    private function latestFor(AnalysisScope $scope): ?AnalysisProblem
    {
        return AnalysisProblem::query()
            ->where($this->scopeFilter($scope))
            ->where('is_latest', true)
            ->first();
    }

    /** @return array<string, mixed> */
    private function scopeFilter(AnalysisScope $scope): array
    {
        return array_filter(
            $scope->columns(),
            static fn (mixed $value): bool => $value !== null,
        );
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        // 23505 is Postgres's unique_violation; the driver surfaces it as the
        // SQLSTATE in position 0.
        return ($exception->errorInfo[0] ?? null) === '23505';
    }
}
