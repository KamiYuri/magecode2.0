<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A batch moved forward (U-8): one or more submissions finished every service
 * they were waiting on.
 *
 * Fired when `completed_count` actually rises, not once per result message —
 * a single SIM message closes a whole language group at once, and openapi
 * declares the payload without a cadence (v3 §7, 2026-08-18).
 *
 * A batch spans a semester, so the frame goes to every section in scope: one
 * event over many channels rather than one dispatch per section.
 */
class AnalysisProgress implements ShouldBroadcast
{
    use Dispatchable;

    /** @param list<int> $sectionIds */
    public function __construct(
        public readonly int $analysisProblemId,
        public readonly int $completedCount,
        public readonly int $totalCount,
        public readonly array $sectionIds,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (int $sectionId): PrivateChannel => new PrivateChannel('section.'.$sectionId),
            $this->sectionIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'analysis.progress';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'analysis_problem_id' => $this->analysisProblemId,
            'completed_count' => $this->completedCount,
            'total_count' => $this->totalCount,
        ];
    }
}
