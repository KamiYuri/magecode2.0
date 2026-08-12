<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $require_bank_approval
 * @property int $creator_id
 */
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'code', 'name', 'description', 'require_bank_approval', 'creator_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['require_bank_approval' => 'boolean'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** @return HasMany<Semester, $this> */
    public function semesters(): HasMany
    {
        return $this->hasMany(Semester::class);
    }

    /** @return HasMany<BankProblem, $this> */
    public function bankProblems(): HasMany
    {
        return $this->hasMany(BankProblem::class);
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }
}
