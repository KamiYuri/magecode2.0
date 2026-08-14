//go:build integration

// Integration tests for the CES repository against the compose Postgres. Run:
//
//	DB_TEST_DSN="postgres://user:pass@localhost:6432/magecode" \
//	  go test -tags integration -race ./internal/repository/ -v
//
// The suite skips when DB_TEST_DSN is unset.
//
// Postgres only, like the api suite (U-6): the upsert leans on the unique
// index over (submission_id, test_case_id) and ON CONFLICT, and the recount on
// a LEFT JOIN with a FILTER — none of which another engine exercises the same
// way.
package repository_test

import (
	"context"
	"fmt"
	"os"
	"sync/atomic"
	"testing"

	"github.com/jmoiron/sqlx"

	"github.com/magecode/code-executor/internal/repository"
	"github.com/magecode/shared/go/apperror"
	"github.com/magecode/shared/go/db"
)

// Distinguishes rows between tests in one run; the process id alone repeats
// across the subtests of a table-driven case.
var fixtureCounter atomic.Int64

func connect(t *testing.T) *sqlx.DB {
	t.Helper()
	dsn := os.Getenv("DB_TEST_DSN")
	if dsn == "" {
		t.Skip("DB_TEST_DSN not set; start compose pgbouncer and export it")
	}
	pool, err := db.Connect(context.Background(), db.Config{DSN: dsn})
	if err != nil {
		t.Fatalf("Connect: %v", err)
	}
	t.Cleanup(func() { _ = pool.Close() })
	return pool
}

func mustGet(t *testing.T, pool *sqlx.DB, dest any, query string, args ...any) {
	t.Helper()
	if err := pool.Get(dest, query, args...); err != nil {
		t.Fatalf("query %q: %v", query, err)
	}
}

func exec(t *testing.T, pool *sqlx.DB, query string, args ...any) {
	t.Helper()
	if _, err := pool.Exec(query, args...); err != nil {
		t.Fatalf("exec %q: %v", query, err)
	}
}

// fixture is one submission with the org → section → problem chain it needs,
// since every foreign key on the way is RESTRICT.
type fixture struct {
	submissionID int64
	problemID    int64
	judge0ID     int
	testCaseIDs  []int64
}

func seed(t *testing.T, pool *sqlx.DB, active, inactive int) fixture {
	t.Helper()
	tag := fmt.Sprintf("ces-%d-%d", os.Getpid(), fixtureCounter.Add(1))

	f := fixture{judge0ID: 71}
	var languageID, userID, orgID, courseID, semesterID, sectionID int64

	mustGet(t, pool, &languageID, `
		INSERT INTO programming_languages
			(name, version, judge0_id, monaco_language, file_extensions, created_at, updated_at)
		VALUES ($1, '3.12', $2, 'python', '["py"]'::jsonb, now(), now()) RETURNING id`,
		"Python "+tag, f.judge0ID)
	mustGet(t, pool, &userID, `
		INSERT INTO users (username, email, password, first_name, last_name, created_at, updated_at)
		VALUES ($1, $2, 'x', 'C', 'ES', now(), now()) RETURNING id`, tag, tag+"@example.test")
	mustGet(t, pool, &orgID, `
		INSERT INTO organizations (name, creator_id, created_at, updated_at)
		VALUES ($1, $2, now(), now()) RETURNING id`, "Org "+tag, userID)
	mustGet(t, pool, &courseID, `
		INSERT INTO courses (organization_id, code, name, creator_id, created_at, updated_at)
		VALUES ($1, $2, 'Course', $3, now(), now()) RETURNING id`, orgID, tag, userID)
	mustGet(t, pool, &semesterID, `
		INSERT INTO semesters (course_id, name, creator_id, created_at, updated_at)
		VALUES ($1, $2, $3, now(), now()) RETURNING id`, courseID, tag, userID)
	mustGet(t, pool, &sectionID, `
		INSERT INTO sections (semester_id, name, creator_id, created_at, updated_at)
		VALUES ($1, $2, $3, now(), now()) RETURNING id`, semesterID, tag, userID)
	mustGet(t, pool, &f.problemID, `
		INSERT INTO problems
			(section_id, creator_id, name, description, time_limit, memory_limit, created_at, updated_at)
		VALUES ($1, $2, 'Two Sum', 'desc', 1500, 65536, now(), now()) RETURNING id`, sectionID, userID)

	insertTestCase := func(isActive bool, order int) int64 {
		var id int64
		mustGet(t, pool, &id, `
			INSERT INTO test_cases
				(problem_id, input, expected_output, is_active, is_visible, "order", created_at, updated_at)
			VALUES ($1, $2, $3, $4, false, $5, now(), now()) RETURNING id`,
			f.problemID, fmt.Sprintf("in-%d", order), fmt.Sprintf("out-%d", order), isActive, order)
		return id
	}
	for i := range active {
		f.testCaseIDs = append(f.testCaseIDs, insertTestCase(true, i))
	}
	for i := range inactive {
		f.testCaseIDs = append(f.testCaseIDs, insertTestCase(false, 100+i))
	}

	mustGet(t, pool, &f.submissionID, `
		INSERT INTO submissions
			(problem_id, creator_id, programming_language_id, file_path, file_name, created_at, updated_at)
		VALUES ($1, $2, $3, 'submissions/1/1/main.py', 'main.py', now(), now()) RETURNING id`,
		f.problemID, userID, languageID)

	t.Cleanup(func() {
		exec(t, pool, `DELETE FROM code_execution_results WHERE submission_id = $1`, f.submissionID)
		exec(t, pool, `DELETE FROM submissions WHERE id = $1`, f.submissionID)
		exec(t, pool, `DELETE FROM test_cases WHERE problem_id = $1`, f.problemID)
		exec(t, pool, `DELETE FROM problems WHERE id = $1`, f.problemID)
		exec(t, pool, `DELETE FROM sections WHERE id = $1`, sectionID)
		exec(t, pool, `DELETE FROM semesters WHERE id = $1`, semesterID)
		exec(t, pool, `DELETE FROM courses WHERE id = $1`, courseID)
		exec(t, pool, `DELETE FROM organizations WHERE id = $1`, orgID)
		exec(t, pool, `DELETE FROM users WHERE id = $1`, userID)
		exec(t, pool, `DELETE FROM programming_languages WHERE id = $1`, languageID)
	})

	return f
}

