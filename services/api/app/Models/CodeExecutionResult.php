<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TestCaseStatus;
use Database\Factories\CodeExecutionResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Append-only, written directly by CES via upsert on
 *  (submission_id, test_case_id) so a restart updates rather than duplicates.
 *
 * @property int $id
 * @property int $submission_id
 * @property int $test_case_id
 * @property TestCaseStatus $status
 * @property string|null $actual_output
 * @property string|null $consumed_time_ms
 * @property int|null $consumed_memory_kb
 * @property string|null $error_content
 * @property Carbon $created_at
 */
class CodeExecutionResult extends Model
{
    /** @use HasFactory<CodeExecutionResultFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'submission_id', 'test_case_id', 'status', 'actual_output',
        'consumed_time_ms', 'consumed_memory_kb', 'error_content', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TestCaseStatus::class,
            'consumed_time_ms' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<TestCase, $this> */
    public function testCase(): BelongsTo
    {
        return $this->belongsTo(TestCase::class);
    }
}
