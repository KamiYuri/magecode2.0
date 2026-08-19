package handler

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/magecode/plagiarism-checker/internal/dolos"
	"github.com/magecode/shared/go/httpsource"
	"github.com/magecode/plagiarism-checker/internal/job"
	"github.com/magecode/plagiarism-checker/internal/result"
	"github.com/magecode/shared/go/apperror"
	"github.com/magecode/shared/go/logger"
	"github.com/magecode/shared/go/rmq"
)

type published struct {
	queue   string
	traceID string
	body    []byte
}

type fakePublisher struct {
	sent []published
	err  error
}

func (p *fakePublisher) Publish(_ context.Context, queue string, body []byte, traceID string) error {
	if p.err != nil {
		return p.err
	}
	p.sent = append(p.sent, published{queue: queue, traceID: traceID, body: body})
	return nil
}

func (p *fakePublisher) Close() error { return nil }

func (p *fakePublisher) only(t *testing.T) result.Analysis {
	t.Helper()
	if len(p.sent) != 1 {
		t.Fatalf("published %d messages, want 1", len(p.sent))
	}
	var message result.Analysis
	if err := json.Unmarshal(p.sent[0].body, &message); err != nil {
		t.Fatalf("decoding the published message: %v", err)
	}
	return message
}

type stubComparer struct {
	pairs []dolos.Pair
	err   error
	dir   string
}

func (c *stubComparer) Compare(_ context.Context, dir string, _ job.Language) ([]dolos.Pair, error) {
	c.dir = dir
	return c.pairs, c.err
}

func testHandler(t *testing.T, publisher Publisher, comparer Comparer) *Handler {
	t.Helper()
	return New(Config{
		Sources: httpsource.New(httpsource.Config{
			MaxBytes: 64 * 1024, Attempts: 1, BaseDelay: time.Millisecond, Timeout: 2 * time.Second,
		}),
		Comparer:      comparer,
		Publisher:     publisher,
		WorkspaceRoot: t.TempDir(),
		Log:           logger.New("plagiarism-checker-test"),
	})
}

func sourceServer(t *testing.T, forbidden map[string]bool) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if forbidden[r.URL.Path] {
			w.WriteHeader(http.StatusForbidden)
			return
		}
		_, _ = fmt.Fprintf(w, "print('%s')\n", r.URL.Path)
	}))
	t.Cleanup(server.Close)
	return server
}

func delivery(server *httptest.Server, paths ...string) rmq.Delivery {
	submissions := make([]string, 0, len(paths))
	for i, path := range paths {
		submissions = append(submissions, fmt.Sprintf(
			`{"submission_id":%d,"analysis_submission_id":%d,"file_url":"%s%s"}`,
			11+i, 21+i, server.URL, path))
	}
	body := fmt.Sprintf(`{"analysis_problem_id":7,"language":"python",`+
		`"language_group_index":0,"language_group_total":1,"submissions":[%s],`+
		`"trace_id":"1b4e28ba-2fa1-11d2-883f-0016d3cca427",`+
		`"timestamp":"2026-08-19T09:30:00Z","version":"1.0"}`,
		join(submissions))
	return rmq.Delivery{Queue: job.QueueName, Body: []byte(body), TraceID: "1b4e28ba-2fa1-11d2-883f-0016d3cca427"}
}

func join(parts []string) string {
	out := ""
	for i, part := range parts {
		if i > 0 {
			out += ","
		}
		out += part
	}
	return out
}

func TestHandlePublishesTheComparison(t *testing.T) {
	server := sourceServer(t, nil)
	publisher := &fakePublisher{}
	comparer := &stubComparer{pairs: []dolos.Pair{{SubmissionAID: 11, SubmissionBID: 12, Similarity: 0.9}}}

	if err := testHandler(t, publisher, comparer).Handle(context.Background(), delivery(server, "/a", "/b")); err != nil {
		t.Fatalf("Handle: %v", err)
	}

	if publisher.sent[0].queue != result.ResultQueue {
		t.Errorf("published to %q, want %q", publisher.sent[0].queue, result.ResultQueue)
	}
	// The trace id travels as a header too (D-88), which is what api reads
	// when the body is unreadable.
	if publisher.sent[0].traceID != "1b4e28ba-2fa1-11d2-883f-0016d3cca427" {
		t.Errorf("header trace_id = %q", publisher.sent[0].traceID)
	}

	message := publisher.only(t)
	if message.Status != result.StatusCompleted {
		t.Errorf("status = %q", message.Status)
	}
	if len(message.Pairs) != 1 || message.Pairs[0].Similarity != 0.9 {
		t.Errorf("pairs = %+v", message.Pairs)
	}
	if len(message.SubmissionStatuses) != 2 {
		t.Errorf("submission_statuses = %+v", message.SubmissionStatuses)
	}
}

// The workspace holds student source and compose mounts it on tmpfs; a job
// that leaves it behind fills the container by the end of an assessment week.
func TestHandleRemovesTheWorkspace(t *testing.T) {
	server := sourceServer(t, nil)
	comparer := &stubComparer{}
	handler := testHandler(t, &fakePublisher{}, comparer)

	if err := handler.Handle(context.Background(), delivery(server, "/a", "/b")); err != nil {
		t.Fatalf("Handle: %v", err)
	}

	if comparer.dir == "" {
		t.Fatal("the comparer was never called")
	}
	if _, err := os.Stat(comparer.dir); !os.IsNotExist(err) {
		t.Errorf("workspace %s survived (err = %v)", comparer.dir, err)
	}
	leftovers, _ := filepath.Glob(filepath.Join(handler.workspaceRoot, "sim-*"))
	if len(leftovers) != 0 {
		t.Errorf("workspaces left behind: %v", leftovers)
	}
}

