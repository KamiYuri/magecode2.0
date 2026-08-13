<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Difficulty;
use App\Enums\PublishMode;
use App\Observers\ProblemObserver;
use Database\Factories\ProblemFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
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
 * @property-read int|null $submissions_count
 * @property-read int|null $my_submissions_count
 * @property-read string|null $my_best_status  Only present after scopeWithMyProgress()
 */
#[ObservedBy(ProblemObserver::class)]
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

    /**
     * The SQL mirror of ProblemVisibilityService::isVisible(), for listings
     * that cannot ask row by row. The two must agree — a student who sees a
     * row here and `is_visible: false` on it would be reading a bug.
     *
     * @param  Builder<Problem>  $query
     */
    public function scopeVisibleIn(Builder $query, Semester $semester): void
    {
        if (! $semester->allow_publish_override) {
            self::constrainOpen($query, $semester->publish_mode);

            return;
        }

        $query->where(function (Builder $outer) use ($semester): void {
            $outer
                ->where(function (Builder $row) use ($semester): void {
                    $row->whereNull('publish_mode_override');
                    self::constrainOpen($row, $semester->publish_mode);
                })
                ->orWhere(function (Builder $row): void {
                    $row->where('publish_mode_override', PublishMode::Auto->value);
                    self::constrainOpen($row, PublishMode::Auto);
                })
                ->orWhere(function (Builder $row): void {
                    $row->where('publish_mode_override', PublishMode::Manual->value);
                    self::constrainOpen($row, PublishMode::Manual);
                });
        });
    }

    /**
     * The caller's own standing. "Best" is the submission that passed the most
     * test cases, the most recent one breaking a tie — the ranking a student
     * would apply themselves.
     *
     * @param  Builder<Problem>  $query
     */
    public function scopeWithMyProgress(Builder $query, User $user): void
    {
        $query
            ->withCount(['submissions as my_submissions_count' => function (Builder $mine) use ($user): void {
                $mine->where('creator_id', $user->id);
            }])
            ->addSelect(['my_best_status' => Submission::query()
                ->select('execution_status')
                ->whereColumn('problem_id', 'problems.id')
                ->where('creator_id', $user->id)
                ->orderByDesc('testcases_passed')
                ->orderByDesc('id')
                ->limit(1),
            ]);
    }

    /** @param  Builder<Problem>  $query */
    private static function constrainOpen(Builder $query, PublishMode $mode): void
    {
        if ($mode === PublishMode::Auto) {
            $query->whereNotNull('activation_time')->where('activation_time', '<=', now());

            return;
        }

        $query->where('is_published', true);
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

    /**
     * The subset a student may read. A separate relation rather than a filter
     * at the call site, so no reader can eager-load the full set by accident.
     *
     * @return HasMany<TestCase, $this>
     */
    public function sampleTestCases(): HasMany
    {
        return $this->hasMany(TestCase::class)->where('is_visible', true)->orderBy('order');
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
