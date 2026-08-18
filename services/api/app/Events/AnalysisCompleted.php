<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\AnalysisStatus;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A batch ended (U-8). The last frame it produces.
 *
 * `status` carries the ending rather than being implied, which is what lets
 * D7's sweeper announce a `timeout` on the same channel instead of needing an
 * event of its own.
 */
class AnalysisCompleted implements ShouldBroadcast
{
    use Dispatchable;

    /** @param list<int> $sectionIds */
    public function __construct(
        public readonly int $analysisProblemId,
        public readonly AnalysisStatus $status,
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
        return 'analysis.completed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'analysis_problem_id' => $this->analysisProblemId,
            'status' => $this->status->value,
        ];
    }
}
