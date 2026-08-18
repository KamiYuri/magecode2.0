<?php

declare(strict_types=1);

namespace App\Http\Resources\Analysis;

use App\Models\SimilarityResult;
use Illuminate\Http\Request;

/**
 * `SimilarityResultDetail`: the list shape plus the two sources and their
 * highlight regions — the only place in the API where one student's code is
 * shown beside another's.
 *
 * Each side is decided on its own (D-05/D-06). An instructor of L01 reading a
 * cross-section pair sees their own student's code and a null where the other
 * would be; an instructor of neither section sees two nulls. The **regions
 * follow their side**: a highlight without its code points into something the
 * reader may not see.
 *
 * The controller fetches only the objects this same context calls visible, so
 * the redacted side is never read from storage. Nulling here as well is
 * deliberate belt-and-braces on the surface where a mistake is unrecoverable.
 */
class SimilarityResultDetailResource extends SimilarityResultResource
{
    public function __construct(
        SimilarityResult $resource,
        AnalysisReadContext $context,
        private readonly ?string $aSourceCode,
        private readonly ?string $bSourceCode,
    ) {
        parent::__construct($resource, $context);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $aVisible = $this->context->maySeeSourceOf($this->submissionA?->problem->section_id);
        $bVisible = $this->context->maySeeSourceOf($this->submissionB?->problem->section_id);

        return parent::toArray($request) + [
            'a_source_code' => $aVisible ? $this->aSourceCode : null,
            'b_source_code' => $bVisible ? $this->bSourceCode : null,
            'a_regions' => $aVisible ? $this->a_regions : null,
            'b_regions' => $bVisible ? $this->b_regions : null,
        ];
    }
}
