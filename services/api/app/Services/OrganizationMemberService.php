<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrganizationRole;
use App\Exceptions\ConflictException;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use Illuminate\Support\Facades\DB;

class OrganizationMemberService
{
    public function __construct(private readonly UserProvisioningService $provisioning) {}

    /**
     * Adds a batch of addresses. An address already on the roster is skipped
     * rather than re-roled — changing a role is what PUT is for.
     *
     * @param  array<int, array{email: string, role: string}>  $entries
     * @return array{added: int, skipped: int, invited: int}
     */
    public function addMany(Organization $organization, array $entries, User $actor): array
    {
        $counts = ['added' => 0, 'skipped' => 0, 'invited' => 0];

        /** @var array<int, array{user: User, password: string}> $invitations */
        $invitations = [];
        $seen = [];

        DB::transaction(function () use ($organization, $entries, $actor, &$counts, &$invitations, &$seen): void {
            foreach ($entries as $entry) {
                $email = mb_strtolower(trim($entry['email']));

                if (isset($seen[$email])) {
                    $counts['skipped']++;

                    continue;
                }
                $seen[$email] = true;

                $result = $this->provisioning->findOrInvite($email);
                $user = $result['user'];

                if ($organization->members()->where('user_id', $user->id)->exists()) {
                    $counts['skipped']++;

                    continue;
                }

                $organization->members()->create([
                    'user_id' => $user->id,
                    'role' => OrganizationRole::from($entry['role']),
                    'added_by' => $actor->id,
                    'created_at' => now(),
                ]);

                if ($result['invited']) {
                    $counts['invited']++;
                    $invitations[] = ['user' => $user, 'password' => (string) $result['password']];
                } else {
                    $counts['added']++;
                }
            }
        });

        // Only after the batch commits: a rolled-back transaction must never
        // leave a password in someone's inbox for an account that vanished.
        foreach ($invitations as $invitation) {
            $invitation['user']->notify(new OrganizationInvitation($organization, $invitation['password']));
        }

        return $counts;
    }

    public function changeRole(OrganizationMember $member, OrganizationRole $role): OrganizationMember
    {
        return DB::transaction(function () use ($member, $role): OrganizationMember {
            if ($role !== OrganizationRole::Admin) {
                $this->assertNotLastAdmin($member);
            }

            $member->update(['role' => $role]);

            return $member;
        });
    }

    public function remove(OrganizationMember $member): void
    {
        DB::transaction(function () use ($member): void {
            $this->assertNotLastAdmin($member);

            $member->delete();
        });
    }

    /**
     * An organization with no admin cannot be managed by anyone below the
     * System Admin, so the last one is pinned. The count is taken under a row
     * lock: two concurrent demotions would otherwise both read two admins and
     * both succeed.
     */
    private function assertNotLastAdmin(OrganizationMember $member): void
    {
        if ($member->role !== OrganizationRole::Admin) {
            return;
        }

        // The ids are selected rather than counted because Postgres rejects
        // FOR UPDATE alongside an aggregate.
        $admins = OrganizationMember::query()
            ->where('organization_id', $member->organization_id)
            ->where('role', OrganizationRole::Admin)
            ->lockForUpdate()
            ->pluck('id');

        if ($admins->count() <= 1) {
            throw ConflictException::lastAdmin();
        }
    }
}
