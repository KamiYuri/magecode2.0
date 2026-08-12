<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiDetectionResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $analysis_submission_id
 * @property string $probability
 * @property Carbon $created_at
 */
class AiDetectionResult extends Model
{
    /** @use HasFactory<AiDetectionResultFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['analysis_submission_id', 'probability', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['probability' => 'decimal:4', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<AnalysisSubmission, $this> */
    public function analysisSubmission(): BelongsTo
    {
        return $this->belongsTo(AnalysisSubmission::class);
    }
}
