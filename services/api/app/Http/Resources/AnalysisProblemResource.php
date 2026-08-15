<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AnalysisProblem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `AnalysisProblem` schema in openapi.yml.
 *
 * The scope columns are deliberately absent: `bank_problem_id` and
 * `manual_match_group_id` are how the batch is addressed internally, and the
 * contract exposes `triggered_by_problem_id` instead — the problem the person
 * actually clicked.
 *
 * @mixin AnalysisProblem
 */
class AnalysisProblemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $total = $this->submissions_count ?? $this->analysisSubmissions()->count();
        $completed = $this->completed_count ?? $this->analysisSubmissions()->whereNotNull('completed_at')->count();

        return [
            'id' => $this->id,
            'semester_id' => $this->semester_id,
            'triggered_by_problem_id' => $this->triggered_by_problem_id,
            'analyst' => new UserSummaryResource($this->whenLoaded('analyst')),
            'services' => $this->services,
            'status' => $this->status,
            'is_latest' => $this->is_latest,
            'is_partial' => $this->is_partial,
            'submissions_count' => $total,
            'completed_count' => $completed,
            'progress_percent' => $this->progressPercent($completed, $total),
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * A batch with no submissions cannot be created (D1 answers 422 first), so
     * the zero guard is for a batch whose rows were removed, not for the
     * ordinary path.
     */
    private function progressPercent(int $completed, int $total): int
    {
        return $total === 0 ? 0 : (int) round($completed / $total * 100);
    }
}
