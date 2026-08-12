<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProblemEditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** One row per changed field (D-40). Append-only.
 *
 * @property int $id
 * @property int $problem_id
 * @property int $edited_by
 * @property string $field_changed
 * @property string|null $old_value
 * @property string|null $new_value
 * @property Carbon $edited_at
 */
class ProblemEditLog extends Model
{
    /** @use HasFactory<ProblemEditLogFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['problem_id', 'edited_by', 'field_changed', 'old_value', 'new_value', 'edited_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    /** @return BelongsTo<Problem, $this> */
    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
