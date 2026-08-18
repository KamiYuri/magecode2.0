<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\ServiceStatus;
use App\Models\AnalysisSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes one AID result: at most one probability row per analysis submission,
 * plus that submission's `ai_detection_status`.
 *
 * `ai_detection_results` is unique on `analysis_submission_id`, so redelivery
 * upserts rather than duplicating — the constraint is the reason the roadmap
 * names this case.
 */
class AiDetectionResultIngestor
{
    /** @param array<string, mixed> $message */
    public function ingest(array $message): void
    {
        $entryId = (int) ($message['analysis_submission_id'] ?? 0);
        $entry = AnalysisSubmission::find($entryId);

        if ($entry === null) {
            // Nowhere to write it and no later delivery will change that.
            Log::warning('ai detection result names an unknown analysis submission', [
                'analysis_submission_id' => $entryId,
                'trace_id' => $message['trace_id'] ?? null,
            ]);

            return;
        }

        $probability = $this->probability($message);
        $status = $this->statusFor((string) ($message['status'] ?? 'error'), $probability, $message);

        DB::transaction(function () use ($entryId, $probability, $status): void {
            if ($probability !== null) {
                DB::table('ai_detection_results')->upsert(
                    [[
                        'analysis_submission_id' => $entryId,
                        'probability' => $probability,
                        'created_at' => now(),
                    ]],
                    ['analysis_submission_id'],
                    ['probability', 'created_at'],
                );
            }

            DB::table('analysis_submissions')
                ->where('id', $entryId)
                ->update(['ai_detection_status' => $status->value, 'updated_at' => now()]);
        });
    }

    /** @param array<string, mixed> $message */
    private function probability(array $message): ?float
    {
        $probability = $message['probability'] ?? null;

        return $probability === null ? null : (float) $probability;
    }

    /**
     * `completed` with no probability is a shape the schema permits and the
     * data model cannot hold — `probability` is NOT NULL, and a result row is
     * the only thing "completed" would mean to a reader. It is recorded as an
     * error rather than as a completion with nothing behind it.
     *
     * @param  array<string, mixed>  $message
     */
    private function statusFor(string $status, ?float $probability, array $message): ServiceStatus
    {
        if ($status === 'completed' && $probability === null) {
            Log::warning('ai detection result is completed but carries no probability', [
                'analysis_submission_id' => $message['analysis_submission_id'] ?? null,
                'trace_id' => $message['trace_id'] ?? null,
            ]);

            return ServiceStatus::Error;
        }

        return match ($status) {
            'completed' => ServiceStatus::Completed,
            'not_applicable' => ServiceStatus::NotApplicable,
            default => ServiceStatus::Error,
        };
    }
}
