<?php

declare(strict_types=1);

namespace App\Http\Requests\Semester;

use App\Enums\PublishMode;
use App\Http\Requests\Concerns\ValidatesSemesterDates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSemesterRequest extends FormRequest
{
    use ValidatesSemesterDates;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The thresholds are bounded here and nowhere else: the column is
     * decimal(3,2), which would happily store 9.99.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'publish_mode' => ['sometimes', Rule::enum(PublishMode::class)],
            'lock_mode' => ['sometimes', Rule::enum(PublishMode::class)],
            'allow_publish_override' => ['sometimes', 'boolean'],
            'allow_lock_override' => ['sometimes', 'boolean'],
            'similarity_threshold' => ['sometimes', 'numeric', 'min:0', 'max:1', 'decimal:0,2'],
            'ai_detection_threshold' => ['sometimes', 'numeric', 'min:0', 'max:1', 'decimal:0,2'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ];
    }
}
