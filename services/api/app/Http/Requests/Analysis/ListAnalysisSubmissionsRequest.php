<?php

declare(strict_types=1);

namespace App\Http\Requests\Analysis;

use App\Http\Requests\Concerns\AcceptsIncludes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAnalysisSubmissionsRequest extends FormRequest
{
    use AcceptsIncludes;

    /**
     * Copied from the contract's enum, which `RouteConformanceTest` diffs
     * against openapi.yml (D-91: `include` is enumerated per operation).
     *
     * `source_code` opts in to the field; it never widens who may read it
     * (D-05/D-06).
     */
    public const INCLUDABLE = ['source_code', 'submission', 'student'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'section_id' => ['sometimes', 'integer', 'min:1'],
            'include' => ['sometimes', 'array'],
            'include.*' => [Rule::in(self::INCLUDABLE)],
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer'],
        ];
    }
}
