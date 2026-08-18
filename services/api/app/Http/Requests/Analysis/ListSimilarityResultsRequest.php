<?php

declare(strict_types=1);

namespace App\Http\Requests\Analysis;

use App\Enums\MatchType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSimilarityResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'match_type' => ['sometimes', Rule::enum(MatchType::class)],
            'min_similarity' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'section_id' => ['sometimes', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer'],
        ];
    }
}
