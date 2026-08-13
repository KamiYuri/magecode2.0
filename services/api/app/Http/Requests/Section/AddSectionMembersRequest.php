<?php

declare(strict_types=1);

namespace App\Http\Requests\Section;

use App\Enums\SectionRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddSectionMembersRequest extends FormRequest
{
    /** A section is one class; a roster larger than this is an import. */
    private const MAX_MEMBERS_PER_REQUEST = 200;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'members' => ['required', 'array', 'min:1', 'max:'.self::MAX_MEMBERS_PER_REQUEST],
            'members.*.email' => ['required', 'email', 'max:255'],
            'members.*.role' => ['required', Rule::enum(SectionRole::class)],
        ];
    }
}
