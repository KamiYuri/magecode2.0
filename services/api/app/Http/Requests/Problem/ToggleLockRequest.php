<?php

declare(strict_types=1);

namespace App\Http\Requests\Problem;

use Illuminate\Foundation\Http\FormRequest;

class ToggleLockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['is_locked' => ['required', 'boolean']];
    }
}
