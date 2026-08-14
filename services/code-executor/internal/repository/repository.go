// Package repository is CES's data layer.
//
// D-81 gives this service direct database access so per-test-case results can
// reach students without a round trip through api. That privilege comes with
// one rule the schema states outright (§3.7, Issue #4): results are **upserted**
// on (submission_id, test_case_id). A message redelivered after a worker died
// mid-run grades the submission again from the top, and a plain insert would
// leave two rows for the same test case.
//
// Every method classifies its errors. C4 treats an unclassified error as
// transient and retries it three times, so a submission that no longer exists
// has to say Permanent or the queue will keep asking for it.
package repository

import (
	"context"
	"database/sql"
	"errors"
	"fmt"

	"github.com/jmoiron/sqlx"

	"github.com/magecode/shared/go/apperror"
)

// Repository reads and writes everything CES touches. No interface: this is
// the only implementation, and the tests run against a real Postgres (U-6),
// so an interface here would exist only to mock what is not worth mocking.
type Repository struct {
	pool *sqlx.DB
}

func New(pool *sqlx.DB) *Repository {
	return &Repository{pool: pool}
}

// TestCase is one case to run, in the order it should be run.
type TestCase struct {
	ID             int64  `db:"id"`
	Input          string `db:"input"`
	ExpectedOutput string `db:"expected_output"`
	Order          int    `db:"order"`
}

// Job is everything needed to grade one submission and nothing more — the
// message carried only an id (D-84), and this is where the rest comes from.
type Job struct {
	SubmissionID     int64  `db:"submission_id"`
	ProblemID        int64  `db:"problem_id"`
	FilePath         string `db:"file_path"`
	Judge0LanguageID int    `db:"judge0_id"`
	TimeLimitMs      int    `db:"time_limit"`
	MemoryLimitKB    int    `db:"memory_limit"`

	TestCases []TestCase
}

// TestCaseResult is one Judge0 verdict, in the shape the results table stores.
type TestCaseResult struct {
	TestCaseID int64
	Status     TestCaseStatus
	Output     string
	// TimeMs and MemoryKB are what Judge0 reported; zero means it reported
	// nothing, which is normal for a compilation error.
	TimeMs   float64
	MemoryKB int
	// ErrorContent is stored verbatim. D-38's 5000-character cap belongs to
	// whoever produces the text (C6) — truncating here would hide a producer
	// that stopped honouring it.
	ErrorContent string
}

// Summary is the recount plus the verdict derived from it.
type Summary struct {
	Passed int             `db:"passed"`
	Total  int             `db:"total"`
	Status ExecutionStatus `db:"-"`
}

// Load fetches the submission, its problem's limits, the Judge0 language id
// and the active test cases in run order.
//
// Inactive test cases never appear: they are excluded from grading, so
// including them would both waste Judge0 calls and put rows in the results
// table that the recount then has to ignore.
func (r *Repository) Load(ctx context.Context, submissionID int64) (Job, error) {
	var job Job

	err := r.pool.GetContext(ctx, &job, `
		SELECT s.id AS submission_id, s.problem_id, s.file_path,
		       l.judge0_id, p.time_limit, p.memory_limit
		FROM submissions s
		JOIN problems p ON p.id = s.problem_id
		JOIN programming_languages l ON l.id = s.programming_language_id
		WHERE s.id = $1`, submissionID)
	if errors.Is(err, sql.ErrNoRows) {
		// Retrying will not conjure the row, and submissions are never
		// deleted (D-52), so this means the id was wrong to begin with.
		return Job{}, apperror.New(apperror.Permanent,
			fmt.Sprintf("submission %d does not exist", submissionID))
	}
	if err != nil {
		return Job{}, apperror.Wrap(apperror.Transient, "loading submission", err)
	}

	if err := r.pool.SelectContext(ctx, &job.TestCases, `
		SELECT id, input, expected_output, "order"
		FROM test_cases
		WHERE problem_id = $1 AND is_active = true
		ORDER BY "order", id`, job.ProblemID); err != nil {
		return Job{}, apperror.Wrap(apperror.Transient, "loading test cases", err)
	}

	return job, nil
}

// MarkProcessing moves the submission out of the queue so the student sees
// grading has begun. Unconditional: a redelivery grades from the top, and the
// counters that follow are recounted anyway.
func (r *Repository) MarkProcessing(ctx context.Context, submissionID int64) error {
	return r.setStatus(ctx, submissionID, ExecutionProcessing)
}

