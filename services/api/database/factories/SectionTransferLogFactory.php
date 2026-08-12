<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Section;
use App\Models\SectionTransferLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SectionTransferLog> */
class SectionTransferLogFactory extends Factory
{
    protected $model = SectionTransferLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // A transfer between two sections of the same semester is the realistic
        // case (D-50), so both ends share one semester.
        $from = Section::factory()->create();

        return [
            'user_id' => User::factory()->student(),
            'from_section_id' => $from->id,
            'to_section_id' => Section::factory()->state(['semester_id' => $from->semester_id]),
            'transferred_by' => User::factory(),
            'transferred_at' => now(),
        ];
    }
}