func countResults(t *testing.T, pool *sqlx.DB, submissionID int64) int {
	t.Helper()
	var rows int
	mustGet(t, pool, &rows, `SELECT count(*) FROM code_execution_results WHERE submission_id = $1`, submissionID)
	return rows
}

func TestLoadReturnsWhatIsNeededToGrade(t *testing.T) {
	pool := connect(t)
	f := seed(t, pool, 3, 2)
	repo := repository.New(pool)

	got, err := repo.Load(context.Background(), f.submissionID)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}

	if got.SubmissionID != f.submissionID {
		t.Errorf("SubmissionID = %d, want %d", got.SubmissionID, f.submissionID)
	}
	if got.Judge0LanguageID != f.judge0ID {
		t.Errorf("Judge0LanguageID = %d, want %d", got.Judge0LanguageID, f.judge0ID)
	}
	if got.FilePath != "submissions/1/1/main.py" {
		t.Errorf("FilePath = %q", got.FilePath)
	}
	if got.TimeLimitMs != 1500 || got.MemoryLimitKB != 65536 {
		t.Errorf("limits = %d ms / %d KB, want 1500 / 65536", got.TimeLimitMs, got.MemoryLimitKB)
	}

	// Inactive test cases are not graded, so they must not arrive here at all.
	if len(got.TestCases) != 3 {
		t.Fatalf("len(TestCases) = %d, want 3 active", len(got.TestCases))
	}
	for i, tc := range got.TestCases {
		if tc.Order != i {
			t.Errorf("TestCases[%d].Order = %d, want %d (run order follows `order`)", i, tc.Order, i)
		}
		if tc.Input != fmt.Sprintf("in-%d", i) || tc.ExpectedOutput != fmt.Sprintf("out-%d", i) {
			t.Errorf("TestCases[%d] = (%q, %q)", i, tc.Input, tc.ExpectedOutput)
		}
	}
}

// C4 retries anything that is not Permanent three times. A submission that
// does not exist will not appear on the third attempt.
func TestLoadReportsAMissingSubmissionAsPermanent(t *testing.T) {
	pool := connect(t)
	repo := repository.New(pool)

	_, err := repo.Load(context.Background(), 999_999_999)
	if err == nil {
		t.Fatal("Load accepted a submission that does not exist")
	}
	if !apperror.IsPermanent(err) {
		t.Errorf("error is not Permanent, so the job would be retried three times: %v", err)
	}
}

