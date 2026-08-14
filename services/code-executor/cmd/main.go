// Code executor (CES): consumes the code-executor queue, runs each submission
// against Judge0 and writes per-test-case results straight to Postgres (D-81).
//
// This file owns the loop and its failure semantics; running the code against
// Judge0 lands in C6.
package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	cesconfig "github.com/magecode/code-executor/internal/config"
	"github.com/magecode/code-executor/internal/job"
	"github.com/magecode/code-executor/internal/repository"
	"github.com/magecode/shared/go/config"
	"github.com/magecode/shared/go/db"
	"github.com/magecode/shared/go/logger"
	"github.com/magecode/shared/go/rmq"
)

// serviceName is both the D-26 service identifier and the job queue name.
const serviceName = "code-executor"

const (
	// defaultPrefetch follows D-76 (CES: 5, SIM: 1, AID/VUL: 3).
	defaultPrefetch = "5"
	// defaultWorkers follows D-75: 5 goroutines, which is also the share of
	// the PgBouncer pool this service is budgeted (D-89).
	defaultWorkers = "5"
	// defaultDBPort is PgBouncer's, not Postgres's — CES never dials the
	// database directly (D-89).
	defaultDBPort = "6432"
)

func main() {
	log := logger.New(serviceName)
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	cfg, err := config.Load(config.Spec{
		"RABBITMQ_URL": {Required: true},
		"RMQ_PREFETCH": {Type: config.Int, Default: defaultPrefetch},
		"WORKER_COUNT": {Type: config.Int, Default: defaultWorkers},
		"DB_HOST":      {Required: true},
		"DB_PORT":      {Type: config.Int, Default: defaultDBPort},
		"DB_NAME":      {Required: true},
		"DB_USER":      {Required: true},
		"DB_PASSWORD":  {Required: true},
	})
	if err != nil {
		log.Error("loading config", logger.Err(err))
		os.Exit(1)
	}

	// Pool sized to this service's share of the PgBouncer budget: one
	// connection per worker (D-75/D-89).
	pool, err := db.Connect(ctx, db.Config{
		DSN: cesconfig.Postgres{
			Host:     cfg.String("DB_HOST"),
			Port:     cfg.Int("DB_PORT"),
			Database: cfg.String("DB_NAME"),
			User:     cfg.String("DB_USER"),
			Password: cfg.String("DB_PASSWORD"),
		}.DSN(),
		MaxOpenConns: cfg.Int("WORKER_COUNT"),
		MaxIdleConns: cfg.Int("WORKER_COUNT"),
	})
	if err != nil {
		log.Error("connecting to postgres", logger.Err(err))
		os.Exit(1)
	}
	defer pool.Close()

	consumer, err := rmq.NewConsumer(ctx, rmq.Config{
		URL:           cfg.String("RABBITMQ_URL"),
		PrefetchCount: cfg.Int("RMQ_PREFETCH"),
		Concurrency:   cfg.Int("WORKER_COUNT"),
		Logger:        log,
	})
	if err != nil {
		log.Error("connecting to rabbitmq", logger.Err(err))
		os.Exit(1)
	}
	defer consumer.Close()

	log.Info("worker started", "data", map[string]any{
		"queue":    serviceName,
		"prefetch": cfg.Int("RMQ_PREFETCH"),
		"workers":  cfg.Int("WORKER_COUNT"),
	})

	err = consumer.Consume(ctx, serviceName, execute(log, repository.New(pool)))
	if err != nil {
		log.Error("consumer stopped", logger.Err(err))
		os.Exit(1)
	}
	log.Info("shutdown complete")
}

// execute returns the delivery handler.
//
// What it returns decides the message's fate: nil acks, a Permanent error
// dead-letters immediately, and anything else is retried up to the budget
// (D-79e). That is why decoding failures are Permanent and why the work to
// come — database reads and Judge0 calls — must classify its own errors
// rather than returning bare ones.
func execute(log *slog.Logger, repo *repository.Repository) rmq.Handler {
	return func(ctx context.Context, d rmq.Delivery) error {
		decoded, err := job.Decode(d.Body)
		if err != nil {
			// d.TraceID rather than the body's: an undecodable body may not
			// have one, and the header is what the publisher set (D-88).
			log.Error("rejecting unreadable job", logger.Err(err), "trace_id", d.TraceID,
				"data", map[string]any{"queue": d.Queue, "bytes": len(d.Body)})
			return err
		}

		graded, err := repo.Load(ctx, decoded.SubmissionID)
		if err != nil {
			log.Error("loading submission", logger.Err(err), "trace_id", decoded.TraceID,
				"data", map[string]any{"submission_id": decoded.SubmissionID})
			return err
		}

		log.Info("grading submission", "trace_id", decoded.TraceID, "data", map[string]any{
			"submission_id": graded.SubmissionID,
			"test_cases":    len(graded.TestCases),
			"retry_count":   d.RetryCount,
		})

		if err := repo.MarkProcessing(ctx, graded.SubmissionID); err != nil {
			return err
		}

		// C6 runs graded.TestCases through Judge0 and calls repo.SaveResult
		// for each verdict. Until then the recount below sees no results,
		// which is the honest answer for a submission nothing has graded.

		summary, err := repo.Finalise(ctx, graded.SubmissionID)
		if err != nil {
			return err
		}

		log.Info("submission graded", "trace_id", decoded.TraceID, "data", map[string]any{
			"submission_id":    graded.SubmissionID,
			"execution_status": string(summary.Status),
			"testcases_passed": summary.Passed,
			"testcases_total":  summary.Total,
		})

		// C7 publishes result-execution here so api can push the verdict to
		// the student over WebSocket (D-83).
		return nil
	}
}
