<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExecutionStatus;
use Database\Factories\SectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The isolation boundary (D-04): scoped queries resolve through membership.
 *
 * @property int $id
 * @property int $semester_id
 * @property string $name
 * @property string|null $description
 * @property int $creator_id
 * @property-read string|null $my_role  Only present after scopeWithMyRole()
 * @property-read int|null $problems_count  Only present after withCount('problems')
 * @property-read int|null $my_solved_count  Only present after scopeWithMyProgress()
 * @property-read int|null $pending_submissions  Only present after scopeWithMyProgress()
 */
class Section extends Model
{
    /** @use HasFactory<SectionFactory> */
    use HasFactory;

    protected $fillable = ['semester_id', 'name', 'description', 'creator_id'];

    /** @return BelongsTo<Semester, $this> */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** @return HasMany<Problem, $this> */
    public function problems(): HasMany
    {
        return $this->hasMany(Problem::class);
    }

    /** @return HasMany<SectionMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(SectionMember::class);
    }

    /**
     * Selects the caller's own role alongside each row, so a listing costs one
     * query instead of one per section.
     *
     * @param  Builder<Section>  $query
     */
    public function scopeWithMyRole(Builder $query, User $user): void
    {
        $query->addSelect(['my_role' => SectionMember::query()
            ->select('role')
            ->whereColumn('section_id', 'sections.id')
            ->where('user_id', $user->id)
            ->limit(1),
        ]);
    }

    /**
     * The two counters openapi's `MySection` describes as a student's, as
     * subqueries so a dashboard listing stays one round trip however many
     * sections the caller belongs to.
     *
     * `my_solved_count` counts *problems* rather than submissions -- a student
     * who solved one problem on the fourth try has solved one -- and
     * `pending_submissions` counts submissions, because each one is a strip
     * still waiting for a verdict.
     *
     * @param  Builder<Section>  $query
     */
    public function scopeWithMyProgress(Builder $query, User $user): void
    {
        $inThisSection = fn (Builder $submissions): Builder => $submissions
            ->join('problems', 'problems.id', '=', 'submissions.problem_id')
            ->whereColumn('problems.section_id', 'sections.id')
            ->where('submissions.creator_id', $user->id);

        $query->addSelect([
            'my_solved_count' => $inThisSection(Submission::query())
                ->selectRaw('count(distinct submissions.problem_id)')
                ->where('submissions.execution_status', ExecutionStatus::Accepted->value),
            'pending_submissions' => $inThisSection(Submission::query())
                ->selectRaw('count(*)')
                ->whereIn('submissions.execution_status', ExecutionStatus::unfinishedValues()),
        ]);
    }
}
