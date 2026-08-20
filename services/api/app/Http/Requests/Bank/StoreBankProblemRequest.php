<?php

declare(strict_types=1);

namespace App\Http\Requests\Bank;

use App\Enums\Difficulty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `CreateBankProblemRequest` in openapi.yml — also the body of the update. */
class StoreBankProblemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'difficulty' => ['sometimes', Rule::enum(Difficulty::class)],
            'time_limit' => ['required', 'integer', 'min:100', 'max:30000'],
            'memory_limit' => ['required', 'integer', 'min:1024', 'max:524288'],
            'programming_language_ids' => ['required', 'array', 'min:1'],
            'programming_language_ids.*' => ['integer', 'exists:programming_languages,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
            'test_cases' => ['sometimes', 'array'],
            'test_cases.*.input' => ['required', 'string'],
            'test_cases.*.expected_output' => ['required', 'string'],
            'test_cases.*.is_active' => ['sometimes', 'boolean'],
            'test_cases.*.is_visible' => ['sometimes', 'boolean'],
            'test_cases.*.order' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
