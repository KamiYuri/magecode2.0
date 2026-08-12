<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationMember> */
class OrganizationMemberFactory extends Factory
{
    protected $model = OrganizationMember::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => OrganizationRole::Instructor,
            'added_by' => null,
            'created_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => OrganizationRole::Admin]);
    }
}
