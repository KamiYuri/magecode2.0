<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UnprocessableException;
use App\Models\Problem;
use App\Models\TestCase;
use App\Models\User;
use App\Notifications\TestCasesUpdated;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class TestCaseService
{
    public function __construct(
        private readonly ProblemVisibilityService $visibility,
        private readonly ProblemService $problems,
    ) {}

    /**
     * Apply one batch of creates, updates and deletes.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{test_cases: Collection<int, TestCase>, outdated: int, notified: int}
     */
    public function sync(Problem $problem, array $entries): array
    {
        if ($this->visibility->isLocked($problem)) {
            throw UnprocessableException::problemLocked();
        }

        $outdated = DB::transaction(function () use ($problem, $entries): int {
            foreach ($entries as $entry) {
                $this->apply($problem, $entry);
            }

            $problem->forceFill(['testcases_updated_at' => now()])->save();

            return $this->problems->flagSubmissionsOutdated($problem);
        });

        return [
            'test_cases' => $problem->testCases()->orderBy('order')->orderBy('id')->get(),
            'outdated' => $outdated,
            'notified' => $outdated > 0 ? $this->notifyAuthors($problem) : 0,
        ];
    }

    /** @param  array<string, mixed>  $entry */
    private function apply(Problem $problem, array $entry): void
    {
        $id = $entry['id'] ?? null;

        if ((bool) ($entry['_delete'] ?? false)) {
            if ($id !== null) {
                $problem->testCases()->whereKey($id)->delete();
            }

            return;
        }

        $attributes = array_intersect_key($entry, array_flip(['input', 'expected_output', 'is_active', 'is_visible', 'order']));

        if ($id === null) {
            $problem->testCases()->create($attributes);

            return;
        }

        $problem->testCases()->whereKey($id)->update($attributes);
    }

    /**
     * One notification per person, however many times they submitted — the
     * news is about the problem, not about each attempt.
     */
    private function notifyAuthors(Problem $problem): int
    {
        $authors = User::query()
            ->whereIn('id', $problem->submissions()->distinct()->pluck('creator_id'))
            ->get();

        if ($authors->isEmpty()) {
            return 0;
        }

        Notification::send($authors, new TestCasesUpdated($problem));

        return $authors->count();
    }
}
