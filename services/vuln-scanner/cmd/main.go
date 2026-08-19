// Vulnerability scanner (VUL): consumes the vuln-scanner queue, runs CodeQL
// over one submission and publishes the findings to api (D-80 — VUL holds no
// database).
//
// This file owns the wiring; `internal/handler` owns what one job does and
// what its failures mean.
package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"

	"github.com/magecode/shared/go/config"
	"github.com/magecode/shared/go/httpsource"
	"github.com/magecode/shared/go/logger"
	"github.com/magecode/shared/go/rmq"
	"github.com/magecode/vuln-scanner/internal/codeql"
	"github.com/magecode/vuln-scanner/internal/handler"
	"github.com/magecode/vuln-scanner/internal/job"
)

// serviceName is both the D-26 service identifier and the job queue name.
const serviceName = job.QueueName

const (
	// defaultPrefetch follows D-76 (CES: 5, SIM: 1, AID/VUL: 3).
	defaultPrefetch = "3"
	// defaultCodeqlTimeout matches compose's VUL_CODEQL_TIMEOUT default.
	// A database build plus a full suite is minutes, not seconds.
	defaultCodeqlTimeout = "600s"
	// defaultThreads leaves the box responsive: VUL shares it with SIM, AID
	// and the database.
	defaultThreads = "2"
	// defaultMaxSourceBytes leaves headroom over D-34's 50KB cap; the point
	// is to refuse something that is not a submission at all.
	defaultMaxSourceBytes = "1048576"
	// defaultWorkspaceRoot is tmpfs in compose: a CodeQL database is the
	// larger half of what a scan writes, and none of it should reach a disk.
	defaultWorkspaceRoot = "/tmp"
)

func main() {
	log := logger.New(serviceName)
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	cfg, err := config.Load(config.Spec{
		"RABBITMQ_URL":     {Required: true},
		"RMQ_PREFETCH":     {Type: config.Int, Default: defaultPrefetch},
		"CODEQL_TIMEOUT":   {Type: config.Duration, Default: defaultCodeqlTimeout},
		"CODEQL_BIN":       {Default: "codeql"},
		"CODEQL_THREADS":   {Default: defaultThreads},
		"MAX_SOURCE_BYTES": {Type: config.Int, Default: defaultMaxSourceBytes},
		"WORKSPACE_ROOT":   {Default: defaultWorkspaceRoot},
	})
	if err != nil {
		log.Error("loading config", logger.Err(err))
		os.Exit(1)
	}

	consumer, err := rmq.NewConsumer(ctx, rmq.Config{
		URL:           cfg.String("RABBITMQ_URL"),
		PrefetchCount: cfg.Int("RMQ_PREFETCH"),
		Logger:        log,
	})
	if err != nil {
		log.Error("connecting to rabbitmq", logger.Err(err))
		os.Exit(1)
	}
	defer func() { _ = consumer.Close() }()

	// A separate connection from the consumer's: a publisher sharing the
	// delivery channel would serialise results behind scans.
	publisher, err := rmq.NewPublisher(ctx, rmq.Config{URL: cfg.String("RABBITMQ_URL"), Logger: log})
	if err != nil {
		log.Error("opening result publisher", logger.Err(err))
		os.Exit(1)
	}
	defer func() { _ = publisher.Close() }()

	jobs := handler.New(handler.Config{
		Sources: httpsource.New(httpsource.Config{
			MaxBytes: int64(cfg.Int("MAX_SOURCE_BYTES")),
			Attempts: 3,
		}),
		Scanner: codeql.New(codeql.Config{
			Binary:        cfg.String("CODEQL_BIN"),
			Timeout:       cfg.Duration("CODEQL_TIMEOUT"),
			Threads:       cfg.String("CODEQL_THREADS"),
			WorkspaceRoot: cfg.String("WORKSPACE_ROOT"),
		}),
		Publisher: publisher,
		Log:       log,
	})

	log.Info("worker started", "data", map[string]any{
		"queue":    serviceName,
		"prefetch": cfg.Int("RMQ_PREFETCH"),
	})

	if err := consumer.Consume(ctx, serviceName, jobs.Handle); err != nil {
		log.Error("consumer stopped", logger.Err(err))
		os.Exit(1)
	}
	log.Info("shutdown complete")
}
