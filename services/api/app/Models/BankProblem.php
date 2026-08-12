<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BankProblemStatus;
use App\Enums\Difficulty;
use Database\Factories\BankProblemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/** Fork model (D-07): original_id is NULL on the first version and points at
 *  it on every later one, so a version chain is one query.
 *
 * @property int $id
 * @property int $course_id
 * @property int $author_id
 * @property int|null $original_id
 * @property string $name
 * @property string $description
 * @property Difficulty $difficulty
 * @property int $time_limit
 * @property int $memory_limit
 * @property int $version
 * @property BankProblemStatus $status
 * @property Carbon|null $deleted_at
 */
class BankProblem extends Model
{
    /** @use HasFactory<BankProblemFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id', 'author_id', 'original_id', 'name', 'description',
        'difficulty', 'time_limit', 'memory_limit', 'version', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['difficulty' => Difficulty::class, 'status' => BankProblemStatus::class];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<self, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_id');
    }

    /** @return HasMany<self, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'original_id');
    }

    /** @return HasMany<BankProblemTestCase, $this> */
    public function testCases(): HasMany
    {
        return $this->hasMany(BankProblemTestCase::class);
    }

    /** @return HasMany<Problem, $this> */
    public function clonedProblems(): HasMany
    {
        return $this->hasMany(Problem::class);
    }

    /** @return BelongsToMany<ProgrammingLanguage, $this> */
    public function programmingLanguages(): BelongsToMany
    {
        return $this->belongsToMany(ProgrammingLanguage::class, 'bank_problem_programming_languages');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'bank_problem_tags');
    }
}
