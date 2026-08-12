<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Services\MembershipService;

/**
 * System Admin's power is deliberately narrow: it creates and lists
 * organizations and nothing else. Platform scope sits *beside* organization
 * scope, not above it, so the flag grants no view of student work anywhere.
 */
class OrganizationPolicy
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->memberships->isOrganizationMember($user, $organization);
    }

    public function create(User $user): bool
    {
        return $user->is_system_admin;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->memberships->isOrganizationAdmin($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->is_system_admin;
    }

    /** Adding or removing Org Admins and instructors. */
    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->memberships->isOrganizationAdmin($user, $organization);
    }
}
