<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SectionMember;
use App\Models\User;
use App\Services\MembershipService;

/**
 * Membership rows are managed through their section (`SectionPolicy`), with
 * one exception: a transfer moves a student *between* sections, so it belongs
 * to the only role that can see both (D-50).
 */
class SectionMemberPolicy
{
    public function __construct(private readonly MembershipService $memberships) {}

    /**
     * Who may move someone. *Whether this particular row can move* — only a
     * student enrolment can — is a business rule and stays in the service.
     */
    public function transfer(User $user, SectionMember $member): bool
    {
        return $this->memberships->administersSection($user, $member->section);
    }
}
