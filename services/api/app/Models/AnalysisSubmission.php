<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceStatus;
use Database\Factories\AnalysisSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $submission_id
 * @property int $analysis_problem_id
 * @property ServiceStatus $plagiarism_status
 * @property ServiceStatus $ai_detection_status
 * @property ServiceStatus $vuln_scan_status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class AnalysisSubmission extends Model
{
    /** @use HasFactory<AnalysisSubmissionFactory> */
    use HasFactory;

    protected $fillable = [
        'submission_id', 'analysis_problem_id',
        'plagiarism_status', 'ai_detection_status', 'vuln_scan_status',
        'started_at', 'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'plagiarism_status' => ServiceStatus::class,
            'ai_detection_status' => ServiceStatus::class,
            'vuln_scan_status' => ServiceStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<AnalysisProblem, $this> */
    public function analysisProblem(): BelongsTo
    {
        return $this->belongsTo(AnalysisProblem::class);
    }

    /** @return HasOne<AiDetectionResult, $this> */
    public function aiDetectionResult(): HasOne
    {
        return $this->hasOne(AiDetectionResult::class);
    }

    /** @return HasMany<VulnerabilityResult, $this> */
    public function vulnerabilityResults(): HasMany
    {
        return $this->hasMany(VulnerabilityResult::class);
    }
}
