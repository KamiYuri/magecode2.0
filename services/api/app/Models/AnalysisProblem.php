<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalysisStatus;
use Database\Factories\AnalysisProblemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** One analysis batch. Scope is semester-wide for SIM: every equivalent
 *  problem across sections, matched by bank_problem_id or manual_match_group_id.
 *
 * @property int $id
 * @property int $semester_id
 * @property int|null $bank_problem_id
 * @property string|null $manual_match_group_id
 * @property int $triggered_by_problem_id
 * @property int $analyst_id
 * @property list<string> $services
 * @property AnalysisStatus $status
 * @property bool $is_latest
 * @property bool $is_partial
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class AnalysisProblem extends Model
{
    /** @use HasFactory<AnalysisProblemFactory> */
    use HasFactory;

    protected $fillable = [
        'semester_id', 'bank_problem_id', 'manual_match_group_id', 'triggered_by_problem_id',
        'analyst_id', 'services', 'status', 'is_latest', 'is_partial', 'started_at', 'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'services' => 'array',
            'status' => AnalysisStatus::class,
            'is_latest' => 'boolean',
            'is_partial' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Semester, $this> */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /** @return BelongsTo<BankProblem, $this> */
    public function bankProblem(): BelongsTo
    {
        return $this->belongsTo(BankProblem::class);
    }

    /** @return BelongsTo<Problem, $this> */
    public function triggeredByProblem(): BelongsTo
    {
        return $this->belongsTo(Problem::class, 'triggered_by_problem_id');
    }

    /** @return BelongsTo<User, $this> */
    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyst_id');
    }

    /** @return HasMany<AnalysisSubmission, $this> */
    public function analysisSubmissions(): HasMany
    {
        return $this->hasMany(AnalysisSubmission::class);
    }

    /** @return HasMany<SimilarityResult, $this> */
    public function similarityResults(): HasMany
    {
        return $this->hasMany(SimilarityResult::class);
    }
}
