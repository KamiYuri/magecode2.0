<?php

declare(strict_types=1);

namespace App\Http\Requests\Problem;

use App\Enums\Difficulty;
use App\Enums\PublishMode;
use App\Models\Problem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProblemRequest extends FormRequest
{
    /**
     * Fields that change how already-submitted work would be judged. They are
     * frozen while the problem is locked, and touching them marks existing
     * submissions outdated (D-41). Test cases are the fourth core field and
     * live behind their own endpoint.
     */
    public const CORE_FIELDS = ['time_limit', 'memory_limit', 'programming_language_ids'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'difficulty' => ['sometimes', Rule::enum(Difficulty::class)],
            'group_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'order' => ['sometimes', 'nullable', 'integer'],
            'max_submissions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'time_limit' => ['sometimes', 'integer', 'min:100', 'max:30000'],
            'memory_limit' => ['sometimes', 'integer', 'min:1024', 'max:524288'],
            'activation_time' => ['sometimes', 'nullable', 'date'],
            'lock_time' => ['sometimes', 'nullable', 'date'],
            'publish_mode_override' => ['sometimes', 'nullable', Rule::enum(PublishMode::class)],
            'lock_mode_override' => ['sometimes', 'nullable', Rule::enum(PublishMode::class)],
            'programming_language_ids' => ['sometimes', 'array', 'min:1'],
            'programming_language_ids.*' => ['integer', 'exists:programming_languages,id'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', $this->tagBelongsToCourse()],
        ];
    }

    /** Whether the payload asks to touch anything a locked problem protects. */
    public function touchesCoreFields(): bool
    {
        return $this->hasAny(self::CORE_FIELDS);
    }

    private function tagBelongsToCourse(): mixed
    {
        $problem = $this->route('problem');

        return Rule::exists('tags', 'id')->where(
            'course_id',
            $problem instanceof Problem ? $problem->section->semester->course_id : 0
        );
    }
}
