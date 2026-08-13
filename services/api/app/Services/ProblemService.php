<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UnprocessableException;
use App\Models\Problem;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProblemService
{
    /** Keys that are relations or nested records, not columns. */
    private const RELATION_KEYS = ['programming_language_ids', 'tag_ids', 'test_cases'];

    public function __construct(private readonly ProblemVisibilityService $visibility) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Section $section, array $attributes, User $creator): Problem
    {
        return DB::transaction(function () use ($section, $attributes, $creator): Problem {
            $problem = $section->problems()->create(
                array_diff_key($attributes, array_flip(self::RELATION_KEYS)) + ['creator_id' => $creator->id]
            );

            $problem->programmingLanguages()->sync($attributes['programming_language_ids'] ?? []);
            $problem->tags()->sync($attributes['tag_ids'] ?? []);

            /** @var array<int, array<string, mixed>> $testCases */
            $testCases = $attributes['test_cases'] ?? [];

            if ($testCases !== []) {
                $problem->testCases()->createMany($testCases);
                $problem->forceFill(['testcases_updated_at' => now()])->save();
            }

            return $problem;
        });
    }

    /**
     * Returns how many submissions the edit invalidated (D-41) — zero unless a
     * core field actually moved, so renaming a problem never disturbs work
     * already graded.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Problem $problem, array $attributes, bool $touchesCoreFields): int
    {
        if ($touchesCoreFields && $this->visibility->isLocked($problem)) {
            throw UnprocessableException::problemLocked();
        }

        return DB::transaction(function () use ($problem, $attributes): int {
            $coreChanged = $this->coreValuesChange($problem, $attributes);

            $problem->fill(array_diff_key($attributes, array_flip(self::RELATION_KEYS)));
            $problem->save();

            if (array_key_exists('programming_language_ids', $attributes)) {
                $problem->programmingLanguages()->sync($attributes['programming_language_ids']);
            }

            if (array_key_exists('tag_ids', $attributes)) {
                $problem->tags()->sync($attributes['tag_ids']);
            }

            return $coreChanged ? $this->flagSubmissionsOutdated($problem) : 0;
        });
    }

    /** Existing work was judged under the old rules; say so (D-41). */
    public function flagSubmissionsOutdated(Problem $problem): int
    {
        return $problem->submissions()->where('is_outdated', false)->update(['is_outdated' => true]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function coreValuesChange(Problem $problem, array $attributes): bool
    {
        foreach (['time_limit', 'memory_limit'] as $field) {
            if (array_key_exists($field, $attributes) && $problem->{$field} !== (int) $attributes[$field]) {
                return true;
            }
        }

        if (! array_key_exists('programming_language_ids', $attributes)) {
            return false;
        }

        /** @var array<int, int> $requested */
        $requested = $attributes['programming_language_ids'];
        $current = $problem->programmingLanguages()->pluck('programming_languages.id')->all();

        sort($requested);
        sort($current);

        return $requested !== $current;
    }
}
