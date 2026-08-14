<?php

declare(strict_types=1);

namespace App\Http\Requests\Submission;

use App\Models\ProgrammingLanguage;
use App\Services\SubmissionStorageService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Upload path. The extension allowlist comes from the chosen language's
 * `file_extensions` (U-4), so the rule has to read the language the same
 * request is selecting — hence a closure rather than a static `mimes:` list.
 */
class UploadSubmissionRequest extends FormRequest
{
    /** Laravel sizes uploads in kilobytes of 1024 bytes. */
    private const MAX_KILOBYTES = SubmissionStorageService::MAX_FILE_BYTES / 1024;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'programming_language_id' => ['required', 'integer', 'exists:programming_languages,id'],
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, $this->extensionAllowedByLanguage()],
        ];
    }

    private function extensionAllowedByLanguage(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $language = ProgrammingLanguage::find($this->input('programming_language_id'));

            // A missing or unknown language is already the other rule's failure;
            // reporting it twice would just clutter the errors map.
            if ($language === null || ! $value instanceof UploadedFile) {
                return;
            }

            if (! $language->allowsExtension((string) $value->getClientOriginalExtension())) {
                $fail(sprintf(
                    'The file extension must be one of: %s.',
                    implode(', ', $language->file_extensions),
                ));
            }
        };
    }
}
