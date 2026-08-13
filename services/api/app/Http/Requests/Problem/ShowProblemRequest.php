<?php

declare(strict_types=1);

namespace App\Http\Requests\Problem;

use App\Http\Requests\Concerns\AcceptsIncludes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowProblemRequest extends FormRequest
{
    use AcceptsIncludes;

    /**
     * `test_cases` is accepted from anyone but honoured for staff only — a
     * student asking for it receives the plain Problem shape, not a 403, so
     * one client can request the same URL for both audiences.
     */
    public const INCLUDABLE = ['test_cases', 'programming_languages', 'tags', 'creator'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'include' => ['sometimes', 'array'],
            'include.*' => [Rule::in(self::INCLUDABLE)],
        ];
    }
}
