<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** `ChangePasswordRequest` in openapi.yml. */
class ChangePasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `current_password` is Laravel's own rule: it checks the value
            // against the authenticated user's hash.
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
