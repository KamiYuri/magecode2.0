<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BankProblemTestCaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bank_problem_id
 * @property string $input
 * @property string $expected_output
 * @property bool $is_active
 * @property bool $is_visible
 * @property int $order
 */
class BankProblemTestCase extends Model
{
    /** @use HasFactory<BankProblemTestCaseFactory> */
    use HasFactory;

    protected $fillable = ['bank_problem_id', 'input', 'expected_output', 'is_active', 'is_visible', 'order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_visible' => 'boolean'];
    }

    /** @return BelongsTo<BankProblem, $this> */
    public function bankProblem(): BelongsTo
    {
        return $this->belongsTo(BankProblem::class);
    }
}
