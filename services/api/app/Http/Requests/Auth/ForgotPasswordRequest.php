<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Deliberately no `exists` rule: the endpoint must not reveal which
        // addresses have accounts.
        return ['email' => ['required', 'string', 'email']];
    }
}
