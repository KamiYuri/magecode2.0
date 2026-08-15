<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Models\Problem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which problems one analysis batch covers.
 *
 * Exactly one identifier is set — `chk_analysis_scope` enforces it in the
 * database and schema §5.2 resolves each in its own branch, so a scope holding
 * both would describe two different sets of problems (v3 §7, 2026-08-14).
 */
final readonly class AnalysisScope
{
    private function __construct(
        public int $semesterId,
        public ?int $bankProblemId,
        public ?string $manualMatchGroupId,
    ) {}

    /** Auto-matched: every problem cloned from the same bank entry (D-47). */
    public static function bank(int $semesterId, int $bankProblemId): self
    {
        return new self($semesterId, $bankProblemId, null);
    }

    /** Manually grouped, or a group of one generated at trigger time (D-58). */
    public static function manual(int $semesterId, string $matchGroupId): self
    {
        return new self($semesterId, null, $matchGroupId);
    }

    /**
     * The problems in scope — the Eloquent form of schema §5.2's pseudocode.
     * One branch, never both.
     *
     * @return Builder<Problem>
     */
    public function problems(): Builder
    {
        $query = Problem::query()
            ->whereHas('section', fn (Builder $section) => $section->where('semester_id', $this->semesterId));

        return $this->bankProblemId !== null
            ? $query->where('bank_problem_id', $this->bankProblemId)
            : $query->where('manual_match_group_id', $this->manualMatchGroupId);
    }

    /** @return array<string, mixed> the two scope columns of analysis_problems */
    public function columns(): array
    {
        return [
            'semester_id' => $this->semesterId,
            'bank_problem_id' => $this->bankProblemId,
            'manual_match_group_id' => $this->manualMatchGroupId,
        ];
    }
}