// MarkFailed records a verdict the counters cannot express — a grading run
// that never finished (ExecutionTimeout) or a language Judge0 refused
// (ExecutionLanguageNotSupported).
func (r *Repository) MarkFailed(ctx context.Context, submissionID int64, status ExecutionStatus) error {
	return r.setStatus(ctx, submissionID, status)
}

func (r *Repository) setStatus(ctx context.Context, submissionID int64, status ExecutionStatus) error {
	if _, err := r.pool.ExecContext(ctx, `
		UPDATE submissions SET execution_status = $1, updated_at = now() WHERE id = $2`,
		string(status), submissionID); err != nil {
		return apperror.Wrap(apperror.Transient, "updating submission status", err)
	}
	return nil
}

// SaveResult writes one test case's verdict, overwriting any previous one for
// the same pair (schema §3.7).
//
// created_at is refreshed on conflict rather than kept: the table has no
// updated_at, so it is the row's only timestamp, and leaving the original
// would date the row to a run whose values have since been replaced.
func (r *Repository) SaveResult(ctx context.Context, submissionID int64, result TestCaseResult) error {
	_, err := r.pool.ExecContext(ctx, `
		INSERT INTO code_execution_results
			(submission_id, test_case_id, status, actual_output,
			 consumed_time_ms, consumed_memory_kb, error_content, created_at)
		VALUES ($1, $2, $3, $4, $5, $6, $7, now())
		ON CONFLICT (submission_id, test_case_id) DO UPDATE SET
			status = EXCLUDED.status,
			actual_output = EXCLUDED.actual_output,
			consumed_time_ms = EXCLUDED.consumed_time_ms,
			consumed_memory_kb = EXCLUDED.consumed_memory_kb,
			error_content = EXCLUDED.error_content,
			created_at = EXCLUDED.created_at`,
		submissionID, result.TestCaseID, string(result.Status), result.Output,
		result.TimeMs, result.MemoryKB, nullable(result.ErrorContent))
	if err != nil {
		return apperror.Wrap(apperror.Transient, "saving test case result", err)
	}
	return nil
}

// Finalise recounts from the results table and writes the counters and the
// verdict (schema §3.7).
//
// The count and the update share a transaction so a reader never sees counters
// that disagree with the status. It stays short — no Judge0 call, no MinIO
// read — because D-89 pools by transaction.
func (r *Repository) Finalise(ctx context.Context, submissionID int64) (Summary, error) {
	tx, err := r.pool.BeginTxx(ctx, nil)
	if err != nil {
		return Summary{}, apperror.Wrap(apperror.Transient, "beginning finalise transaction", err)
	}
	defer func() { _ = tx.Rollback() }()

	var summary Summary
	// LEFT JOIN from test_cases, not from the results: a test case with no
	// result yet still belongs in the total, or a run that died halfway would
	// report 2/2 instead of 2/3. The is_active filter is v3 §7 — a test case
	// switched off leaves both sides of the fraction at once.
	if err := tx.GetContext(ctx, &summary, `
		SELECT
			count(*) FILTER (WHERE r.status = $2) AS passed,
			count(*) AS total
		FROM test_cases t
		LEFT JOIN code_execution_results r
			ON r.test_case_id = t.id AND r.submission_id = $1
		WHERE t.problem_id = (SELECT problem_id FROM submissions WHERE id = $1)
		  AND t.is_active = true`, submissionID, string(StatusAccepted)); err != nil {
		return Summary{}, apperror.Wrap(apperror.Transient, "recounting test case results", err)
	}

	summary.Status = DeriveStatus(summary.Passed, summary.Total)

	if _, err := tx.ExecContext(ctx, `
		UPDATE submissions
		SET testcases_passed = $1, testcases_total = $2, execution_status = $3, updated_at = now()
		WHERE id = $4`,
		summary.Passed, summary.Total, string(summary.Status), submissionID); err != nil {
		return Summary{}, apperror.Wrap(apperror.Transient, "writing submission counters", err)
	}

	if err := tx.Commit(); err != nil {
		return Summary{}, apperror.Wrap(apperror.Transient, "committing finalise", err)
	}

	return summary, nil
}

// nullable keeps an empty error_content out of the column as NULL, matching
// what the api's resource renders as absent rather than as an empty string.
func nullable(value string) any {
	if value == "" {
		return nil
	}
	return value
}
