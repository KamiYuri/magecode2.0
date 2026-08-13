<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Problem;
use App\Models\ProblemEditLog;
use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * D-40: one append-only row per changed field, so "who moved the deadline"
 * has an answer. Bookkeeping the system writes for itself is skipped —
 * `testcases_updated_at` is a consequence of an edit, not an edit.
 *
 * Rows created outside a request (seeders, factories, artisan) have no editor
 * to attribute the change to and `edited_by` is NOT NULL, so those changes go
 * unlogged rather than borrowing someone's name.
 */
class ProblemObserver
{
    private const IGNORED = ['updated_at', 'created_at', 'deleted_at', 'testcases_updated_at'];

    public function updated(Problem $problem): void
    {
        $editorId = Auth::id();

        if ($editorId === null) {
            return;
        }

        $editedAt = now();

        foreach ($problem->getChanges() as $field => $newValue) {
            if (in_array($field, self::IGNORED, true)) {
                continue;
            }

            ProblemEditLog::create([
                'problem_id' => $problem->id,
                'edited_by' => $editorId,
                'field_changed' => $field,
                'old_value' => $this->stringify($problem->getOriginal($field)),
                'new_value' => $this->stringify($newValue),
                'edited_at' => $editedAt,
            ]);
        }
    }

    private function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof Carbon => $value->toIso8601String(),
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
