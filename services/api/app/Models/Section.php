<?php

declare(strict_types=1);

namespace App\Models;

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
}
