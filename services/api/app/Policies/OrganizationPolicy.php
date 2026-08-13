<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Services\MembershipService;

/**
 * System Admin's power is administrative, never observational: it creates,
 * lists and manages organizations — including their member roster, since
 * assigning the first Org Admin is its job (decisions-v1 §4.1) — but the flag
 * grants no *view* of an organization it does not belong to, and therefore no
 * sight of student work anywhere. Platform scope sits beside organization
 * scope, not above it.
 */
class OrganizationPolicy
{
    public function __construct(private readonly MembershipService $memberships) {}

    /** Open by design: the listing query decides which rows a caller sees. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** The unscoped `GET /admin/organizations` listing. */
    public function viewAll(User $user): bool
    {
        return $user->is_system_admin;
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
        return $user->is_system_admin
            || $this->memberships->isOrganizationAdmin($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->is_system_admin;
    }

    /** Adding or removing Org Admins and instructors. */
    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->is_system_admin
            || $this->memberships->isOrganizationAdmin($user, $organization);
    }
}