// The headline requirement of C5 (schema §3.7, Issue #4): a redelivered
// message grades the submission again, and the second run must overwrite.
func TestSaveResultOverwritesInsteadOfDuplicating(t *testing.T) {
	pool := connect(t)
	f := seed(t, pool, 2, 0)
	repo := repository.New(pool)
	ctx := context.Background()

	first := repository.TestCaseResult{
		TestCaseID: f.testCaseIDs[0],
		Status:     repository.StatusWrongAnswer,
		Output:     "4\n",
		TimeMs:     12.5,
		MemoryKB:   3072,
	}
	if err := repo.SaveResult(ctx, f.submissionID, first); err != nil {
		t.Fatalf("SaveResult: %v", err)
	}

	second := first
	second.Status = repository.StatusAccepted
	second.Output = "3\n"
	if err := repo.SaveResult(ctx, f.submissionID, second); err != nil {
		t.Fatalf("SaveResult (rerun): %v", err)
	}

	if rows := countResults(t, pool, f.submissionID); rows != 1 {
		t.Errorf("rows = %d, want 1 (the rerun must overwrite)", rows)
	}

	var status, output string
	mustGet(t, pool, &status, `
		SELECT status FROM code_execution_results WHERE submission_id = $1 AND test_case_id = $2`,
		f.submissionID, f.testCaseIDs[0])
	mustGet(t, pool, &output, `
		SELECT actual_output FROM code_execution_results WHERE submission_id = $1 AND test_case_id = $2`,
		f.submissionID, f.testCaseIDs[0])
	if status != string(repository.StatusAccepted) || output != "3\n" {
		t.Errorf("row = (%s, %q), want the rerun's values", status, output)
	}
}

func TestFinaliseCountsAndWritesTheVerdict(t *testing.T) {
	cases := []struct {
		name     string
		statuses []repository.TestCaseStatus
		passed   int
		want     repository.ExecutionStatus
	}{
		{"all pass", []repository.TestCaseStatus{
			repository.StatusAccepted, repository.StatusAccepted, repository.StatusAccepted,
		}, 3, repository.ExecutionAccepted},
		{"some pass", []repository.TestCaseStatus{
			repository.StatusAccepted, repository.StatusWrongAnswer, repository.StatusAccepted,
		}, 2, repository.ExecutionPartiallyAccepted},
		{"none pass", []repository.TestCaseStatus{
			repository.StatusWrongAnswer, repository.StatusRuntimeError, repository.StatusWrongAnswer,
		}, 0, repository.ExecutionError},
		// v3 §7: submission-level timeout is about the run, not the verdicts.
		{"all time out", []repository.TestCaseStatus{
			repository.StatusTimeLimitExceeded, repository.StatusTimeLimitExceeded, repository.StatusTimeout,
		}, 0, repository.ExecutionError},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			pool := connect(t)
			f := seed(t, pool, len(tc.statuses), 0)
			repo := repository.New(pool)
			ctx := context.Background()

			for i, status := range tc.statuses {
				if err := repo.SaveResult(ctx, f.submissionID, repository.TestCaseResult{
					TestCaseID: f.testCaseIDs[i], Status: status,
				}); err != nil {
					t.Fatalf("SaveResult: %v", err)
				}
			}

			summary, err := repo.Finalise(ctx, f.submissionID)
			if err != nil {
				t.Fatalf("Finalise: %v", err)
			}

			if summary.Passed != tc.passed || summary.Total != len(tc.statuses) {
				t.Errorf("counters = %d/%d, want %d/%d",
					summary.Passed, summary.Total, tc.passed, len(tc.statuses))
			}
			if summary.Status != tc.want {
				t.Errorf("Status = %q, want %q", summary.Status, tc.want)
			}

			var storedPassed, storedTotal int
			var storedStatus string
			mustGet(t, pool, &storedPassed, `SELECT testcases_passed FROM submissions WHERE id = $1`, f.submissionID)
			mustGet(t, pool, &storedTotal, `SELECT testcases_total FROM submissions WHERE id = $1`, f.submissionID)
			mustGet(t, pool, &storedStatus, `SELECT execution_status FROM submissions WHERE id = $1`, f.submissionID)
			if storedPassed != tc.passed || storedTotal != len(tc.statuses) || storedStatus != string(tc.want) {
				t.Errorf("stored = %d/%d %q, want %d/%d %q", storedPassed, storedTotal, storedStatus,
					tc.passed, len(tc.statuses), tc.want)
			}
		})
	}
}

// A test case with no result yet still belongs in the denominator, or a run
// that died halfway would report 2/2 instead of 2/3.
func TestFinaliseCountsUngradedTestCasesInTheTotal(t *testing.T) {
	pool := connect(t)
	f := seed(t, pool, 3, 0)
	repo := repository.New(pool)
	ctx := context.Background()

	for _, id := range f.testCaseIDs[:2] {
		if err := repo.SaveResult(ctx, f.submissionID, repository.TestCaseResult{
			TestCaseID: id, Status: repository.StatusAccepted,
		}); err != nil {
			t.Fatalf("SaveResult: %v", err)
		}
	}

	summary, err := repo.Finalise(ctx, f.submissionID)
	if err != nil {
		t.Fatalf("Finalise: %v", err)
	}

	if summary.Passed != 2 || summary.Total != 3 {
		t.Errorf("counters = %d/%d, want 2/3", summary.Passed, summary.Total)
	}
	if summary.Status != repository.ExecutionPartiallyAccepted {
		t.Errorf("Status = %q, want partially_accepted", summary.Status)
	}
}

