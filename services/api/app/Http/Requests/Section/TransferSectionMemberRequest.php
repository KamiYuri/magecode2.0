<?php

declare(strict_types=1);

namespace App\Http\Requests\Section;

use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferSectionMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $section = $this->route('section');

        return [
            'to_section_id' => [
                'required',
                'integer',
                'exists:sections,id',
                // Moving a student into the section they are already in is a
                // no-op that would still write an audit row.
                Rule::notIn([$section instanceof Section ? $section->id : 0]),
            ],
        ];
    }
}
