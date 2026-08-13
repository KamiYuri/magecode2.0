<?php

declare(strict_types=1);

namespace App\Http\Requests\Problem;

use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderProblemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $section = $this->route('section');

        return [
            'order' => ['required', 'array', 'min:1'],
            'order.*.problem_id' => [
                'required',
                'integer',
                // Section-scoped: reordering is not a way to reach into L02.
                Rule::exists('problems', 'id')
                    ->where('section_id', $section instanceof Section ? $section->id : 0)
                    ->whereNull('deleted_at'),
            ],
            'order.*.order' => ['required', 'integer'],
            'order.*.group_label' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
