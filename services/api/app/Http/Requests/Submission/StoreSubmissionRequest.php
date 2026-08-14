<?php

declare(strict_types=1);

namespace App\Http\Requests\Submission;

use App\Services\SubmissionStorageService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editor path: the source arrives as text and the file is named after the
 * language's canonical extension. Whether the language is *allowed on this
 * problem* is a rule about the problem, not the payload, so it stays in
 * SubmissionService with its own 422 code.
 */
class StoreSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'programming_language_id' => ['required', 'integer', 'exists:programming_languages,id'],
            'source_code' => ['required', 'string', $this->withinByteLimit()],
        ];
    }

    /**
     * D-34, corrected to 50KB in C1. Measured in bytes rather than with
     * `max:`, which counts characters — a Vietnamese comment would otherwise
     * pass validation and then be refused by the storage service, turning a
     * 422 into a 500.
     */
    private function withinByteLimit(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > SubmissionStorageService::MAX_FILE_BYTES) {
                $fail("The :attribute may not be greater than ".SubmissionStorageService::MAX_FILE_BYTES.' bytes.');
            }
        };
    }
}
