<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SectionRole;
use App\Models\Section;
use App\Models\User;
use App\Notifications\SectionInvitation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Enrolls a section from an uploaded roster, row by row.
 *
 * Partial success by design: a file of two hundred names with three typos
 * enrolls the other one hundred and ninety-seven and hands back the three line
 * numbers. Refusing the whole file would make the instructor hunt for the typo
 * with no help from us.
 *
 * Each row is its own transaction for the same reason — one bad row must not
 * roll back the rows before it.
 */
class RosterImportService
{
    public function __construct(
        private readonly RosterFileReader $reader,
        private readonly UserProvisioningService $provisioning,
        private readonly EnrollmentGuard $enrollment,
    ) {}

    /**
     * @return array{
     *     imported: int, skipped: int, created_accounts: int,
     *     errors: array<int, array{row: int, error: string}>
     * }
     */
    public function import(Section $section, UploadedFile $file, User $actor): array
    {
        $imported = 0;
        $skipped = 0;
        $created = 0;
        $errors = [];

        /** @var array<int, array{user: User, password: string}> $invitations */
        $invitations = [];
        $seen = [];

        foreach ($this->reader->rows($file) as $line => $row) {
            $email = mb_strtolower($row['email'] ?? '');

            if (isset($seen[$email]) && $email !== '') {
                $skipped++;

                continue;
            }

            try {
                $outcome = $this->importRow($section, $row, $actor);
            } catch (Throwable $failure) {
                $errors[] = ['row' => $line, 'error' => $failure->getMessage()];

                continue;
            }

            $seen[$email] = true;

            if ($outcome === null) {
                $skipped++;

                continue;
            }

            $imported++;

            if ($outcome['invited']) {
                $created++;
                $invitations[] = ['user' => $outcome['user'], 'password' => $outcome['password']];
            }
        }

        foreach ($invitations as $invitation) {
            $invitation['user']->notify(new SectionInvitation($section, $invitation['password']));
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'created_accounts' => $created, 'errors' => $errors];
    }

    /**
     * One row: null when the person is already on the roster.
     *
     * @param  array<string, string>  $row
     * @return array{user: User, invited: bool, password: string}|null
     */
    private function importRow(Section $section, array $row, User $actor): ?array
    {
        $data = $this->validateRow($row);
        $role = SectionRole::from($data['role']);

        return DB::transaction(function () use ($section, $data, $role, $actor): ?array {
            $result = $this->provisioning->findOrInvite($data['email'], [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'student_id' => $data['student_id'],
            ]);
            $user = $result['user'];

            if ($section->members()->where('user_id', $user->id)->exists()) {
                return null;
            }

            $conflict = $this->enrollment->conflictFor($user, $section, $role);

            if ($conflict !== null) {
                throw new RosterRowException(__('Already enrolled in section :section this semester (D-51).', [
                    'section' => $conflict->name,
                ]));
            }

            $section->members()->create([
                'user_id' => $user->id,
                'role' => $role,
                'added_by' => $actor->id,
                'created_at' => now(),
            ]);

            return ['user' => $user, 'invited' => $result['invited'], 'password' => (string) $result['password']];
        });
    }

    /**
     * @param  array<string, string>  $row
     * @return array{email: string, role: string, first_name: string|null, last_name: string|null, student_id: string|null}
     */
    private function validateRow(array $row): array
    {
        $validator = Validator::make([
            'email' => mb_strtolower(trim($row['email'] ?? '')),
            // A roster without a role column is a list of students.
            'role' => ($row['role'] ?? '') !== '' ? mb_strtolower($row['role']) : SectionRole::Student->value,
            'first_name' => $row['first_name'] ?? null,
            'last_name' => $row['last_name'] ?? null,
            'student_id' => $row['student_id'] ?? null,
        ], [
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:'.implode(',', array_column(SectionRole::cases(), 'value'))],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'student_id' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            throw new RosterRowException((string) $validator->errors()->first());
        }

        /** @var array{email: string, role: string, first_name: string|null, last_name: string|null, student_id: string|null} $validated */
        $validated = $validator->validated();

        return $validated;
    }
}
