<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\SectionRole;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `MySection` schema in openapi.yml: a section plus the context F4's
 * sidebar needs to group it.
 *
 * The course, semester and organization names are flattened onto the row on
 * purpose -- the alternative is the frontend walking four endpoints per
 * section to render a nav tree.
 *
 * `my_solved_count` and `pending_submissions` are described as a student's,
 * and are null for anyone else: an instructor has no "solved" of their own,
 * and a zero would read as one.
 *
 * @mixin Section
 */
class MySectionResource extends JsonResource
{
    private function studentOnly(?int $count): ?int
    {
        return $this->my_role === SectionRole::Student->value ? $count : null;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $semester = $this->semester;
        $course = $semester?->course;

        return [
            'section' => new SectionResource($this->resource),
            'course_code' => $course?->code,
            'course_name' => $course?->name,
            'semester_name' => $semester?->name,
            'organization_name' => $course?->organization?->name,
            'problems_count' => $this->problems_count ?? 0,
            'my_solved_count' => $this->studentOnly($this->my_solved_count),
            'pending_submissions' => $this->studentOnly($this->pending_submissions),
        ];
    }
}
