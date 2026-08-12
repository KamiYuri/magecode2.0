<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SectionRole;
use App\Models\Section;
use App\Models\SectionMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SectionMember> */
class SectionMemberFactory extends Factory
{
    protected $model = SectionMember::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
            'user_id' => User::factory(),
            'role' => SectionRole::Student,
            'added_by' => null,
            'created_at' => now(),
        ];
    }

    public function instructor(): static
    {
        return $this->state(fn (): array => [
            'role' => SectionRole::Instructor,
            'user_id' => User::factory(),
        ]);
    }

    public function ta(): static
    {
        return $this->state(fn (): array => ['role' => SectionRole::Ta]);
    }

    public function student(): static
    {
        return $this->state(fn (): array => [
            'role' => SectionRole::Student,
            'user_id' => User::factory()->student(),
        ]);
    }
}
