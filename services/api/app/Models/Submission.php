<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExecutionStatus;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Never deleted (D-52). execution_status/testcases_* are written by CES (D-81).
 *
 * @property int $id
 * @property int $problem_id
 * @property int $creator_id
 * @property int $programming_language_id
 * @property string $file_path
 * @property string $file_name
 * @property ExecutionStatus $execution_status
 * @property int $testcases_passed
 * @property int $testcases_total
 * @property bool $is_outdated
 */
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    protected $fillable = [
        'problem_id', 'creator_id', 'programming_language_id', 'file_path', 'file_name',
        'execution_status', 'testcases_passed', 'testcases_total', 'is_outdated',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'execution_status' => ExecutionStatus::InQueue->value,
        'testcases_passed' => 0,
        'testcases_total' => 0,
        'is_outdated' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['execution_status' => ExecutionStatus::class, 'is_outdated' => 'boolean'];
    }

    /** @return BelongsTo<Problem, $this> */
    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** @return BelongsTo<ProgrammingLanguage, $this> */
    public function programmingLanguage(): BelongsTo
    {
        return $this->belongsTo(ProgrammingLanguage::class);
    }

    /** @return HasMany<CodeExecutionResult, $this> */
    public function executionResults(): HasMany
    {
        return $this->hasMany(CodeExecutionResult::class);
    }

    /** @return HasMany<AnalysisSubmission, $this> */
    public function analysisSubmissions(): HasMany
    {
        return $this->hasMany(AnalysisSubmission::class);
    }
}
