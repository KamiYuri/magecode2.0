<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Grading finished (U-8). The last frame a submission produces; the browser
 * turns it into the toast `Đã chấm xong: 7/10 test` (ui-ux §4.2).
 */
class ExecutionCompleted implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly int $submissionId,
        public readonly string $finalStatus,
        public readonly int $testcasesPassed,
        public readonly int $testcasesTotal,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('submission.'.$this->submissionId);
    }

    public function broadcastAs(): string
    {
        return 'execution.completed';
    }

    /**
     * `final_status`, not `status`: openapi names it differently from
     * `execution.updated` because this one cannot change afterwards.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'submission_id' => $this->submissionId,
            'final_status' => $this->finalStatus,
            'testcases_passed' => $this->testcasesPassed,
            'testcases_total' => $this->testcasesTotal,
        ];
    }
}
