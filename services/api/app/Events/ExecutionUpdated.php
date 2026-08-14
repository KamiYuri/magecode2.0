<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One test case finished (U-8). This is what fills a single cell of the
 * verdict strip while grading is still running (ui-ux §4.2).
 *
 * CES has already written the row; this only tells the browser to look.
 */
class ExecutionUpdated implements ShouldBroadcast
{
    use Dispatchable;

    /** @param array{test_case_order: int, status: string} $latestResult */
    public function __construct(
        public readonly int $submissionId,
        public readonly string $status,
        public readonly int $testcasesPassed,
        public readonly int $testcasesTotal,
        public readonly array $latestResult,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('submission.'.$this->submissionId);
    }

    public function broadcastAs(): string
    {
        return 'execution.updated';
    }

    /**
     * The payload openapi declares for this event.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'submission_id' => $this->submissionId,
            'status' => $this->status,
            'testcases_passed' => $this->testcasesPassed,
            'testcases_total' => $this->testcasesTotal,
            'latest_result' => $this->latestResult,
        ];
    }
}
