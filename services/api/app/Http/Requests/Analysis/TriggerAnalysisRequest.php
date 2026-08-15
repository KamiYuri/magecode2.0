<?php

declare(strict_types=1);

namespace App\Http\Requests\Analysis;

use App\Enums\AnalysisService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TriggerAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'services' => ['required', 'array', 'min:1'],
            'services.*' => [Rule::enum(AnalysisService::class)],
            // v3 §7: without it, a scope that already has completed results
            // answers 200 with them instead of spending the compute again.
            'force' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<AnalysisService> */
    public function services(): array
    {
        /** @var list<string> $values */
        $values = $this->safe()->array('services');

        return array_values(array_unique(
            array_map(AnalysisService::from(...), $values),
            SORT_REGULAR,
        ));
    }
}
