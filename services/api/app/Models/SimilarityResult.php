<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MatchType;
use Database\Factories\SimilarityResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** One row per ordered pair: submission_a_id < submission_b_id, enforced by
 *  chk_similarity_pair_order. Query both columns to find a submission's matches.
 *
 * @property int $id
 * @property int $analysis_problem_id
 * @property int $submission_a_id
 * @property int $submission_b_id
 * @property string $similarity
 * @property int|null $longest_fragment
 * @property int|null $total_overlap
 * @property MatchType $match_type
 * @property string|null $a_regions
 * @property string|null $b_regions
 * @property Carbon $created_at
 */
class SimilarityResult extends Model
{
    /** @use HasFactory<SimilarityResultFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'analysis_problem_id', 'submission_a_id', 'submission_b_id', 'similarity',
        'longest_fragment', 'total_overlap', 'match_type', 'a_regions', 'b_regions', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'similarity' => 'decimal:4',
            'match_type' => MatchType::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AnalysisProblem, $this> */
    public function analysisProblem(): BelongsTo
    {
        return $this->belongsTo(AnalysisProblem::class);
    }

    /** @return BelongsTo<Submission, $this> */
    public function submissionA(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_a_id');
    }

    /** @return BelongsTo<Submission, $this> */
    public function submissionB(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_b_id');
    }
}
