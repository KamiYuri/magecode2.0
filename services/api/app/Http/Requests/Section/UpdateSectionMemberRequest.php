<?php

declare(strict_types=1);

namespace App\Http\Requests\Section;

use App\Enums\SectionRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSectionMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['role' => ['required', Rule::enum(SectionRole::class)]];
    }
}
