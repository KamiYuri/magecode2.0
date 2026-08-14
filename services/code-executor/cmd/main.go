// Code executor (CES): consumes the code-executor queue, runs each submission
// against Judge0 and writes per-test-case results straight to Postgres (D-81).
//
// This file owns the loop and its failure semantics; the work itself lands in
// C5 (repository) and C6 (Judge0 client).
package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/magecode/code-executor/internal/job"
	"github.com/magecode/shared/go/config"
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
)

func main() {
	log := logger.New(serviceName)
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	cfg, err := config.Load(config.Spec{
		"RABBITMQ_URL": {Required: true},
		"RMQ_PREFETCH": {Type: config.Int, Default: defaultPrefetch},
		"WORKER_COUNT": {Type: config.Int, Default: defaultWorkers},
	})
	if err != nil {
		log.Error("loading config", logger.Err(err))
		os.Exit(1)
	}

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

	err = consumer.Consume(ctx, serviceName, execute(log))
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
func execute(log *slog.Logger) rmq.Handler {
	return func(_ context.Context, d rmq.Delivery) error {
		decoded, err := job.Decode(d.Body)
		if err != nil {
			// d.TraceID rather than the body's: an undecodable body may not
			// have one, and the header is what the publisher set (D-88).
			log.Error("rejecting unreadable job", logger.Err(err), "trace_id", d.TraceID,
				"data", map[string]any{"queue": d.Queue, "bytes": len(d.Body)})
			return err
		}

		log.Info("job received", "trace_id", decoded.TraceID, "data", map[string]any{
			"submission_id": decoded.SubmissionID,
			"retry_count":   d.RetryCount,
		})

		// C5 loads the submission and its test cases, C6 runs them.
		return nil
	}
}
