<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SectionRole;
use App\Exceptions\UnprocessableException;
use App\Models\Section;
use App\Models\SectionMember;
use App\Models\SectionTransferLog;
use App\Models\User;
use App\Notifications\SectionInvitation;
use Illuminate\Support\Facades\DB;

class SectionMemberService
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly EnrollmentGuard $enrollment,
    ) {}

    /**
     * @param  array<int, array{email: string, role: string}>  $entries
     * @return array{added: int, skipped: int, invited: int}
     */
    public function addMany(Section $section, array $entries, User $actor): array
    {
        $counts = ['added' => 0, 'skipped' => 0, 'invited' => 0];

        /** @var array<int, array{user: User, password: string}> $invitations */
        $invitations = [];
        $seen = [];

        DB::transaction(function () use ($section, $entries, $actor, &$counts, &$invitations, &$seen): void {
            foreach ($entries as $entry) {
                $email = mb_strtolower(trim($entry['email']));

                if (isset($seen[$email])) {
                    $counts['skipped']++;

                    continue;
                }
                $seen[$email] = true;

                $role = SectionRole::from($entry['role']);
                $result = $this->provisioning->findOrInvite($email);
                $user = $result['user'];

                if ($section->members()->where('user_id', $user->id)->exists()) {
                    $counts['skipped']++;

                    continue;
                }

                $this->assertEnrollable($user, $section, $role);
                $this->enroll($section, $user, $role, $actor);

                if ($result['invited']) {
                    $counts['invited']++;
                    $invitations[] = ['user' => $user, 'password' => (string) $result['password']];
                } else {
                    $counts['added']++;
                }
            }
        });

        foreach ($invitations as $invitation) {
            $invitation['user']->notify(new SectionInvitation($section, $invitation['password']));
        }

        return $counts;
    }

    public function changeRole(SectionMember $member, SectionRole $role): SectionMember
    {
        // Promoting someone to student can create the very clash D-51 forbids.
        $this->assertEnrollable($member->user, $member->section, $role);

        $member->update(['role' => $role]);

        return $member;
    }

    /**
     * D-50: a transfer is a move plus an audit row, in one transaction — the
     * roster and its history must never disagree about where a student went.
     */
    public function transfer(SectionMember $member, Section $target, User $actor): SectionMember
    {
        if ($member->role !== SectionRole::Student) {
            throw UnprocessableException::transferNotAStudent();
        }

        if ($target->semester_id !== $member->section->semester_id) {
            throw UnprocessableException::transferOutsideSemester();
        }

        return DB::transaction(function () use ($member, $target, $actor): SectionMember {
            $student = $member->user;
            $origin = $member->section;

            $member->delete();
            $moved = $this->enroll($target, $student, SectionRole::Student, $actor);

            SectionTransferLog::create([
                'user_id' => $student->id,
                'from_section_id' => $origin->id,
                'to_section_id' => $target->id,
                'transferred_by' => $actor->id,
                'transferred_at' => now(),
            ]);

            return $moved;
        });
    }

    private function enroll(Section $section, User $user, SectionRole $role, User $actor): SectionMember
    {
        /** @var SectionMember $member */
        $member = $section->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'added_by' => $actor->id,
            'created_at' => now(),
        ]);

        return $member;
    }

    private function assertEnrollable(User $user, Section $section, SectionRole $role): void
    {
        $conflict = $this->enrollment->conflictFor($user, $section, $role);

        if ($conflict !== null) {
            throw UnprocessableException::duplicateEnrollment($conflict->name);
        }
    }
}
