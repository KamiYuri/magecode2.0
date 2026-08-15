<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Models\Problem;
use Illuminate\Support\Str;

/**
 * Works out which scope a problem belongs to, giving it one if it has none.
 *
 * Separate from the trigger service because D2 (SIM job building), D4 (result
 * handling) and D8 (the read APIs) all need "which problems are in this scope"
 * and none of them should re-derive it.
 */
class AnalysisScopeResolver
{
    /**
     * Resolve, and generate a one-problem match group when the problem has
     * neither identifier (v3 §7, 2026-08-14).
     *
     * The write makes this **not** safe to call outside the trigger's
     * transaction: a UUID stored by a call that then rolls back would leave
     * the problem carrying a group no batch ever used, and the next trigger
     * would silently adopt it.
     */
    public function resolveAndClaim(Problem $problem): AnalysisScope
    {
        $semesterId = $problem->section->semester_id;

        // Locked, and re-read under the lock. Two instructors triggering the
        // same unscoped problem at once would otherwise each generate their
        // own UUID: two different scopes, so the partial unique indexes never
        // collide, two batches are created, and the problem keeps whichever
        // group was written last while a batch points at the other. The row
        // lock is cheap here — this transaction is short and touches nothing
        // but the database.
        $locked = Problem::query()->whereKey($problem->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->bank_problem_id !== null) {
            return AnalysisScope::bank($semesterId, $locked->bank_problem_id);
        }

        if ($locked->manual_match_group_id !== null) {
            return AnalysisScope::manual($semesterId, $locked->manual_match_group_id);
        }

        // A problem written from scratch satisfies neither branch of the scope
        // query, so without this it could never be analysed at all — and that
        // is the ordinary case for a section teaching its own exercises.
        $groupId = (string) Str::uuid();
        $locked->forceFill(['manual_match_group_id' => $groupId])->save();
        $problem->setAttribute('manual_match_group_id', $groupId);

        return AnalysisScope::manual($semesterId, $groupId);
    }

    /** Read-only: for callers that must not invent a scope (D2, D4, D8). */
    public function resolve(Problem $problem): ?AnalysisScope
    {
        $semesterId = $problem->section->semester_id;

        return match (true) {
            $problem->bank_problem_id !== null => AnalysisScope::bank($semesterId, $problem->bank_problem_id),
            $problem->manual_match_group_id !== null => AnalysisScope::manual($semesterId, $problem->manual_match_group_id),
            default => null,
        };
    }
}
