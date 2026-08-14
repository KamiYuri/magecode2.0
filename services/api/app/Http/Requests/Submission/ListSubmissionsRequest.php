<?php

declare(strict_types=1);

namespace App\Http\Requests\Submission;

use App\Enums\ExecutionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters shared by both listings. `problem_id` is only meaningful on
 * the section-wide one; naming it on a problem listing is harmless, since the
 * query already pins that problem.
 */
class ListSubmissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['sometimes', Rule::in(['all', 'best', 'latest'])],
            'status' => ['sometimes', Rule::enum(ExecutionStatus::class)],
            'student_id' => ['sometimes', 'integer'],
            'problem_id' => ['sometimes', 'integer'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->safe()->only(['mode', 'status', 'student_id', 'problem_id']);
    }
}
