<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Difficulty;
use App\Enums\PublishMode;
use Database\Factories\ProblemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/** Soft-deleted (D-43); force delete is never allowed once submissions exist.
 *
 * @property int $id
 * @property int $section_id
 * @property int|null $bank_problem_id
 * @property int $creator_id
 * @property string $name
 * @property string $description
 * @property Difficulty $difficulty
 * @property string|null $group_label
 * @property int|null $order
 * @property int|null $max_submissions
 * @property int $time_limit
 * @property int $memory_limit
 * @property Carbon|null $activation_time
 * @property Carbon|null $lock_time
 * @property PublishMode|null $publish_mode_override
 * @property PublishMode|null $lock_mode_override
 * @property bool $is_published
 * @property bool $is_locked
 * @property string|null $manual_match_group_id
 * @property Carbon|null $testcases_updated_at
 * @property Carbon|null $deleted_at
 */
class Problem extends Model
{
    /** @use HasFactory<ProblemFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'section_id', 'bank_problem_id', 'creator_id', 'name', 'description', 'difficulty',
        'group_label', 'order', 'max_submissions', 'time_limit', 'memory_limit',
        'activation_time', 'lock_time', 'publish_mode_override', 'lock_mode_override',
        'is_published', 'is_locked', 'manual_match_group_id', 'testcases_updated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'difficulty' => Difficulty::class,
            'publish_mode_override' => PublishMode::class,
            'lock_mode_override' => PublishMode::class,
            'is_published' => 'boolean',
            'is_locked' => 'boolean',
            'activation_time' => 'datetime',
            'lock_time' => 'datetime',
            'testcases_updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<BankProblem, $this> */
    public function bankProblem(): BelongsTo
    {
        return $this->belongsTo(BankProblem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** @return HasMany<TestCase, $this> */
    public function testCases(): HasMany
    {
        return $this->hasMany(TestCase::class);
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /** @return HasMany<ProblemEditLog, $this> */
    public function editLogs(): HasMany
    {
        return $this->hasMany(ProblemEditLog::class);
    }

    /** @return HasMany<AnalysisProblem, $this> */
    public function triggeredAnalyses(): HasMany
    {
        return $this->hasMany(AnalysisProblem::class, 'triggered_by_problem_id');
    }

    /** @return BelongsToMany<ProgrammingLanguage, $this> */
    public function programmingLanguages(): BelongsToMany
    {
        return $this->belongsToMany(ProgrammingLanguage::class, 'problem_programming_languages');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'problem_tags');
    }
}
