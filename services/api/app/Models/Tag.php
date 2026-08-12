<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Course-scoped (D-15).
 *
 * @property int $id
 * @property int $course_id
 * @property string $name
 * @property string|null $color
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    protected $fillable = ['course_id', 'name', 'color'];

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsToMany<Problem, $this> */
    public function problems(): BelongsToMany
    {
        return $this->belongsToMany(Problem::class, 'problem_tags');
    }

    /** @return BelongsToMany<BankProblem, $this> */
    public function bankProblems(): BelongsToMany
    {
        return $this->belongsToMany(BankProblem::class, 'bank_problem_tags');
    }
}
