<?php

declare(strict_types=1);

namespace App\Http\Requests\Tag;

use Illuminate\Foundation\Http\FormRequest;

/** `CreateTagRequest` in openapi.yml — also the body of the update. */
class StoreTagRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            // A CSS hex colour. Nullable because openapi says so: a tag with
            // no colour renders in the default chip style.
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/', 'max:7'],
        ];
    }
}
