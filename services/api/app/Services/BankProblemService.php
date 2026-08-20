<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BankProblemStatus;
use App\Models\BankProblem;
use App\Models\Course;
use App\Models\Problem;
use App\Models\Section;
use App\Models\User;
use App\Notifications\BankProblemApprovalRequired;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `bank_problems`, and the one place the version chain is
 * built (D-63/D-64) and read from.
 *
 * The chain rule is worth stating once: `original_id` is NULL on the first
 * version and points at that first row on every later one, so every version of
 * a problem is one query and the first row is its own identity. A new version
 * is `max(version) + 1` over the chain, never an overwrite (D-66) -- a section
 * that cloned version 2 keeps teaching version 2 after someone publishes
 * version 3.
 */
class BankProblemService
{
    /**
     * D-25/D-70: a course that requires approval admits new entries as
     * `pending` and tells its Org Admins; one that does not approves on the
     * spot, because an approval nobody is required to give is a queue that
     * never drains.
     */
    public function initialStatus(Course $course): BankProblemStatus
    {
        return $course->require_bank_approval
            ? BankProblemStatus::Pending
            : BankProblemStatus::Approved;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $languageIds
     * @param  list<int>  $tagIds
     * @param  list<array<string, mixed>>  $testCases
     */
    public function create(
        Course $course,
        User $author,
        array $attributes,
        array $languageIds,
        array $tagIds = [],
        array $testCases = [],
        ?BankProblem $newVersionOf = null,
    ): BankProblem {
        return DB::transaction(function () use (
            $course, $author, $attributes, $languageIds, $tagIds, $testCases, $newVersionOf
        ): BankProblem {
            $chain = $newVersionOf === null ? null : $this->rootOf($newVersionOf);

            $bankProblem = BankProblem::query()->create($attributes + [
                'course_id' => $course->id,
                'author_id' => $author->id,
                // NULL on the first version, the root's id on every later one.
                'original_id' => $chain?->id,
                'version' => $chain === null ? 1 : $this->nextVersion($chain),
                'status' => $this->initialStatus($course)->value,
            ]);

            $bankProblem->programmingLanguages()->sync($languageIds);
            $bankProblem->tags()->sync($tagIds);

            foreach ($testCases as $index => $testCase) {
                $bankProblem->testCases()->create($testCase + ['order' => $testCase['order'] ?? $index + 1]);
            }

            return $bankProblem;
        });
    }

    /**
     * D-66: publishing a section problem into the bank. A problem that was
     * itself cloned from the bank extends that chain rather than starting a
     * second one -- otherwise every round trip through a section would fork
     * the history and `versions` would answer with one row for ever.
     */
    public function publishFromProblem(Problem $problem, User $author): BankProblem
    {
        $course = $problem->section->semester->course;

        return $this->create(
            $course,
            $author,
            [
                'name' => $problem->name,
                'description' => $problem->description,
                'difficulty' => $problem->difficulty->value,
                'time_limit' => $problem->time_limit,
                'memory_limit' => $problem->memory_limit,
            ],
            $problem->programmingLanguages()->pluck('programming_languages.id')->all(),
            $problem->tags()->pluck('tags.id')->all(),
            $problem->testCases()->orderBy('order')->get()
                ->map(fn ($testCase): array => [
                    'input' => $testCase->input,
                    'expected_output' => $testCase->expected_output,
                    'is_active' => $testCase->is_active,
                    'is_visible' => $testCase->is_visible,
                    'order' => $testCase->order,
                ])->all(),
            newVersionOf: $problem->bankProblem,
        );
    }

    /**
     * D-65: a deep copy into a section. The section's problem keeps a
     * `bank_problem_id` so its origin is known, but nothing propagates
     * afterwards -- the instructor owns their copy, and a later bank edit does
     * not rewrite a problem students are already solving.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function cloneInto(BankProblem $bankProblem, Section $section, User $creator, array $overrides = []): Problem
    {
        return DB::transaction(function () use ($bankProblem, $section, $creator, $overrides): Problem {
            $problem = Problem::query()->create($overrides + [
                'section_id' => $section->id,
                'bank_problem_id' => $bankProblem->id,
                'creator_id' => $creator->id,
                'name' => $bankProblem->name,
                'description' => $bankProblem->description,
                'difficulty' => $bankProblem->difficulty->value,
                'time_limit' => $bankProblem->time_limit,
                'memory_limit' => $bankProblem->memory_limit,
            ]);

            $problem->programmingLanguages()->sync(
                $bankProblem->programmingLanguages()->pluck('programming_languages.id')->all()
            );
            $problem->tags()->sync($bankProblem->tags()->pluck('tags.id')->all());

            foreach ($bankProblem->testCases()->orderBy('order')->get() as $testCase) {
                $problem->testCases()->create([
                    'input' => $testCase->input,
                    'expected_output' => $testCase->expected_output,
                    'is_active' => $testCase->is_active,
                    'is_visible' => $testCase->is_visible,
                    'order' => $testCase->order,
                ]);
            }

            return $problem;
        });
    }

    /** D-70: the Org Admins who can act on it, told once, in-app only. */
    public function requestApproval(BankProblem $bankProblem): void
    {
        if ($bankProblem->status !== BankProblemStatus::Pending) {
            return;
        }

        foreach ($this->organizationAdmins($bankProblem) as $admin) {
            $admin->notify(new BankProblemApprovalRequired($bankProblem));
        }
    }

    /** The first version of the chain `$bankProblem` belongs to. */
    public function rootOf(BankProblem $bankProblem): BankProblem
    {
        return $bankProblem->original_id === null
            ? $bankProblem
            : ($bankProblem->original ?? $bankProblem);
    }

    private function nextVersion(BankProblem $root): int
    {
        $highest = BankProblem::query()
            ->withTrashed()
            ->where(fn ($chain) => $chain->whereKey($root->id)->orWhere('original_id', $root->id))
            ->max('version');

        return (int) $highest + 1;
    }

    /** @return Collection<int, User> */
    private function organizationAdmins(BankProblem $bankProblem): Collection
    {
        return User::query()
            ->whereHas('organizationMemberships', fn ($member) => $member
                ->where('organization_id', $bankProblem->course->organization_id)
                ->where('role', 'admin'))
            ->get();
    }
}