// v3 §7: the counters describe the current test set, so a test case switched
// off leaves both the numerator and the denominator.
func TestFinaliseIgnoresDeactivatedTestCasesButKeepsTheirRows(t *testing.T) {
	pool := connect(t)
	f := seed(t, pool, 3, 0)
	repo := repository.New(pool)
	ctx := context.Background()

	for _, id := range f.testCaseIDs {
		if err := repo.SaveResult(ctx, f.submissionID, repository.TestCaseResult{
			TestCaseID: id, Status: repository.StatusAccepted,
		}); err != nil {
			t.Fatalf("SaveResult: %v", err)
		}
	}
	exec(t, pool, `UPDATE test_cases SET is_active = false WHERE id = $1`, f.testCaseIDs[2])

	summary, err := repo.Finalise(ctx, f.submissionID)
	if err != nil {
		t.Fatalf("Finalise: %v", err)
	}

	if summary.Passed != 2 || summary.Total != 2 {
		t.Errorf("counters = %d/%d, want 2/2", summary.Passed, summary.Total)
	}
	if summary.Status != repository.ExecutionAccepted {
		t.Errorf("Status = %q, want accepted", summary.Status)
	}
	// The row records what actually ran, and the test case can come back.
	if rows := countResults(t, pool, f.submissionID); rows != 3 {
		t.Errorf("stored rows = %d, want 3 (results are not deleted)", rows)
	}
}

// The crash simulation C5 asks for: a worker that died after two of three test
// cases, then a redelivery that grades the whole submission again.
func TestFinaliseAfterAPartialRunThenARerun(t *testing.T) {
	pool := connect(t)
	f := seed(t, pool, 3, 0)
	repo := repository.New(pool)
	ctx := context.Background()

	for _, id := range f.testCaseIDs[:2] {
		if err := repo.SaveResult(ctx, f.submissionID, repository.TestCaseResult{
			TestCaseID: id, Status: repository.StatusWrongAnswer,
		}); err != nil {
			t.Fatalf("SaveResult: %v", err)
		}
	}

	for _, id := range f.testCaseIDs {
		if err := repo.SaveResult(ctx, f.submissionID, repository.TestCaseResult{
			TestCaseID: id, Status: repository.StatusAccepted,
		}); err != nil {
			t.Fatalf("SaveResult (rerun): %v", err)
		}
	}

	summary, err := repo.Finalise(ctx, f.submissionID)
	if err != nil {
		t.Fatalf("Finalise: %v", err)
	}

	if rows := countResults(t, pool, f.submissionID); rows != 3 {
		t.Errorf("rows = %d, want 3 (one per test case)", rows)
	}
	if summary.Passed != 3 || summary.Total != 3 || summary.Status != repository.ExecutionAccepted {
		t.Errorf("summary = %d/%d %q, want 3/3 accepted", summary.Passed, summary.Total, summary.Status)
	}
}

// A problem whose test cases were all switched off must not report accepted
// on an empty run — the same hole DeriveStatus closes, checked end to end.
func TestFinaliseWithNoActiveTestCasesIsAnError(t *testing.T) {
	pool := connect(t)
	f := seed(t, pool, 0, 2)
	repo := repository.New(pool)

	summary, err := repo.Finalise(context.Background(), f.submissionID)
	if err != nil {
		t.Fatalf("Finalise: %v", err)
	}

	if summary.Total != 0 || summary.Status != repository.ExecutionError {
		t.Errorf("summary = %d/%d %q, want 0/0 error", summary.Passed, summary.Total, summary.Status)
	}
}

func TestMarkProcessingAndMarkFailed(t *testing.T) {
	pool := connect(t)
	f := seed(t, pool, 1, 0)
	repo := repository.New(pool)
	ctx := context.Background()

	if err := repo.MarkProcessing(ctx, f.submissionID); err != nil {
		t.Fatalf("MarkProcessing: %v", err)
	}
	var status string
	mustGet(t, pool, &status, `SELECT execution_status FROM submissions WHERE id = $1`, f.submissionID)
	if status != string(repository.ExecutionProcessing) {
		t.Errorf("status = %q, want processing", status)
	}

	if err := repo.MarkFailed(ctx, f.submissionID, repository.ExecutionTimeout); err != nil {
		t.Fatalf("MarkFailed: %v", err)
	}
	mustGet(t, pool, &status, `SELECT execution_status FROM submissions WHERE id = $1`, f.submissionID)
	if status != string(repository.ExecutionTimeout) {
		t.Errorf("status = %q, want timeout", status)
	}
}
