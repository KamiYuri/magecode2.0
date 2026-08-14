<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Events\ExecutionCompleted;
use App\Events\ExecutionUpdated;
use App\Models\Submission;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Turns a `result.execution` message into the WebSocket frame the student's
 * browser is waiting for (U-8, D-83).
 *
 * It reads and broadcasts, and writes nothing: CES already wrote the verdict
 * and the counters (D-81), and the message carries them only so api does not
 * have to query for them. Writing them back here would give the same field two
 * authors and a race between them.
 */
class ExecutionResultHandler
{
    private const REQUIRED = [
        'submission_id', 'status', 'execution_status', 'testcases_passed', 'testcases_total',
    ];

    /** @throws InvalidResultMessage */
    public function handle(string $body): void
    {
        $message = $this->decode($body);

        // The submission is looked up rather than trusted: a message naming a
        // row that is gone would otherwise broadcast on a channel nobody can
        // authorise, and the id is the only thing the frame is addressed by.
        $submission = Submission::find($message['submission_id']);

        if ($submission === null) {
            Log::warning('result message names an unknown submission', [
                'submission_id' => $message['submission_id'],
                'trace_id' => $message['trace_id'] ?? null,
            ]);

            return;
        }

        match ($message['status']) {
            'processing' => $this->broadcastProgress($message),
            'completed', 'error' => $this->broadcastCompletion($message),
            default => throw InvalidResultMessage::unknownStatus((string) $message['status']),
        };
    }

    /** @param array<string, mixed> $message */
    private function broadcastProgress(array $message): void
    {
        /** @var array{test_case_order: int, status: string} $latest */
        $latest = $message['latest_result'] ?? ['test_case_order' => 0, 'status' => 'internal_error'];

        ExecutionUpdated::dispatch(
            (int) $message['submission_id'],
            (string) $message['execution_status'],
            (int) $message['testcases_passed'],
            (int) $message['testcases_total'],
            $latest,
        );
    }

    /** @param array<string, mixed> $message */
    private function broadcastCompletion(array $message): void
    {
        ExecutionCompleted::dispatch(
            (int) $message['submission_id'],
            (string) $message['execution_status'],
            (int) $message['testcases_passed'],
            (int) $message['testcases_total'],
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidResultMessage
     */
    private function decode(string $body): array
    {
        try {
            /** @var array<string, mixed> $message */
            $message = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw InvalidResultMessage::notJson($failure->getMessage());
        }

        $missing = array_values(array_diff(self::REQUIRED, array_keys($message)));

        if ($missing !== []) {
            throw InvalidResultMessage::missingFields($missing);
        }

        return $message;
    }
}
