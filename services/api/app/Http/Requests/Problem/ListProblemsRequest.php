<?php

declare(strict_types=1);

namespace App\Http\Requests\Problem;

use App\Enums\Difficulty;
use App\Http\Requests\Concerns\AcceptsIncludes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProblemsRequest extends FormRequest
{
    use AcceptsIncludes;

    /** Test cases are never listable in bulk — only through the detail read. */
    public const INCLUDABLE = ['programming_languages', 'tags', 'creator'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'group_label' => ['sometimes', 'string', 'max:100'],
            'difficulty' => ['sometimes', Rule::enum(Difficulty::class)],
            'include' => ['sometimes', 'array'],
            'include.*' => [Rule::in(self::INCLUDABLE)],
        ];
    }
}
