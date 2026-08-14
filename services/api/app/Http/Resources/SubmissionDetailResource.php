<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Submission;
use Illuminate\Http\Request;

/**
 * The `SubmissionDetail` schema in openapi.yml: a Submission plus its
 * per-test-case results and, on request, the source itself.
 *
 * The source is passed in rather than read here — fetching it costs a storage
 * round-trip, and a resource that reached for MinIO on its own would do so
 * once per row the moment someone used this in a collection.
 *
 * @mixin Submission
 */
class SubmissionDetailResource extends SubmissionResource
{
    public function __construct(
        Submission $resource,
        private readonly ?string $sourceCode,
        private readonly bool $revealHiddenTestCases,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'source_code' => $this->sourceCode,
            'execution_results' => CodeExecutionResultResource::forViewer(
                $this->executionResults,
                $this->revealHiddenTestCases,
            ),
        ];
    }
}
