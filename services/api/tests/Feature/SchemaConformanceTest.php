<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Asserts the migrated schema matches docs/database-schema.md, which is the
 * source of truth. Every expectation below is transcribed from that document;
 * when the two disagree, the document wins and the migration is the bug.
 */
class SchemaConformanceTest extends TestCase
{
    use RefreshDatabase;

    /** Section 1.3: 35 tables. */
    private const DOCUMENTED_TABLES = [
        'users', 'organizations', 'courses', 'semesters', 'sections', 'problems', 'submissions',
        'test_cases', 'programming_languages',
        'bank_problems', 'bank_problem_test_cases',
        'analysis_problems', 'analysis_submissions', 'code_execution_results',
        'similarity_results', 'ai_detection_results', 'vulnerability_results',
        'organization_members', 'section_members',
        'tags', 'problem_programming_languages', 'bank_problem_programming_languages',
        'bank_problem_tags', 'problem_tags',
        'problem_edit_logs', 'section_transfer_logs',
        'notifications',
        'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens',
    ];

    /**
     * Section 1.4 FK cascade policy, expanded per column.
     *
     * @var array<string, string> "table.column" => "referenced_table:DELETE RULE"
     */
    private const EXPECTED_FOREIGN_KEYS = [
        'organizations.creator_id' => 'users:RESTRICT',
        'courses.organization_id' => 'organizations:RESTRICT',
        'courses.creator_id' => 'users:RESTRICT',
        'semesters.course_id' => 'courses:RESTRICT',
        'semesters.creator_id' => 'users:RESTRICT',
        'sections.semester_id' => 'semesters:RESTRICT',
        'sections.creator_id' => 'users:RESTRICT',
        'bank_problems.course_id' => 'courses:RESTRICT',
        'bank_problems.author_id' => 'users:RESTRICT',
        'bank_problems.original_id' => 'bank_problems:RESTRICT',
        'bank_problem_test_cases.bank_problem_id' => 'bank_problems:CASCADE',
        'problems.section_id' => 'sections:RESTRICT',
        'problems.bank_problem_id' => 'bank_problems:SET NULL',
        'problems.creator_id' => 'users:RESTRICT',
        'test_cases.problem_id' => 'problems:CASCADE',
        'submissions.problem_id' => 'problems:RESTRICT',
        'submissions.creator_id' => 'users:RESTRICT',
        'submissions.programming_language_id' => 'programming_languages:RESTRICT',
        'analysis_problems.semester_id' => 'semesters:RESTRICT',
        'analysis_problems.bank_problem_id' => 'bank_problems:RESTRICT',
        'analysis_problems.triggered_by_problem_id' => 'problems:RESTRICT',
        'analysis_problems.analyst_id' => 'users:RESTRICT',
        'analysis_submissions.submission_id' => 'submissions:RESTRICT',
        'analysis_submissions.analysis_problem_id' => 'analysis_problems:CASCADE',
        'code_execution_results.submission_id' => 'submissions:RESTRICT',
        'code_execution_results.test_case_id' => 'test_cases:RESTRICT',
        'similarity_results.analysis_problem_id' => 'analysis_problems:CASCADE',
        'similarity_results.submission_a_id' => 'submissions:RESTRICT',
        'similarity_results.submission_b_id' => 'submissions:RESTRICT',
        'ai_detection_results.analysis_submission_id' => 'analysis_submissions:CASCADE',
        'vulnerability_results.analysis_submission_id' => 'analysis_submissions:CASCADE',
        'organization_members.organization_id' => 'organizations:RESTRICT',
        'organization_members.user_id' => 'users:RESTRICT',
        'organization_members.added_by' => 'users:RESTRICT',
        'section_members.section_id' => 'sections:RESTRICT',
        'section_members.user_id' => 'users:RESTRICT',
        'section_members.added_by' => 'users:RESTRICT',
        'tags.course_id' => 'courses:RESTRICT',
        'problem_programming_languages.problem_id' => 'problems:CASCADE',
        'problem_programming_languages.programming_language_id' => 'programming_languages:CASCADE',
        'bank_problem_programming_languages.bank_problem_id' => 'bank_problems:CASCADE',
        'bank_problem_programming_languages.programming_language_id' => 'programming_languages:CASCADE',
        'bank_problem_tags.bank_problem_id' => 'bank_problems:CASCADE',
        'bank_problem_tags.tag_id' => 'tags:CASCADE',
        'problem_tags.problem_id' => 'problems:CASCADE',
        'problem_tags.tag_id' => 'tags:CASCADE',
        'problem_edit_logs.problem_id' => 'problems:RESTRICT',
        'problem_edit_logs.edited_by' => 'users:RESTRICT',
        'section_transfer_logs.user_id' => 'users:RESTRICT',
        'section_transfer_logs.from_section_id' => 'sections:RESTRICT',
        'section_transfer_logs.to_section_id' => 'sections:RESTRICT',
        'section_transfer_logs.transferred_by' => 'users:RESTRICT',
    ];

