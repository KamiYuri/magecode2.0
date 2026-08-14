// Package grader runs one submission end to end: fetch the source, run every
// active test case through Judge0, store each verdict, then recount.
//
// It exists so cmd/main.go stays a wiring file and this sequence — the part
// with the interesting failure modes — is testable without a broker.
package grader

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"

	"github.com/magecode/code-executor/internal/judge0"
	"github.com/magecode/code-executor/internal/repository"
	"github.com/magecode/shared/go/apperror"
)

// Store is the slice of the repository this package uses.
type Store interface {
	Load(ctx context.Context, submissionID int64) (repository.Job, error)
	MarkProcessing(ctx context.Context, submissionID int64) error
	MarkFailed(ctx context.Context, submissionID int64, status repository.ExecutionStatus) error
	SaveResult(ctx context.Context, submissionID int64, result repository.TestCaseResult) error
	Finalise(ctx context.Context, submissionID int64) (repository.Summary, error)
}

// Source reads a submission's program out of object storage.
type Source interface {
	Get(ctx context.Context, path string) (io.ReadCloser, error)
}

// Runner executes one test case.
type Runner interface {
	Run(ctx context.Context, job repository.Job, testCase repository.TestCase, source string) (repository.TestCaseResult, error)
}

type Grader struct {
	store  Store
	source Source
	runner Runner
	log    *slog.Logger
}

func New(store Store, source Source, runner Runner, log *slog.Logger) *Grader {
	return &Grader{store: store, source: source, runner: runner, log: log}
}

// Grade processes one submission and returns the summary CES signals with.
//
// Test cases run in order and each verdict is stored as it arrives rather than
// batched at the end: D-81 gives CES direct database access precisely so a
// student watches the verdict strip fill in, and a batch write would hold
// every cell back until the last one finished.
func (g *Grader) Grade(ctx context.Context, submissionID int64, traceID string) (repository.Summary, error) {
	job, err := g.store.Load(ctx, submissionID)
	if err != nil {
		return repository.Summary{}, err
	}

	if err := g.store.MarkProcessing(ctx, submissionID); err != nil {
		return repository.Summary{}, err
	}

	source, err := g.readSource(ctx, job)
	if err != nil {
		return repository.Summary{}, err
	}

	for _, testCase := range job.TestCases {
		result, err := g.runner.Run(ctx, job, testCase, source)

		if errors.Is(err, judge0.ErrLanguageNotSupported) {
			// Judge0 will refuse every remaining test case for the same
			// reason, so the run stops here and the submission carries the
			// verdict the enum has for it (schema §10).
			g.log.Warn("language rejected by judge0", "trace_id", traceID,
				"data", map[string]any{"submission_id": submissionID, "judge0_language_id": job.Judge0LanguageID})

			if markErr := g.store.MarkFailed(ctx, submissionID, repository.ExecutionLanguageNotSupported); markErr != nil {
				return repository.Summary{}, markErr
			}
			return repository.Summary{Status: repository.ExecutionLanguageNotSupported}, nil
		}

		if err != nil {
			// Everything else keeps its classification: a Judge0 outage is
			// transient and the whole submission is graded again from the top,
			// which the upsert makes safe (C5).
			return repository.Summary{}, err
		}

		if err := g.store.SaveResult(ctx, submissionID, result); err != nil {
			return repository.Summary{}, err
		}
	}

	return g.store.Finalise(ctx, submissionID)
}

func (g *Grader) readSource(ctx context.Context, job repository.Job) (string, error) {
	reader, err := g.source.Get(ctx, job.FilePath)
	if err != nil {
		// The object is written before the submission row commits (C2), so a
		// missing one means storage lost it rather than a race — but a bucket
		// that is merely unreachable is the far likelier cause, and that is
		// worth retrying.
		return "", apperror.Wrap(apperror.Transient,
			fmt.Sprintf("reading source %s", job.FilePath), err)
	}
	defer func() { _ = reader.Close() }()

	source, err := io.ReadAll(reader)
	if err != nil {
		return "", apperror.Wrap(apperror.Transient, "reading source body", err)
	}

	return string(source), nil
}
