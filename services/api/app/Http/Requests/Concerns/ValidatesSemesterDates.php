<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Semester;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * `end_date >= start_date` (decisions-v3 §7, 2026-08-13). Expressed here
 * rather than as `after_or_equal:start_date` because a PUT may carry only one
 * of the two dates, in which case the other one still lives in the row and
 * the rule must reach for it.
 */
trait ValidatesSemesterDates
{
    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                    return;
                }

                $start = $this->effectiveDate('start_date');
                $end = $this->effectiveDate('end_date');

                if ($start !== null && $end !== null && $end->lessThan($start)) {
                    $validator->errors()->add('end_date', __('The end date must be on or after the start date.'));
                }
            },
        ];
    }

    private function effectiveDate(string $key): ?Carbon
    {
        if ($this->has($key)) {
            $value = $this->input($key);

            return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
        }

        $semester = $this->route('semester');

        return $semester instanceof Semester ? $semester->{$key} : null;
    }
}