    public function test_every_documented_table_exists(): void
    {
        $existing = collect(DB::select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
        ))->pluck('tablename')->all();

        foreach (self::DOCUMENTED_TABLES as $table) {
            $this->assertContains($table, $existing, "Missing table: {$table}");
        }
    }

    public function test_spatie_permission_tables_are_absent(): void
    {
        // U-2: authorization uses Laravel Policies + membership roles, so the
        // prototype's Spatie tables are deliberately not ported.
        $existing = collect(DB::select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
        ))->pluck('tablename')->all();

        foreach (['permissions', 'roles', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $table) {
            $this->assertNotContains($table, $existing, "Unexpected Spatie table: {$table}");
        }
    }

    public function test_foreign_key_delete_rules_match_the_cascade_policy(): void
    {
        $actual = [];
        foreach ($this->foreignKeyRows() as $row) {
            $actual["{$row->table_name}.{$row->column_name}"] = "{$row->foreign_table}:{$row->delete_rule}";
        }

        foreach (self::EXPECTED_FOREIGN_KEYS as $column => $expected) {
            $this->assertArrayHasKey($column, $actual, "Missing foreign key on {$column}");
            $this->assertSame($expected, $actual[$column], "Wrong FK target/delete rule on {$column}");
        }
    }

    public function test_no_undocumented_foreign_keys_exist(): void
    {
        $documented = array_keys(self::EXPECTED_FOREIGN_KEYS);
        $frameworkOwned = ['personal_access_tokens', 'sessions'];

        foreach ($this->foreignKeyRows() as $row) {
            if (in_array($row->table_name, $frameworkOwned, true)) {
                continue;
            }
            $this->assertContains(
                "{$row->table_name}.{$row->column_name}",
                $documented,
                "Undocumented foreign key: {$row->table_name}.{$row->column_name}"
            );
        }
    }

    public function test_analysis_problems_partial_unique_indexes_exist(): void
    {
        $indexes = collect(DB::select(
            "SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'analysis_problems'"
        ))->keyBy('indexname');

        $this->assertArrayHasKey('idx_analysis_problems_latest_bank', $indexes->all());
        $bank = $indexes['idx_analysis_problems_latest_bank']->indexdef;
        $this->assertStringContainsString('UNIQUE', $bank);
        $this->assertStringContainsString('semester_id', $bank);
        $this->assertStringContainsString('bank_problem_id', $bank);
        $this->assertStringContainsString('WHERE', $bank, 'Index must be partial (is_latest predicate)');

        $this->assertArrayHasKey('idx_analysis_problems_latest_manual', $indexes->all());
        $manual = $indexes['idx_analysis_problems_latest_manual']->indexdef;
        $this->assertStringContainsString('UNIQUE', $manual);
        $this->assertStringContainsString('manual_match_group_id', $manual);
        $this->assertStringContainsString('WHERE', $manual);
    }

    public function test_partial_unique_index_allows_only_one_latest_per_scope(): void
    {
        $scope = $this->seedAnalysisScope();

        $this->insertAnalysisProblem($scope, isLatest: true);

        $this->expectException(QueryException::class);
        $this->insertAnalysisProblem($scope, isLatest: true);
    }

    public function test_superseded_analysis_rows_may_coexist(): void
    {
        $scope = $this->seedAnalysisScope();

        $this->insertAnalysisProblem($scope, isLatest: false);
        $this->insertAnalysisProblem($scope, isLatest: false);
        $this->insertAnalysisProblem($scope, isLatest: true);

        $this->assertSame(3, DB::table('analysis_problems')->count());
    }

    public function test_check_constraints_exist(): void
    {
        $constraints = collect(DB::select(
            "SELECT conname FROM pg_constraint WHERE contype = 'c'"
        ))->pluck('conname')->all();

        $this->assertContains('chk_analysis_scope', $constraints);
        $this->assertContains('chk_similarity_pair_order', $constraints);
    }

    public function test_analysis_scope_check_rejects_a_scopeless_row(): void
    {
        $scope = $this->seedAnalysisScope();

        $this->expectException(QueryException::class);
        $this->insertScopedAnalysisProblem($scope, bankProblemId: null, matchGroupId: null);
    }

    /**
     * Section 5.2: exactly one scope identifier. The scope logic resolves
     * bank_problem_id and manual_match_group_id in separate branches with no
     * precedence rule between them, so a row carrying both would describe two
     * different sets of problems.
     */
    public function test_analysis_scope_check_rejects_a_row_with_both_identifiers(): void
    {
        $scope = $this->seedAnalysisScope();

        $this->expectException(QueryException::class);
        $this->insertScopedAnalysisProblem(
            $scope,
            bankProblemId: $scope['bank_problem_id'],
            matchGroupId: '3f8c1d2e-7b4a-4c6d-9e1f-2a5b8c0d3e6f',
        );
    }

    public function test_analysis_scope_check_accepts_either_identifier_alone(): void
    {
        $scope = $this->seedAnalysisScope();

        $this->insertScopedAnalysisProblem($scope, bankProblemId: $scope['bank_problem_id'], matchGroupId: null);
        $this->insertScopedAnalysisProblem(
            $scope,
            bankProblemId: null,
            matchGroupId: '3f8c1d2e-7b4a-4c6d-9e1f-2a5b8c0d3e6f',
        );

        $this->assertSame(2, DB::table('analysis_problems')->count());
    }

    public function test_soft_deletes_exist_only_on_problems_and_bank_problems(): void
    {
        $withDeletedAt = collect(DB::select(
            "SELECT table_name FROM information_schema.columns
             WHERE table_schema = 'public' AND column_name = 'deleted_at'"
        ))->pluck('table_name')->sort()->values()->all();

        $this->assertSame(['bank_problems', 'problems'], $withDeletedAt);
    }

    public function test_append_only_tables_have_no_updated_at(): void
    {
        // Section 5.4/5.5/5.6/5.7 and 6.x: these tables carry created_at only.
        $appendOnly = [
            'code_execution_results', 'similarity_results', 'ai_detection_results',
            'vulnerability_results', 'organization_members', 'section_members',
        ];

        foreach ($appendOnly as $table) {
            $columns = $this->columnsOf($table);
            $this->assertContains('created_at', $columns, "{$table} must keep created_at");
            $this->assertNotContains('updated_at', $columns, "{$table} must not have updated_at");
        }
    }

    public function test_analysis_problems_services_column_is_jsonb(): void
    {
        $type = DB::selectOne(
            "SELECT data_type FROM information_schema.columns
             WHERE table_name = 'analysis_problems' AND column_name = 'services'"
        );

        $this->assertSame('jsonb', $type->data_type);
    }

    public function test_programming_languages_file_extensions_column_is_jsonb(): void
    {
        $type = DB::selectOne(
            "SELECT data_type FROM information_schema.columns
             WHERE table_name = 'programming_languages' AND column_name = 'file_extensions'"
        );

        $this->assertSame('jsonb', $type->data_type);
    }

    public function test_users_table_matches_the_documented_columns(): void
    {
        $expected = [
            'avatar_path', 'created_at', 'email', 'email_verified_at', 'first_name', 'id',
            'is_first_time_register', 'is_system_admin', 'last_name', 'password', 'student_id',
            'updated_at', 'username',
        ];

        $this->assertSame($expected, $this->columnsOf('users'));
    }

    public function test_documented_unique_constraints_exist(): void
    {
        $expected = [
            'courses' => ['organization_id', 'code'],
            'semesters' => ['course_id', 'name'],
            'sections' => ['semester_id', 'name'],
            'code_execution_results' => ['submission_id', 'test_case_id'],
            'similarity_results' => ['analysis_problem_id', 'submission_a_id', 'submission_b_id'],
            'ai_detection_results' => ['analysis_submission_id'],
            'organization_members' => ['organization_id', 'user_id'],
            'section_members' => ['section_id', 'user_id'],
            'tags' => ['course_id', 'name'],
        ];

        foreach ($expected as $table => $columns) {
            $indexes = collect(DB::select(
                'SELECT indexdef FROM pg_indexes WHERE tablename = ?',
                [$table]
            ))->pluck('indexdef');

            $match = $indexes->first(fn (string $def): bool => str_contains($def, 'UNIQUE')
                && ! str_contains($def, 'WHERE')
                && collect($columns)->every(fn (string $column): bool => str_contains($def, $column)));

            $this->assertNotNull($match, 'Missing UNIQUE('.implode(', ', $columns).") on {$table}");
        }
    }

    public function test_pivot_tables_use_composite_primary_keys(): void
    {
        $pivots = [
            'problem_programming_languages' => ['problem_id', 'programming_language_id'],
            'bank_problem_programming_languages' => ['bank_problem_id', 'programming_language_id'],
            'bank_problem_tags' => ['bank_problem_id', 'tag_id'],
            'problem_tags' => ['problem_id', 'tag_id'],
        ];

        foreach ($pivots as $table => $columns) {
            $this->assertNotContains('id', $this->columnsOf($table), "{$table} must not have an id column");

            $primary = DB::selectOne(
                "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint
                 WHERE contype = 'p' AND conrelid = ?::regclass",
                [$table]
            );
            $this->assertNotNull($primary, "{$table} has no primary key");
            foreach ($columns as $column) {
                $this->assertStringContainsString($column, $primary->def);
            }
        }
    }

    /**
     * @return list<object{table_name: string, column_name: string, foreign_table: string, delete_rule: string}>
     */
    private function foreignKeyRows(): array
    {
        /** @var list<object{table_name: string, column_name: string, foreign_table: string, delete_rule: string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT
                con.conrelid::regclass::text  AS table_name,
                att.attname                   AS column_name,
                con.confrelid::regclass::text AS foreign_table,
                CASE con.confdeltype
                    WHEN 'a' THEN 'NO ACTION'
                    WHEN 'r' THEN 'RESTRICT'
                    WHEN 'c' THEN 'CASCADE'
                    WHEN 'n' THEN 'SET NULL'
                    WHEN 'd' THEN 'SET DEFAULT'
                END AS delete_rule
            FROM pg_constraint con
            JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY (con.conkey)
            WHERE con.contype = 'f'
              AND con.connamespace = 'public'::regnamespace
        SQL);

        return $rows;
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        return collect(DB::select(
            'SELECT column_name FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? ORDER BY column_name',
            ['public', $table]
        ))->pluck('column_name')->all();
    }

    /**
     * Inserts the minimum parent rows an analysis_problems row needs.
     *
     * @return array{semester_id: int, bank_problem_id: int, problem_id: int, user_id: int}
     */
    private function seedAnalysisScope(): array
    {
        $userId = DB::table('users')->insertGetId([
            'username' => 'analyst', 'email' => 'analyst@example.test',
            'password' => 'secret', 'first_name' => 'An', 'last_name' => 'Alyst',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $organizationId = DB::table('organizations')->insertGetId([
            'name' => 'BKCS', 'creator_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $courseId = DB::table('courses')->insertGetId([
            'organization_id' => $organizationId, 'code' => 'IT3080', 'name' => 'Networking',
            'creator_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $semesterId = DB::table('semesters')->insertGetId([
            'course_id' => $courseId, 'name' => '20252', 'creator_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sectionId = DB::table('sections')->insertGetId([
            'semester_id' => $semesterId, 'name' => 'L01', 'creator_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bankProblemId = DB::table('bank_problems')->insertGetId([
            'course_id' => $courseId, 'author_id' => $userId, 'name' => 'Two Sum',
            'description' => 'desc', 'time_limit' => 1000, 'memory_limit' => 65536,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $problemId = DB::table('problems')->insertGetId([
            'section_id' => $sectionId, 'bank_problem_id' => $bankProblemId, 'creator_id' => $userId,
            'name' => 'Two Sum', 'description' => 'desc', 'time_limit' => 1000, 'memory_limit' => 65536,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'semester_id' => $semesterId,
            'bank_problem_id' => $bankProblemId,
            'problem_id' => $problemId,
            'user_id' => $userId,
        ];
    }

    /** @param array{semester_id: int, bank_problem_id: int, problem_id: int, user_id: int} $scope */
    private function insertAnalysisProblem(array $scope, bool $isLatest): void
    {
        DB::table('analysis_problems')->insert([
            'semester_id' => $scope['semester_id'],
            'bank_problem_id' => $scope['bank_problem_id'],
            'triggered_by_problem_id' => $scope['problem_id'],
            'analyst_id' => $scope['user_id'],
            'services' => json_encode(['plagiarism-checker']),
            'is_latest' => $isLatest,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert with the two scope identifiers spelled out, so a case can set
     * neither, one or both without the partial unique indexes interfering.
     *
     * @param  array<string, int>  $scope
     */
    private function insertScopedAnalysisProblem(array $scope, ?int $bankProblemId, ?string $matchGroupId): void
    {
        DB::table('analysis_problems')->insert([
            'semester_id' => $scope['semester_id'],
            'bank_problem_id' => $bankProblemId,
            'manual_match_group_id' => $matchGroupId,
            'triggered_by_problem_id' => $scope['problem_id'],
            'analyst_id' => $scope['user_id'],
            'services' => json_encode(['plagiarism-checker']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
