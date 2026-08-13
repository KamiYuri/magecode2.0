<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * `?include=a,b` — the comma-separated form openapi.yml declares
 * (`style: form, explode: false`). Split before validation so an unknown
 * relation fails as `include.0` rather than silently loading nothing.
 *
 * The eager-load names are the API's, not Eloquent's: `programming_languages`
 * is what a client asks for, `programmingLanguages` is what Eloquent loads.
 */
trait AcceptsIncludes
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('include')) {
            return;
        }

        $raw = $this->input('include');
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);

        $this->merge([
            'include' => array_values(array_filter(array_map('trim', $values), fn (string $v) => $v !== '')),
        ]);
    }

    /** @return array<int, string> */
    public function includes(): array
    {
        /** @var array<int, string>|null $includes */
        $includes = $this->validated('include');

        return $includes ?? [];
    }

    public function wants(string $relation): bool
    {
        return in_array($relation, $this->includes(), true);
    }
}
