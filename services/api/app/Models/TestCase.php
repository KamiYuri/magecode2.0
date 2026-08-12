<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TestCaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Max 50 per problem, 1MB per field (D-45). Cascade-deleted with the problem.
 *
 * @property int $id
 * @property int $problem_id
 * @property string $input
 * @property string $expected_output
 * @property bool $is_active
 * @property bool $is_visible
 * @property int $order
 */
class TestCase extends Model
{
    /** @use HasFactory<TestCaseFactory> */
    use HasFactory;

    protected $fillable = ['problem_id', 'input', 'expected_output', 'is_active', 'is_visible', 'order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_visible' => 'boolean'];
    }

    /** @return BelongsTo<Problem, $this> */
    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }
}
