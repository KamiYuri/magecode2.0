<?php

declare(strict_types=1);

namespace App\Http\Requests\Analysis;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D-58: link problems written independently in different sections so SIM
 * compares them as one group.
 *
 * Only the shape is checked here. Which problems may legally be linked —
 * same semester, no bank problem, no run in flight — is a rule about the
 * database's state rather than the payload, so it lives in the service and
 * comes back as a 422 with a code.
 */
class SetMatchGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Two, because a group of one is what the trigger already
            // generates on its own (v3 §7, 2026-08-14).
            'problem_ids' => ['required', 'array', 'min:2'],
            'problem_ids.*' => ['integer', 'min:1'],
        ];
    }

    /** @return list<int> */
    public function problemIds(): array
    {
        /** @var list<int> $ids */
        $ids = $this->safe()->array('problem_ids');

        return array_values(array_unique(array_map(intval(...), $ids)));
    }
}
