<?php

declare(strict_types=1);

namespace App\Http\Requests\Problem;

use App\Models\Problem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BatchUpdateTestCasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $problem = $this->route('problem');

        return [
            'test_cases' => ['required', 'array'],
            'test_cases.*.id' => [
                'sometimes',
                'nullable',
                'integer',
                // Scoped to this problem: the batch is not a way to edit
                // someone else's test cases by id.
                Rule::exists('test_cases', 'id')
                    ->where('problem_id', $problem instanceof Problem ? $problem->id : 0),
            ],
            // A row being deleted carries nothing but its id.
            'test_cases.*.input' => [
                'required_unless:test_cases.*._delete,true',
                'string',
                'max:'.StoreProblemRequest::MAX_TEST_CASE_BYTES,
            ],
            'test_cases.*.expected_output' => [
                'required_unless:test_cases.*._delete,true',
                'string',
                'max:'.StoreProblemRequest::MAX_TEST_CASE_BYTES,
            ],
            'test_cases.*.is_active' => ['sometimes', 'boolean'],
            'test_cases.*.is_visible' => ['sometimes', 'boolean'],
            'test_cases.*.order' => ['sometimes', 'integer'],
            'test_cases.*._delete' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $problem = $this->route('problem');

                if (! $problem instanceof Problem || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->survivingCount($problem) > StoreProblemRequest::MAX_TEST_CASES) {
                    $validator->errors()->add('test_cases', __('A problem may hold at most :max test cases.', [
                        'max' => StoreProblemRequest::MAX_TEST_CASES,
                    ]));
                }
            },
        ];
    }

    /**
     * What the problem would hold once the batch is applied: the ceiling is
     * about the result, so swapping a case at exactly the limit is fine.
     */
    private function survivingCount(Problem $problem): int
    {
        $existing = $problem->testCases()->count();

        foreach ($this->entries() as $entry) {
            $isDelete = (bool) ($entry['_delete'] ?? false);
            $isNew = ($entry['id'] ?? null) === null;

            if ($isDelete) {
                $existing--;
            } elseif ($isNew) {
                $existing++;
            }
        }

        return $existing;
    }

    /** @return array<int, array<string, mixed>> */
    public function entries(): array
    {
        /** @var array<int, array<string, mixed>> $entries */
        $entries = $this->validated('test_cases');

        return $entries;
    }
}
