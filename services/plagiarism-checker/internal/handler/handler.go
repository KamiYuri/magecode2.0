package handler

import (
	"context"
	"log/slog"
	"time"

	"github.com/magecode/plagiarism-checker/internal/dolos"
	"github.com/magecode/plagiarism-checker/internal/downloader"
	"github.com/magecode/plagiarism-checker/internal/job"
	"github.com/magecode/plagiarism-checker/internal/result"
	"github.com/magecode/plagiarism-checker/internal/workspace"
	"github.com/magecode/shared/go/apperror"
	"github.com/magecode/shared/go/logger"
	"github.com/magecode/shared/go/rmq"
)

// minComparableSources is what a comparison needs. api never publishes a
// smaller group — it marks those not_applicable — so falling below this means
// downloads failed.
const minComparableSources = 2

// Publisher is the half of rmq.Publisher this handler uses.
type Publisher interface {
	Publish(ctx context.Context, queue string, body []byte, traceID string) error
}

// Comparer runs the similarity analysis over a prepared workspace.
type Comparer interface {
	Compare(ctx context.Context, dir string, language job.Language) ([]dolos.Pair, error)
}

// Config wires one handler.
type Config struct {
	Sources       *downloader.Client
	Comparer      Comparer
	Publisher     Publisher
	WorkspaceRoot string
	Log           *slog.Logger
}

// Handler processes one SIM job end to end.
type Handler struct {
	sources       *downloader.Client
	comparer      Comparer
	publisher     Publisher
	workspaceRoot string
	log           *slog.Logger
}

// New returns a Handler.
func New(cfg Config) *Handler {
	return &Handler{
		sources:       cfg.Sources,
		comparer:      cfg.Comparer,
		publisher:     cfg.Publisher,
		workspaceRoot: cfg.WorkspaceRoot,
		log:           cfg.Log,
	}
}

// Handle runs one delivery: stage the group's sources, compare them, answer api.
//
// What it returns decides the message's fate (D-79e), and the rule this
// service follows is that **SIM always answers**. A failure it can describe —
// an unreadable source, a comparison that timed out — is published as a result
// with status error and acked, because api is waiting on this message and a
// dead-letter costs the batch D-82's full 30-minute timeout for an answer SIM
// already has. Only two things are not answered: a body it cannot decode,
// which names no submission to report against, and a broker it cannot reach,
// which is what retries are for.
func (h *Handler) Handle(ctx context.Context, d rmq.Delivery) error {
	decoded, err := job.Decode(d.Body)
	if err != nil {
		// d.TraceID rather than the body's: an undecodable body may not
		// have one, and the header is what the publisher set (D-88).
		h.log.Error("rejecting unreadable job", logger.Err(err), "trace_id", d.TraceID,
			"data", map[string]any{"queue": d.Queue, "bytes": len(d.Body)})
		return err
	}

	h.log.Info("comparing language group", "trace_id", decoded.TraceID, "data", map[string]any{
		"analysis_problem_id":  decoded.AnalysisProblemID,
		"language":             string(decoded.Language),
		"language_group_index": decoded.LanguageGroupIndex,
		"language_group_total": decoded.LanguageGroupTotal,
		"submissions":          len(decoded.Submissions),
		"retry_count":          d.RetryCount,
	})

	started := time.Now()

	ws, err := workspace.New(h.workspaceRoot)
	if err != nil {
		// Nothing can be staged, so nothing can be said about the group;
		// a bare error is retried, which is the right answer for a full or
		// unwritable tmpfs that may recover.
		h.log.Error("creating workspace", logger.Err(err), "trace_id", decoded.TraceID)
		return err
	}
	defer func() {
		if err := ws.Close(); err != nil {
			h.log.Warn("removing workspace", logger.Err(err), "trace_id", decoded.TraceID,
				"data", map[string]any{"dir": ws.Dir()})
		}
	}()

	failures, readable := h.stage(ctx, ws, decoded)

	if readable < minComparableSources {
		h.log.Warn("too few readable sources to compare", "trace_id", decoded.TraceID,
			"data", map[string]any{
				"analysis_problem_id": decoded.AnalysisProblemID,
				"readable":            readable,
				"submissions":         len(decoded.Submissions),
			})
		return h.publish(ctx, decoded, result.Completed(decoded, nil, failures, time.Now()))
	}

	pairs, err := h.comparer.Compare(ctx, ws.Dir(), decoded.Language)
	if err != nil {
		h.log.Error("comparison failed", logger.Err(err), "trace_id", decoded.TraceID,
			"data", map[string]any{"analysis_problem_id": decoded.AnalysisProblemID})
		return h.publish(ctx, decoded, result.Failed(decoded, err, time.Now()))
	}

	h.log.Info("language group compared", "trace_id", decoded.TraceID, "data", map[string]any{
		"analysis_problem_id":  decoded.AnalysisProblemID,
		"language_group_index": decoded.LanguageGroupIndex,
		"pairs":                len(pairs),
		"readable":             readable,
		"duration_ms":          time.Since(started).Milliseconds(),
	})

	return h.publish(ctx, decoded, result.Completed(decoded, pairs, failures, time.Now()))
}

// stage downloads the group into ws and returns the per-submission failures
// keyed by analysis_submission_id, plus how many files are actually there.
func (h *Handler) stage(ctx context.Context, ws *workspace.Workspace, decoded job.Similarity) (map[int64]error, int) {
	failures := make(map[int64]error)
	readable := 0

	for _, fetched := range Fetch(ctx, ws, h.sources, decoded) {
		if fetched.Err != nil {
			failures[fetched.Submission.AnalysisSubmissionID] = fetched.Err
			h.log.Warn("source unavailable", logger.Err(fetched.Err), "trace_id", decoded.TraceID,
				"data", map[string]any{
					"submission_id":          fetched.Submission.SubmissionID,
					"analysis_submission_id": fetched.Submission.AnalysisSubmissionID,
				})
			continue
		}
		readable++
	}

	return failures, readable
}

func (h *Handler) publish(ctx context.Context, decoded job.Similarity, message result.Analysis) error {
	body, err := message.Encode()
	if err != nil {
		// A message this build cannot encode is a bug in this build, and
		// three more attempts produce the same bytes.
		h.log.Error("encoding result", logger.Err(err), "trace_id", decoded.TraceID)
		return apperror.Wrap(apperror.Permanent, "encoding analysis result", err)
	}

	if err := h.publisher.Publish(ctx, message.Queue(), body, message.TraceIdentifier()); err != nil {
		// Retried, deliberately: the delivery is re-run from the top, which
		// costs another download and another comparison, but acking here
		// would throw the finished work away and leave the batch waiting on
		// a message nobody will send.
		h.log.Error("publishing result", logger.Err(err), "trace_id", decoded.TraceID,
			"data", map[string]any{"queue": message.Queue()})
		return apperror.Wrap(apperror.Transient, "publishing analysis result", err)
	}

	h.log.Info("result published", "trace_id", decoded.TraceID, "data", map[string]any{
		"analysis_problem_id":  decoded.AnalysisProblemID,
		"language_group_index": decoded.LanguageGroupIndex,
		"status":               message.Status,
		"pairs":                len(message.Pairs),
	})
	return nil
}
