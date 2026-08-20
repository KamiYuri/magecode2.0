<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BankProblemStatus;
use App\Http\Requests\Bank\CloneFromBankRequest;
use App\Http\Resources\ProblemDetailResource;
use App\Models\BankProblem;
use App\Models\Problem;
use App\Models\Section;
use App\Models\User;
use App\Services\BankProblemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-65: clone a bank entry into a section. B8 left this out deliberately --
 * it is a deep copy, and the bank did not exist yet.
 *
 * The copy is the point: test cases, languages and tags all come across, and
 * nothing propagates afterwards. The section's problem records where it came
 * from, so `versions` can still tell the instructor a newer one exists, but a
 * later bank edit never rewrites a problem students are already solving.
 */
class ProblemCloneController extends Controller
{
    public function __construct(private readonly BankProblemService $bank) {}

    public function __invoke(CloneFromBankRequest $request, Section $section): JsonResponse
    {
        $this->authorize('create', [Problem::class, $section]);

        $validated = $request->validated();

        /** @var BankProblem $bankProblem */
        $bankProblem = BankProblem::query()
            ->with(['testCases', 'programmingLanguages', 'tags'])
            ->findOrFail($validated['bank_problem_id']);

        // openapi names this one: the entry must belong to the same course as
        // the section's semester, or the bank becomes a way to move material
        // between courses without anyone deciding to.
        if ($bankProblem->course_id !== $section->semester->course_id) {
            throw ValidationException::withMessages([
                'bank_problem_id' => 'BANK_COURSE_MISMATCH: the bank problem belongs to another course.',
            ]);
        }

        // An unapproved entry is not part of the shared vocabulary yet (D-25),
        // so it cannot be taught from -- not even by its author, who would
        // otherwise be able to route around the gate they are waiting on.
        if ($bankProblem->status !== BankProblemStatus::Approved) {
            throw ValidationException::withMessages([
                'bank_problem_id' => 'The bank problem is not approved.',
            ]);
        }

        /** @var User $user */
        $user = $request->user();

        $problem = $this->bank->cloneInto($bankProblem, $section, $user, array_intersect_key(
            $validated,
            array_flip(['group_label', 'order', 'activation_time', 'lock_time']),
        ));

        return response()->json(
            ['data' => new ProblemDetailResource(
                $problem->load(['testCases', 'programmingLanguages', 'tags', 'creator'])
            )],
            Response::HTTP_CREATED
        );
    }
}
