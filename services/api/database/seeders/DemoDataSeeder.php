<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Enums\SectionRole;
use App\Models\Course;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Section;
use App\Models\SectionMember;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A minimal but complete hierarchy for local development: one organization
 * down to one section, with an admin, an instructor and three students.
 *
 * Every write is keyed on a natural unique column so re-running the seeder
 * updates instead of duplicating — `make seed` is safe to repeat.
 */
class DemoDataSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        $admin = $this->user('admin', 'admin@magecode.test', 'Quản', 'Trị');
        $instructor = $this->user('instructor', 'instructor@magecode.test', 'Giảng', 'Viên');

        $organization = Organization::firstOrCreate(
            ['name' => 'BKCS'],
            [
                'description' => 'Bach Khoa Computer Science',
                'email' => 'bkcs@magecode.test',
                'creator_id' => $admin->id,
            ]
        );

        OrganizationMember::firstOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $admin->id],
            ['role' => OrganizationRole::Admin, 'created_at' => now()]
        );
        OrganizationMember::firstOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $instructor->id],
            ['role' => OrganizationRole::Instructor, 'added_by' => $admin->id, 'created_at' => now()]
        );

        $course = Course::firstOrCreate(
            ['organization_id' => $organization->id, 'code' => 'IT3080'],
            [
                'name' => 'Mạng máy tính',
                'description' => 'Computer Networking',
                'creator_id' => $instructor->id,
            ]
        );

        $semester = Semester::firstOrCreate(
            ['course_id' => $course->id, 'name' => '20252'],
            [
                'description' => 'Học kỳ 2 năm học 2025-2026',
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->addMonths(4)->toDateString(),
                'creator_id' => $instructor->id,
            ]
        );

        $section = Section::firstOrCreate(
            ['semester_id' => $semester->id, 'name' => 'L01'],
            ['description' => 'Lớp thực hành 01', 'creator_id' => $instructor->id]
        );

        SectionMember::firstOrCreate(
            ['section_id' => $section->id, 'user_id' => $instructor->id],
            ['role' => SectionRole::Instructor, 'added_by' => $admin->id, 'created_at' => now()]
        );

        foreach (range(1, 3) as $index) {
            $student = $this->user(
                username: "student{$index}",
                email: "student{$index}@magecode.test",
                firstName: 'Sinh',
                lastName: "Viên {$index}",
                studentId: '2021000'.$index,
            );

            SectionMember::firstOrCreate(
                ['section_id' => $section->id, 'user_id' => $student->id],
                ['role' => SectionRole::Student, 'added_by' => $instructor->id, 'created_at' => now()]
            );
        }
    }

    private function user(
        string $username,
        string $email,
        string $firstName,
        string $lastName,
        ?string $studentId = null,
    ): User {
        return User::firstOrCreate(
            ['username' => $username],
            [
                'email' => $email,
                'password' => Hash::make(self::DEMO_PASSWORD),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'student_id' => $studentId,
                'email_verified_at' => now(),
            ]
        );
    }
}
