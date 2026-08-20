<?php

declare(strict_types=1);

namespace App\Http\Requests\Bank;

use Illuminate\Foundation\Http\FormRequest;

/** `CloneFromBankRequest` in openapi.yml. */
class CloneFromBankRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bank_problem_id' => ['required', 'integer', 'exists:bank_problems,id'],
            'group_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'order' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'activation_time' => ['sometimes', 'nullable', 'date'],
            'lock_time' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
