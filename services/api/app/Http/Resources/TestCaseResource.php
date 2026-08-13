<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `TestCase` schema in openapi.yml. Reaching a student only through
 * `sample_test_cases`, which is filtered to `is_visible` rows.
 *
 * @mixin TestCase
 */
class TestCaseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'input' => $this->input,
            'expected_output' => $this->expected_output,
            'is_active' => $this->is_active,
            'is_visible' => $this->is_visible,
            'order' => $this->order,
        ];
    }
}
