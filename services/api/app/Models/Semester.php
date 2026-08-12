<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublishMode;
use Database\Factories\SemesterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $course_id
 * @property string $name
 * @property string|null $description
 * @property PublishMode $publish_mode
 * @property PublishMode $lock_mode
 * @property bool $allow_publish_override
 * @property bool $allow_lock_override
 * @property string $similarity_threshold
 * @property string $ai_detection_threshold
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property int $creator_id
 */
class Semester extends Model
{
    /** @use HasFactory<SemesterFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id', 'name', 'description', 'publish_mode', 'lock_mode',
        'allow_publish_override', 'allow_lock_override',
        'similarity_threshold', 'ai_detection_threshold',
        'start_date', 'end_date', 'creator_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'publish_mode' => PublishMode::class,
            'lock_mode' => PublishMode::class,
            'allow_publish_override' => 'boolean',
            'allow_lock_override' => 'boolean',
            'similarity_threshold' => 'decimal:2',
            'ai_detection_threshold' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** @return HasMany<Section, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /** @return HasMany<AnalysisProblem, $this> */
    public function analysisProblems(): HasMany
    {
        return $this->hasMany(AnalysisProblem::class);
    }
}
