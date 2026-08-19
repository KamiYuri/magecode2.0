// Package handler runs one VUL job: fetch the submission, scan it, answer api.
package handler

import (
	"context"
	"log/slog"
	"time"

	"github.com/magecode/shared/go/apperror"
	"github.com/magecode/shared/go/httpsource"
	"github.com/magecode/shared/go/logger"
	"github.com/magecode/shared/go/rmq"
	"github.com/magecode/vuln-scanner/internal/codeql"
	"github.com/magecode/vuln-scanner/internal/job"
	"github.com/magecode/vuln-scanner/internal/result"
	"github.com/magecode/vuln-scanner/internal/sarif"
)

// Publisher is the half of rmq.Publisher this handler uses.
type Publisher interface {
	Publish(ctx context.Context, queue string, body []byte, traceID string) error
}

// Scanner runs the vulnerability analysis over one submission's source.
type Scanner interface {
	Scan(ctx context.Context, source []byte, language job.Language) ([]sarif.Finding, error)
}

// Config wires one handler.
type Config struct {
	Sources   *httpsource.Client
	Scanner   Scanner
	Publisher Publisher
	Log       *slog.Logger
	// ResultQueue is overridable so an integration test can drive the loop
	// without racing api's consumer for messages on the real queue.
	ResultQueue string
}

// Handler processes one VUL job end to end.
type Handler struct {
	sources     *httpsource.Client
	scanner     Scanner
	publisher   Publisher
	log         *slog.Logger
	resultQueue string
}

// New returns a Handler.
func New(cfg Config) *Handler {
	if cfg.ResultQueue == "" {
		cfg.ResultQueue = result.ResultQueue
	}
	return &Handler{
		sources:     cfg.Sources,
		scanner:     cfg.Scanner,
		publisher:   cfg.Publisher,
		log:         cfg.Log,
		resultQueue: cfg.ResultQueue,
	}
}

// Handle runs one delivery.
//
// **VUL always answers**, the rule SIM and AID follow for the same reason: api
// is waiting on a result for this submission, and a job that dead-letters
// leaves the batch to burn D-82's 30-minute timeout for an answer VUL already
// has. A failure it can describe — an expired URL, a scan that timed out, a
// language with no suite — is published and acked. The two exceptions are a
// body it cannot decode, which names no analysis_submission_id to answer for,
// and a broker it cannot reach, which is what retries are for.
func (h *Handler) Handle(ctx context.Context, d rmq.Delivery) error {
	decoded, err := job.Decode(d.Body)
	if err != nil {
		// d.TraceID rather than the body's: an undecodable body may not have
		// one, and the header is what the publisher set (D-88).
		h.log.Error("rejecting unreadable job", logger.Err(err), "trace_id", d.TraceID,
			"data", map[string]any{"queue": d.Queue, "bytes": len(d.Body)})
		return err
	}

	h.log.Info("scanning submission", "trace_id", decoded.TraceID, "data", map[string]any{
		"analysis_submission_id": decoded.AnalysisSubmissionID,
		"submission_id":          decoded.SubmissionID,
		"language":               string(decoded.Language),
		"retry_count":            d.RetryCount,
	})

	started := time.Now()
	message, err := h.scan(ctx, decoded)
	if err != nil {
		h.log.Error("scan failed", logger.Err(err), "trace_id", decoded.TraceID,
			"data", map[string]any{"analysis_submission_id": decoded.AnalysisSubmissionID})
		message = result.Failed(decoded, err, time.Now())
	}

	if err := h.publish(ctx, decoded, message); err != nil {
		return err
	}

	h.log.Info("result published", "trace_id", decoded.TraceID, "data", map[string]any{
		"analysis_submission_id": decoded.AnalysisSubmissionID,
		"status":                 message.Status,
		"findings":               len(message.Findings),
		"duration_ms":            time.Since(started).Milliseconds(),
	})
	return nil
}

func (h *Handler) scan(ctx context.Context, decoded job.Scan) (result.Analysis, error) {
	// A language with no suite in this build is a result, not a failure: D6
	// counts not_applicable as terminal, so the batch closes without it.
	if !codeql.Supports(decoded.Language) {
		h.log.Info("language not scanned by this build", "trace_id", decoded.TraceID,
			"data", map[string]any{
				"analysis_submission_id": decoded.AnalysisSubmissionID,
				"language":               string(decoded.Language),
			})
		return result.NotApplicable(decoded, time.Now()), nil
	}

	source, err := h.sources.Download(ctx, decoded.FileURL)
	if err != nil {
		return result.Analysis{}, err
	}

	findings, err := h.scanner.Scan(ctx, source, decoded.Language)
	if err != nil {
		return result.Analysis{}, err
	}

	return result.Completed(decoded, findings, time.Now()), nil
}

func (h *Handler) publish(ctx context.Context, decoded job.Scan, message result.Analysis) error {
	body, err := message.Encode()
	if err != nil {
		h.log.Error("encoding result", logger.Err(err), "trace_id", decoded.TraceID)
		return apperror.Wrap(apperror.Permanent, "encoding analysis result", err)
	}

	if err := h.publisher.Publish(ctx, h.resultQueue, body, message.TraceIdentifier()); err != nil {
		// Retried, deliberately: the delivery re-runs from the top, which
		// costs another scan, but acking here would throw the findings away
		// and leave the batch waiting on a message nobody will send.
		h.log.Error("publishing result", logger.Err(err), "trace_id", decoded.TraceID,
			"data", map[string]any{"queue": h.resultQueue})
		return apperror.Wrap(apperror.Transient, "publishing analysis result", err)
	}

	return nil
}
