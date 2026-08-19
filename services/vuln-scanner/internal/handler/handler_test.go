package handler

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/magecode/shared/go/apperror"
	"github.com/magecode/shared/go/httpsource"
	"github.com/magecode/shared/go/logger"
	"github.com/magecode/shared/go/rmq"
	"github.com/magecode/vuln-scanner/internal/job"
	"github.com/magecode/vuln-scanner/internal/result"
	"github.com/magecode/vuln-scanner/internal/sarif"
)

type fakePublisher struct {
	sent []struct {
		queue   string
		traceID string
		body    []byte
	}
	err error
}

func (p *fakePublisher) Publish(_ context.Context, queue string, body []byte, traceID string) error {
	if p.err != nil {
		return p.err
	}
	p.sent = append(p.sent, struct {
		queue   string
		traceID string
		body    []byte
	}{queue, traceID, body})
	return nil
}

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

type stubScanner struct {
	findings []sarif.Finding
	err      error
	source   []byte
	calls    int
}

func (s *stubScanner) Scan(_ context.Context, source []byte, _ job.Language) ([]sarif.Finding, error) {
	s.calls++
	s.source = source
	return s.findings, s.err
}

func testHandler(t *testing.T, publisher Publisher, scanner Scanner) *Handler {
	t.Helper()
	return New(Config{
		Sources: httpsource.New(httpsource.Config{
			MaxBytes: 64 * 1024, Attempts: 1, BaseDelay: time.Millisecond, Timeout: 2 * time.Second,
		}),
		Scanner:   scanner,
		Publisher: publisher,
		Log:       logger.New("vuln-scanner-test"),
	})
}

func sourceServer(t *testing.T, status int) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		if status != http.StatusOK {
			w.WriteHeader(status)
			return
		}
		_, _ = fmt.Fprint(w, "print(1)\n")
	}))
	t.Cleanup(server.Close)
	return server
}

func delivery(url, language string) rmq.Delivery {
	body := fmt.Sprintf(`{"analysis_submission_id":501,"submission_id":42,"file_url":"%s",`+
		`"language":"%s","trace_id":"1b4e28ba-2fa1-11d2-883f-0016d3cca427",`+
		`"timestamp":"2026-08-19T09:30:00Z","version":"1.0"}`, url, language)
	return rmq.Delivery{Queue: job.QueueName, Body: []byte(body),
		TraceID: "1b4e28ba-2fa1-11d2-883f-0016d3cca427"}
}

func TestHandlePublishesTheFindings(t *testing.T) {
	server := sourceServer(t, http.StatusOK)
	publisher := &fakePublisher{}
	scanner := &stubScanner{findings: []sarif.Finding{{Name: "py/sql-injection", Severity: "error"}}}

	if err := testHandler(t, publisher, scanner).Handle(context.Background(),
		delivery(server.URL, "python")); err != nil {
		t.Fatalf("Handle: %v", err)
	}

	if publisher.sent[0].queue != result.ResultQueue {
		t.Errorf("published to %q", publisher.sent[0].queue)
	}
	if string(scanner.source) != "print(1)\n" {
		t.Errorf("the scanner was handed %q", scanner.source)
	}

	message := publisher.only(t)
	if message.Status != result.StatusCompleted {
		t.Errorf("status = %q", message.Status)
	}
	if len(message.Findings) != 1 {
		t.Errorf("findings = %+v", message.Findings)
	}
}

// api replaces a submission's findings (D5), so a clean scan must still be
// published — it is what clears a previous run's.
func TestHandlePublishesACleanScan(t *testing.T) {
	server := sourceServer(t, http.StatusOK)
	publisher := &fakePublisher{}

	if err := testHandler(t, publisher, &stubScanner{}).Handle(context.Background(),
		delivery(server.URL, "python")); err != nil {
		t.Fatalf("Handle: %v", err)
	}

	message := publisher.only(t)
	if message.Status != result.StatusCompleted || len(message.Findings) != 0 {
		t.Errorf("message = %+v", message)
	}
}

func TestHandleAnswersAFailedScanRatherThanRetrying(t *testing.T) {
	server := sourceServer(t, http.StatusOK)
	publisher := &fakePublisher{}
	scanner := &stubScanner{err: apperror.New(apperror.Permanent, "codeql exceeded its 10m0s timeout")}

	if err := testHandler(t, publisher, scanner).Handle(context.Background(),
		delivery(server.URL, "python")); err != nil {
		t.Fatalf("Handle returned %v — a failed scan must be reported, not retried", err)
	}

	message := publisher.only(t)
	if message.Status != result.StatusError {
		t.Errorf("status = %q", message.Status)
	}
	if message.ErrorMessage == "" {
		t.Error("no error_message")
	}
}

func TestHandleAnswersAnUnreadableSource(t *testing.T) {
	// A 403 is an expired pre-signed URL (D-85); the submission cannot be
	// scanned and api needs to hear so.
	server := sourceServer(t, http.StatusForbidden)
	publisher := &fakePublisher{}
	scanner := &stubScanner{}

	if err := testHandler(t, publisher, scanner).Handle(context.Background(),
		delivery(server.URL, "python")); err != nil {
		t.Fatalf("Handle: %v", err)
	}

	if scanner.calls != 0 {
		t.Error("the scanner ran without a source")
	}
	if publisher.only(t).Status != result.StatusError {
		t.Errorf("status = %q", publisher.only(t).Status)
	}
}

func TestHandleDeadLettersAnUnreadableJob(t *testing.T) {
	publisher := &fakePublisher{}

	err := testHandler(t, publisher, &stubScanner{}).Handle(context.Background(),
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

func TestHandleRetriesWhenTheResultCannotBePublished(t *testing.T) {
	server := sourceServer(t, http.StatusOK)
	publisher := &fakePublisher{err: errors.New("broker is gone")}

	err := testHandler(t, publisher, &stubScanner{}).Handle(context.Background(),
		delivery(server.URL, "python"))
	if err == nil {
		t.Fatal("Handle acked a result it never published")
	}
	if apperror.IsPermanent(err) {
		t.Errorf("a broker failure should be retried: %v", err)
	}
}
