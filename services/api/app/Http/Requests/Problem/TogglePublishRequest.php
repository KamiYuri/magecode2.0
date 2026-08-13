<?php

declare(strict_types=1);

namespace App\Http\Requests\Problem;

use Illuminate\Foundation\Http\FormRequest;

class TogglePublishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['is_published' => ['required', 'boolean']];
    }
}