// D4 was written to ingest exactly this: the pairs among the readable files,
// with the unreadable one reported against its own submission.
func TestHandleReportsOneBadSourceAndKeepsTheRest(t *testing.T) {
	server := sourceServer(t, map[string]bool{"/gone": true})
	publisher := &fakePublisher{}
	comparer := &stubComparer{pairs: []dolos.Pair{{SubmissionAID: 11, SubmissionBID: 13, Similarity: 0.7}}}

	err := testHandler(t, publisher, comparer).Handle(context.Background(),
		delivery(server, "/a", "/gone", "/c"))
	if err != nil {
		t.Fatalf("Handle: %v", err)
	}

	message := publisher.only(t)
	if message.Status != result.StatusCompleted {
		t.Errorf("status = %q, want completed", message.Status)
	}
	byID := map[int64]result.SubmissionStatus{}
	for _, status := range message.SubmissionStatuses {
		byID[status.AnalysisSubmissionID] = status
	}
	if byID[22].Status != result.StatusError {
		t.Errorf("analysis_submission 22 is %q, want error", byID[22].Status)
	}
	if byID[21].Status != result.StatusCompleted || byID[23].Status != result.StatusCompleted {
		t.Errorf("the readable submissions are %q / %q", byID[21].Status, byID[23].Status)
	}
	if len(message.Pairs) != 1 {
		t.Errorf("pairs = %+v — the readable pair must survive", message.Pairs)
	}
}

// Fewer than two readable files is not a comparison. api never publishes such
// a group, so reaching this means downloads failed — and the answer still has
// to go out, or the batch waits for the sweeper.
func TestHandleAnswersWhenTooFewSourcesSurvive(t *testing.T) {
	server := sourceServer(t, map[string]bool{"/gone": true})
	publisher := &fakePublisher{}
	comparer := &stubComparer{}

	err := testHandler(t, publisher, comparer).Handle(context.Background(), delivery(server, "/a", "/gone"))
	if err != nil {
		t.Fatalf("Handle: %v", err)
	}

	if comparer.dir != "" {
		t.Error("Dolos was run over a single file")
	}
	message := publisher.only(t)
	if len(message.Pairs) != 0 {
		t.Errorf("pairs = %+v, want none", message.Pairs)
	}
	byID := map[int64]string{}
	for _, status := range message.SubmissionStatuses {
		byID[status.AnalysisSubmissionID] = status.Status
	}
	if byID[22] != result.StatusError {
		t.Errorf("the unreadable submission is %q", byID[22])
	}
}

// A comparison SIM cannot make is answered, not dead-lettered: the batch is
// waiting on this message and D-82 would otherwise hold it for 30 minutes.
func TestHandleAnswersAFailedComparisonWithAnErrorMessage(t *testing.T) {
	server := sourceServer(t, nil)
	publisher := &fakePublisher{}
	comparer := &stubComparer{err: apperror.New(apperror.Permanent, "dolos exceeded its 5m0s timeout")}

	err := testHandler(t, publisher, comparer).Handle(context.Background(), delivery(server, "/a", "/b"))
	if err != nil {
		t.Fatalf("Handle returned %v — a failed comparison must be reported, not retried", err)
	}

	message := publisher.only(t)
	if message.Status != result.StatusError {
		t.Errorf("status = %q, want error", message.Status)
	}
	if message.ErrorMessage == "" {
		t.Error("no error_message")
	}
	for _, status := range message.SubmissionStatuses {
		if status.Status != result.StatusError {
			t.Errorf("analysis_submission %d is %q", status.AnalysisSubmissionID, status.Status)
		}
	}
}

// A body SIM cannot decode names no submission to answer for, so it is the one
// failure that belongs on the DLQ.
func TestHandleDeadLettersAnUnreadableJob(t *testing.T) {
	publisher := &fakePublisher{}

	err := testHandler(t, publisher, &stubComparer{}).Handle(context.Background(),
		rmq.Delivery{Queue: job.QueueName, Body: []byte(`{"nope":true}`), TraceID: "t"})
	if err == nil {
		t.Fatal("Handle accepted an unreadable job")
	}
	if !apperror.IsPermanent(err) {
		t.Errorf("error is not Permanent: %v", err)
	}
	if len(publisher.sent) != 0 {
		t.Errorf("published %d messages for a job it could not read", len(publisher.sent))
	}
}

// Losing the broker after the work is done must retry the delivery: acking
// here would throw the whole comparison away and leave the batch to time out.
func TestHandleRetriesWhenTheResultCannotBePublished(t *testing.T) {
	server := sourceServer(t, nil)
	publisher := &fakePublisher{err: errors.New("broker is gone")}

	err := testHandler(t, publisher, &stubComparer{}).Handle(context.Background(), delivery(server, "/a", "/b"))
	if err == nil {
		t.Fatal("Handle acked a result it never published")
	}
	if apperror.IsPermanent(err) {
		t.Errorf("a broker failure should be retried, got Permanent: %v", err)
	}
}
