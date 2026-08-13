<?php

declare(strict_types=1);

namespace App\Http\Requests\Problem;

use App\Enums\Difficulty;
use App\Enums\PublishMode;
use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProblemRequest extends FormRequest
{
    /** D-45: a problem carries at most 50 test cases, 1MB per field. */
    public const MAX_TEST_CASES = 50;

    public const MAX_TEST_CASE_BYTES = 1_048_576;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'difficulty' => ['sometimes', Rule::enum(Difficulty::class)],
            'group_label' => ['nullable', 'string', 'max:100'],
            'order' => ['nullable', 'integer'],
            'max_submissions' => ['nullable', 'integer', 'min:1'],
            'time_limit' => ['required', 'integer', 'min:100', 'max:30000'],
            'memory_limit' => ['required', 'integer', 'min:1024', 'max:524288'],
            'activation_time' => ['nullable', 'date'],
            'lock_time' => ['nullable', 'date'],
            'publish_mode_override' => ['nullable', Rule::enum(PublishMode::class)],
            'lock_mode_override' => ['nullable', Rule::enum(PublishMode::class)],
            'programming_language_ids' => ['required', 'array', 'min:1'],
            'programming_language_ids.*' => ['integer', 'exists:programming_languages,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', $this->tagBelongsToCourse()],
            'test_cases' => ['sometimes', 'array', 'max:'.self::MAX_TEST_CASES],
            'test_cases.*.input' => ['required', 'string', 'max:'.self::MAX_TEST_CASE_BYTES],
            'test_cases.*.expected_output' => ['required', 'string', 'max:'.self::MAX_TEST_CASE_BYTES],
            'test_cases.*.is_active' => ['sometimes', 'boolean'],
            'test_cases.*.is_visible' => ['sometimes', 'boolean'],
            'test_cases.*.order' => ['sometimes', 'integer'],
        ];
    }

    /** Tags are course-scoped (D-15): one from elsewhere is not addressable here. */
    private function tagBelongsToCourse(): mixed
    {
        $section = $this->route('section');

        return Rule::exists('tags', 'id')->where(
            'course_id',
            $section instanceof Section ? $section->semester->course_id : 0
        );
    }
}
