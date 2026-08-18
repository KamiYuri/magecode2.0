<?php

declare(strict_types=1);

namespace App\Services\Analysis;

use App\Enums\ServiceStatus;
use App\Enums\Severity;
use App\Models\AnalysisSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes one VUL result: this submission's CodeQL findings and its
 * `vuln_scan_status`.
 *
 * Unlike AID, `vulnerability_results` has no unique key to upsert on — a
 * submission can carry any number of findings and two of them can legitimately
 * be identical but for their line numbers. Redelivery is therefore made
 * idempotent by replacing the submission's findings wholesale inside the
 * transaction; appending would double every finding on the second delivery.
 */
class VulnScanResultIngestor
{
    /** @param array<string, mixed> $message */
    public function ingest(array $message): void
    {
        $entryId = (int) ($message['analysis_submission_id'] ?? 0);
        $entry = AnalysisSubmission::find($entryId);

        if ($entry === null) {
            Log::warning('vulnerability result names an unknown analysis submission', [
                'analysis_submission_id' => $entryId,
                'trace_id' => $message['trace_id'] ?? null,
            ]);

            return;
        }

        $status = $this->statusFor((string) ($message['status'] ?? 'error'));
        $findings = $status === ServiceStatus::Completed ? $this->rows($entryId, $message) : [];

        DB::transaction(function () use ($entryId, $status, $findings): void {
            // Replace, not append. An empty findings list is a clean scan, and
            // a re-scan that now finds nothing must leave nothing behind.
            DB::table('vulnerability_results')->where('analysis_submission_id', $entryId)->delete();

            if ($findings !== []) {
                DB::table('vulnerability_results')->insert($findings);
            }

            DB::table('analysis_submissions')
                ->where('id', $entryId)
                ->update(['vuln_scan_status' => $status->value, 'updated_at' => now()]);
        });
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    private function rows(int $entryId, array $message): array
    {
        /** @var list<array<string, mixed>> $findings */
        $findings = $message['findings'] ?? [];
        $rows = [];

        foreach ($findings as $finding) {
            $severity = Severity::tryFrom((string) ($finding['severity'] ?? ''));

            if ($severity === null || ($finding['name'] ?? null) === null) {
                // The schema requires both; a finding missing either cannot be
                // rendered or filtered, so it is dropped rather than stored as
                // a row nothing can read.
                Log::warning('vulnerability finding is missing its name or severity', [
                    'analysis_submission_id' => $entryId,
                    'severity' => $finding['severity'] ?? null,
                    'trace_id' => $message['trace_id'] ?? null,
                ]);

                continue;
            }

            $rows[] = [
                'analysis_submission_id' => $entryId,
                'name' => (string) $finding['name'],
                'description' => $finding['description'] ?? null,
                'severity' => $severity->value,
                'file_path' => $finding['file_path'] ?? null,
                'start_line' => $finding['start_line'] ?? null,
                'start_column' => $finding['start_column'] ?? null,
                'end_line' => $finding['end_line'] ?? null,
                'end_column' => $finding['end_column'] ?? null,
                'created_at' => now(),
            ];
        }

        return $rows;
    }

    private function statusFor(string $status): ServiceStatus
    {
        return match ($status) {
            'completed' => ServiceStatus::Completed,
            'not_applicable' => ServiceStatus::NotApplicable,
            default => ServiceStatus::Error,
        };
    }
}
