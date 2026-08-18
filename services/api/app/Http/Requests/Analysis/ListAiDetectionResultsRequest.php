<?php

declare(strict_types=1);

namespace App\Http\Requests\Analysis;

use Illuminate\Foundation\Http\FormRequest;

class ListAiDetectionResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'flagged_only' => ['sometimes', 'boolean'],
            'section_id' => ['sometimes', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer'],
        ];
    }
}
