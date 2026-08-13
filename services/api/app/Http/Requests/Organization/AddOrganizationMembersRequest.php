<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddOrganizationMembersRequest extends FormRequest
{
    /** One batch stays small enough to run inside a single transaction. */
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
            'members.*.role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }
}
