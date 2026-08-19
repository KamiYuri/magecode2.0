// Plagiarism checker (SIM): consumes the plagiarism-checker queue, downloads
// one language group's sources into a temporary workspace and compares them
// with Dolos, then publishes the whole result to api (D-80 — SIM holds no
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

	"github.com/magecode/plagiarism-checker/internal/dolos"
	"github.com/magecode/plagiarism-checker/internal/handler"
	"github.com/magecode/plagiarism-checker/internal/job"
	"github.com/magecode/shared/go/config"
	"github.com/magecode/shared/go/health"
	"github.com/magecode/shared/go/httpsource"
	"github.com/magecode/shared/go/logger"
	"github.com/magecode/shared/go/rmq"
)

// serviceName is both the D-26 service identifier and the job queue name.
const serviceName = job.QueueName

const (
	// defaultPrefetch follows D-76 (CES: 5, SIM: 1, AID/VUL: 3). One job is
	// a whole language group, so a second in flight buys nothing and doubles
	// the workspace on tmpfs.
	defaultPrefetch = "1"
	// defaultDolosTimeout matches compose's SIM_DOLOS_TIMEOUT default. It is
	// read here because E2's wrapper needs it and startup is where a bad
	// value should fail.
	defaultDolosTimeout = "300s"
	// defaultMaxSourceBytes leaves generous headroom over D-34's 50KB cap:
	// the point is to refuse something that is not a submission at all, not
	// to re-enforce the API's limit.
	defaultMaxSourceBytes = "1048576"
	// defaultHealthAddr is where compose's healthcheck asks (D-72) and where
	// G1 will hang /metrics.
	defaultHealthAddr = ":9090"
	// defaultWorkspaceRoot is tmpfs in compose, so the sources of a batch
	// never touch a disk.
	defaultWorkspaceRoot = "/tmp"
	// defaultReporterScript is where the image puts the Node reporter that
	// drives the Dolos library (reporter/report.mjs).
	defaultReporterScript = "/app/reporter/report.mjs"
	// defaultMaxRegions bounds one pair's highlight list. Regions land in a
	// TEXT column and a long pair of files can produce hundreds.
	defaultMaxRegions = "100"
)

func main() {
	log := logger.New(serviceName)
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	cfg, err := config.Load(config.Spec{
		"RABBITMQ_URL":         {Required: true},
		"RMQ_PREFETCH":         {Type: config.Int, Default: defaultPrefetch},
		"DOLOS_TIMEOUT":        {Type: config.Duration, Default: defaultDolosTimeout},
		"MAX_SOURCE_BYTES":     {Type: config.Int, Default: defaultMaxSourceBytes},
		"WORKSPACE_ROOT":       {Default: defaultWorkspaceRoot},
		"REPORTER_SCRIPT":      {Default: defaultReporterScript},
		"NODE_BIN":             {Default: "node"},
		"MAX_REGIONS_PER_PAIR": {Type: config.Int, Default: defaultMaxRegions},
		"HEALTH_ADDR":          {Default: defaultHealthAddr},
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

	sources := httpsource.New(httpsource.Config{
		MaxBytes: int64(cfg.Int("MAX_SOURCE_BYTES")),
		Attempts: 3,
	})

	comparer := dolos.New(dolos.Config{
		Node:    cfg.String("NODE_BIN"),
		Script:  cfg.String("REPORTER_SCRIPT"),
		Timeout: cfg.Duration("DOLOS_TIMEOUT"),
		Limits:  dolos.Limits{MaxRegionsPerPair: cfg.Int("MAX_REGIONS_PER_PAIR")},
	})

	// A separate connection from the consumer's: a publisher sharing the
	// delivery channel would serialise results behind comparisons.
	publisher, err := rmq.NewPublisher(ctx, rmq.Config{URL: cfg.String("RABBITMQ_URL"), Logger: log})
	if err != nil {
		log.Error("opening result publisher", logger.Err(err))
		os.Exit(1)
	}
	defer func() { _ = publisher.Close() }()

	jobs := handler.New(handler.Config{
		Sources:       sources,
		Comparer:      comparer,
		Publisher:     publisher,
		WorkspaceRoot: cfg.String("WORKSPACE_ROOT"),
		Log:           log,
	})

	// Readiness is the broker: a worker whose connection died still has a
	// process, and without this the container looks fine while consuming
	// nothing.
	liveness := health.New(cfg.String("HEALTH_ADDR"), consumer.Healthy, log)
	if err := liveness.Start(ctx); err != nil {
		log.Error("starting health server", logger.Err(err))
		os.Exit(1)
	}
	defer liveness.Stop()

	log.Info("worker started", "data", map[string]any{
		"queue":    serviceName,
		"prefetch": cfg.Int("RMQ_PREFETCH"),
	})

	err = consumer.Consume(ctx, serviceName, jobs.Handle)
	if err != nil {
		log.Error("consumer stopped", logger.Err(err))
		os.Exit(1)
	}
	log.Info("shutdown complete")
}
