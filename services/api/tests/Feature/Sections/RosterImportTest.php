<?php

declare(strict_types=1);

namespace Tests\Feature\Sections;

use App\Enums\SectionRole;
use App\Models\Section;
use App\Models\Semester;
use App\Models\User;
use App\Notifications\SectionInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * Roster import. Partial success is the whole point: a file of 200 students
 * with three bad rows enrolls 197 and reports the three, rather than refusing
 * the lot and leaving the instructor to find the typo.
 */
class RosterImportTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private Semester $semester;

    private Section $section;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()));
        $this->section = $this->sectionIn($this->semester, ['name' => 'L01']);
        $this->instructor = $this->sectionMember($this->section, SectionRole::Instructor);
    }

    public function test_a_clean_file_enrolls_every_row(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members/import", [
            'file' => $this->fixture('valid.csv'),
        ])
            ->assertOk()
            ->assertJsonPath('data.imported', 3)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.created_accounts', 3)
            ->assertJsonPath('data.errors', []);

        $this->assertSame(4, $this->section->members()->count());
        $this->assertDatabaseHas('users', ['email' => 'an.nguyen@sis.hust.edu.vn', 'student_id' => '20210001']);
        Notification::assertSentTimes(SectionInvitation::class, 3);
    }

    public function test_the_file_supplies_names_and_student_ids(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members/import", [
            'file' => $this->fixture('valid.csv'),
        ])->assertOk();

        $student = User::where('email', 'an.nguyen@sis.hust.edu.vn')->firstOrFail();
        $this->assertSame('An', $student->first_name);
        $this->assertSame('Nguyễn', $student->last_name);
        $this->assertTrue($student->is_first_time_register);
    }

    public function test_bad_rows_are_reported_with_their_line_number(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->instructor);

        $response = $this->postJson("/api/v1/sections/{$this->section->id}/members/import", [
            'file' => $this->fixture('messy.csv'),
        ])
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.skipped', 1);

        /** @var array<int, array{row: int, error: string}> $errors */
        $errors = $response->json('data.errors');
        $this->assertCount(3, $errors);
        // Rows are numbered as the spreadsheet shows them, header included,
        // so the instructor can find the line they need to fix.
        $this->assertSame([3, 4, 5], array_column($errors, 'row'));

        $this->assertDatabaseMissing('users', ['email' => 'duong.pham@sis.hust.edu.vn']);
    }

    public function test_a_row_already_on_the_roster_is_skipped_not_failed(): void
    {
        Notification::fake();
        $existing = User::factory()->create(['email' => 'an.nguyen@sis.hust.edu.vn']);
        $this->sectionMember($this->section, SectionRole::Student, $existing);
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members/import", [
            'file' => $this->fixture('valid.csv'),
        ])
            ->assertOk()
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.errors', []);
    }

    public function test_a_student_enrolled_in_a_sibling_section_is_reported_as_a_row_error(): void
    {
        // D-51 inside an import is a row-level problem, not a reason to refuse
        // the file.
        Notification::fake();
        $sibling = $this->sectionIn($this->semester, ['name' => 'L02']);
        $clash = User::factory()->create(['email' => 'an.nguyen@sis.hust.edu.vn']);
        $this->sectionMember($sibling, SectionRole::Student, $clash);
        Sanctum::actingAs($this->instructor);

        $response = $this->postJson("/api/v1/sections/{$this->section->id}/members/import", [
            'file' => $this->fixture('valid.csv'),
        ])
            ->assertOk()
            ->assertJsonPath('data.imported', 2);

        /** @var array<int, array{row: int, error: string}> $errors */
        $errors = $response->json('data.errors');
        $this->assertCount(1, $errors);
        $this->assertSame(2, $errors[0]['row']);
        $this->assertStringContainsString('L02', $errors[0]['error']);
    }

    public function test_a_file_with_only_an_email_column_still_imports(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members/import", [
            'file' => $this->fixture('minimal.csv'),
        ])->assertOk()->assertJsonPath('data.imported', 1);

        $this->assertDatabaseHas('section_members', [
            'section_id' => $this->section->id,
            'role' => SectionRole::Student->value,
        ]);
    }

    public function test_a_spreadsheet_imports_exactly_like_a_csv(): void
    {
        // The registrar exports whichever format their system produces, so
        // both have to behave the same.
        Notification::fake();
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members/import", [
            'file' => $this->spreadsheet([
                ['email', 'student_id', 'first_name', 'last_name'],
                ['an.nguyen@sis.hust.edu.vn', '20210001', 'An', 'Nguyễn'],
                ['not-an-email', '20210004', 'Bad', 'Row'],
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.errors.0.row', 3);

        $this->assertDatabaseHas('users', ['email' => 'an.nguyen@sis.hust.edu.vn', 'student_id' => '20210001']);
    }

    public function test_a_file_without_an_email_column_is_rejected_outright(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members/import", [
            'file' => UploadedFile::fake()->createWithContent('roster.csv', "name,student_id\nAn,20210001\n"),
        ])->assertUnprocessable()->assertJsonValidationErrors(['file']);
    }

    public function test_an_unsupported_file_type_is_rejected(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members/import", [
            'file' => UploadedFile::fake()->create('roster.pdf', 10, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['file']);
    }

    public function test_the_import_requires_a_file(): void
    {
        Sanctum::actingAs($this->instructor);

        $this->postJson("/api/v1/sections/{$this->section->id}/members/import", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_a_ta_may_not_import_a_roster(): void
    {
        Sanctum::actingAs($this->sectionMember($this->section, SectionRole::Ta));

        $this->postJson("/api/v1/sections/{$this->section->id}/members/import", [
            'file' => $this->fixture('valid.csv'),
        ])->assertForbidden();
    }

    private function fixture(string $name): UploadedFile
    {
        $path = __DIR__.'/../../Fixtures/roster/'.$name;

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /**
     * Written rather than committed as a binary fixture: a generated .xlsx
     * stays readable in the diff and cannot drift from what the test claims.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function spreadsheet(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'roster').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($rows as $cells) {
            $writer->addRow(Row::fromValues($cells));
        }

        $writer->close();

        return new UploadedFile($path, 'roster.xlsx', null, null, true);
    }
}
