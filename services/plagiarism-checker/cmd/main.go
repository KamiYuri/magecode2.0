// Plagiarism checker (SIM): consumes the plagiarism-checker queue, downloads
// one language group's sources into a temporary workspace and compares them
// with Dolos, then publishes the whole result to api (D-80 — SIM holds no
// database).
//
// This file owns the loop and its failure semantics. The comparison itself
// lands in E2 and the result message in E3.
package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/magecode/plagiarism-checker/internal/dolos"
	"github.com/magecode/plagiarism-checker/internal/downloader"
	"github.com/magecode/plagiarism-checker/internal/handler"
	"github.com/magecode/plagiarism-checker/internal/job"
	"github.com/magecode/plagiarism-checker/internal/workspace"
	"github.com/magecode/shared/go/config"
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

	sources := downloader.New(downloader.Config{
		MaxBytes: int64(cfg.Int("MAX_SOURCE_BYTES")),
		Attempts: 3,
	})

	comparer := dolos.New(dolos.Config{
		Node:    cfg.String("NODE_BIN"),
		Script:  cfg.String("REPORTER_SCRIPT"),
		Timeout: cfg.Duration("DOLOS_TIMEOUT"),
		Limits:  dolos.Limits{MaxRegionsPerPair: cfg.Int("MAX_REGIONS_PER_PAIR")},
	})

	log.Info("worker started", "data", map[string]any{
		"queue":    serviceName,
		"prefetch": cfg.Int("RMQ_PREFETCH"),
	})

	err = consumer.Consume(ctx, serviceName, compare(log, sources, comparer, cfg.String("WORKSPACE_ROOT")))
	if err != nil {
		log.Error("consumer stopped", logger.Err(err))
		os.Exit(1)
	}
	log.Info("shutdown complete")
}

// compare returns the delivery handler.
//
// What it returns decides the message's fate: nil acks, a Permanent error
// dead-letters immediately, and anything else is retried up to the budget
// (D-79e). Decoding failures are Permanent because a body SIM cannot read is
// one it also cannot answer — there is no analysis_submission_id in it to
// report an error against.
func compare(log *slog.Logger, sources *downloader.Client, comparer *dolos.Runner, workspaceRoot string) rmq.Handler {
	return func(ctx context.Context, d rmq.Delivery) error {
		decoded, err := job.Decode(d.Body)
		if err != nil {
			// d.TraceID rather than the body's: an undecodable body may not
			// have one, and the header is what the publisher set (D-88).
			log.Error("rejecting unreadable job", logger.Err(err), "trace_id", d.TraceID,
				"data", map[string]any{"queue": d.Queue, "bytes": len(d.Body)})
			return err
		}

		log.Info("comparing language group", "trace_id", decoded.TraceID, "data", map[string]any{
			"analysis_problem_id":  decoded.AnalysisProblemID,
			"language":             string(decoded.Language),
			"language_group_index": decoded.LanguageGroupIndex,
			"language_group_total": decoded.LanguageGroupTotal,
			"submissions":          len(decoded.Submissions),
			"retry_count":          d.RetryCount,
		})

		ws, err := workspace.New(workspaceRoot)
		if err != nil {
			log.Error("creating workspace", logger.Err(err), "trace_id", decoded.TraceID)
			return err
		}
		// The workspace goes whether the job succeeded or not: it holds
		// student source, and a container that keeps it fills its tmpfs by
		// the end of an assessment week.
		defer func() {
			if err := ws.Close(); err != nil {
				log.Warn("removing workspace", logger.Err(err), "trace_id", decoded.TraceID,
					"data", map[string]any{"dir": ws.Dir()})
			}
		}()

		started := time.Now()
		fetched := handler.Fetch(ctx, ws, sources, decoded)

		downloaded := 0
		for _, result := range fetched {
			if result.Err != nil {
				log.Warn("source unavailable", logger.Err(result.Err), "trace_id", decoded.TraceID,
					"data", map[string]any{
						"submission_id":          result.Submission.SubmissionID,
						"analysis_submission_id": result.Submission.AnalysisSubmissionID,
					})
				continue
			}
			downloaded++
		}

		log.Info("sources ready", "trace_id", decoded.TraceID, "data", map[string]any{
			"analysis_problem_id": decoded.AnalysisProblemID,
			"downloaded":          downloaded,
			"failed":              len(fetched) - downloaded,
			"duration_ms":         time.Since(started).Milliseconds(),
		})

		// Fewer than two readable files is not a comparison. api marks such
		// groups not_applicable before publishing, so reaching this means
		// downloads failed — which E3 reports as a per-submission error.
		if downloaded < 2 {
			log.Warn("too few sources to compare", "trace_id", decoded.TraceID, "data", map[string]any{
				"analysis_problem_id": decoded.AnalysisProblemID,
				"downloaded":          downloaded,
			})
			return nil
		}

		pairs, err := comparer.Compare(ctx, ws.Dir(), decoded.Language)
		if err != nil {
			log.Error("comparison failed", logger.Err(err), "trace_id", decoded.TraceID,
				"data", map[string]any{"analysis_problem_id": decoded.AnalysisProblemID})
			return err
		}

		log.Info("language group compared", "trace_id", decoded.TraceID, "data", map[string]any{
			"analysis_problem_id":  decoded.AnalysisProblemID,
			"language_group_index": decoded.LanguageGroupIndex,
			"pairs":                len(pairs),
			"duration_ms":          time.Since(started).Milliseconds(),
		})

		return nil
	}
}
