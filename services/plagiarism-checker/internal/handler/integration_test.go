//go:build integration

// Integration test for E1: a job published to a real broker becomes a
// workspace of files named by submission id, and the workspace is gone once
// the job settles. Run:
//
//	RMQ_TEST_URL="amqp://user:pass@localhost:5672/vhost" \
//	  go test -tags integration ./internal/handler/ -v
//
// The suite skips when RMQ_TEST_URL is unset.
package handler_test

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sort"
	"testing"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"

	"github.com/magecode/plagiarism-checker/internal/downloader"
	"github.com/magecode/plagiarism-checker/internal/handler"
	"github.com/magecode/plagiarism-checker/internal/job"
	"github.com/magecode/plagiarism-checker/internal/workspace"
	"github.com/magecode/shared/go/rmq"
)

const waitTimeout = 20 * time.Second

func brokerURL(t *testing.T) string {
	t.Helper()
	url := os.Getenv("RMQ_TEST_URL")
	if url == "" {
		t.Skip("RMQ_TEST_URL not set; start compose rabbitmq and export it")
	}
	return url
}

func testQueue(t *testing.T, url string) string {
	t.Helper()
	suffix := make([]byte, 4)
	if _, err := rand.Read(suffix); err != nil {
		t.Fatalf("generating queue suffix: %v", err)
	}
	queue := fmt.Sprintf("it-sim-%s", hex.EncodeToString(suffix))

	t.Cleanup(func() {
		conn, err := amqp.Dial(url)
		if err != nil {
			return
		}
		defer conn.Close()
		ch, err := conn.Channel()
		if err != nil {
			return
		}
		defer ch.Close()
		for _, q := range []string{queue, queue + ".retry", queue + ".dlq"} {
			_, _ = ch.QueueDelete(q, false, false, false)
		}
	})
	return queue
}

func waitFor(t *testing.T, what string, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(waitTimeout)
	for time.Now().Before(deadline) {
		if cond() {
			return
		}
		time.Sleep(50 * time.Millisecond)
	}
	t.Fatalf("timed out after %v waiting for %s", waitTimeout, what)
}

// TestJobBecomesAWorkspaceAndIsCleanedUp is E1's verification: fixture job in,
// files on disk named by submission_id, workspace removed after the ack.
func TestJobBecomesAWorkspaceAndIsCleanedUp(t *testing.T) {
	url := brokerURL(t)
	queue := testQueue(t, url)

	sources := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = fmt.Fprintf(w, "# source for %s\nprint(1)\n", r.URL.Path)
	}))
	defer sources.Close()

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	root := t.TempDir()
	type observation struct {
		dir   string
		names []string
	}
	observed := make(chan observation, 1)

	consumer, err := rmq.NewConsumer(ctx, rmq.Config{URL: url, PrefetchCount: 1})
	if err != nil {
		t.Fatalf("NewConsumer: %v", err)
	}
	t.Cleanup(func() { _ = consumer.Close() })

	client := downloader.New(downloader.Config{MaxBytes: 64 * 1024, Attempts: 2, BaseDelay: time.Millisecond})

	go func() {
		_ = consumer.Consume(ctx, queue, func(ctx context.Context, d rmq.Delivery) error {
			decoded, err := job.Decode(d.Body)
			if err != nil {
				return err
			}
			ws, err := workspace.New(root)
			if err != nil {
				return err
			}
			defer func() { _ = ws.Close() }()

			for _, fetched := range handler.Fetch(ctx, ws, client, decoded) {
				if fetched.Err != nil {
					return fetched.Err
				}
			}

			entries, err := os.ReadDir(ws.Dir())
			if err != nil {
				return err
			}
			names := make([]string, 0, len(entries))
			for _, entry := range entries {
				names = append(names, entry.Name())
			}
			sort.Strings(names)
			observed <- observation{dir: ws.Dir(), names: names}
			return nil
		})
	}()

	publisher, err := rmq.NewPublisher(ctx, rmq.Config{URL: url})
	if err != nil {
		t.Fatalf("NewPublisher: %v", err)
	}
	t.Cleanup(func() { _ = publisher.Close() })

	body := fmt.Appendf(nil,
		`{"analysis_problem_id":7,"language":"python","language_group_index":0,`+
			`"language_group_total":1,"submissions":[`+
			`{"submission_id":11,"analysis_submission_id":21,"file_url":"%s/a"},`+
			`{"submission_id":12,"analysis_submission_id":22,"file_url":"%s/b"}],`+
			`"trace_id":"1b4e28ba-2fa1-11d2-883f-0016d3cca427",`+
			`"timestamp":"2026-08-19T09:30:00Z","version":"1.0"}`, sources.URL, sources.URL)

	if err := publisher.Publish(ctx, queue, body, "1b4e28ba-2fa1-11d2-883f-0016d3cca427"); err != nil {
		t.Fatalf("Publish: %v", err)
	}

	var got observation
	select {
	case got = <-observed:
	case <-time.After(waitTimeout):
		t.Fatal("the job was never processed")
	}

	want := []string{"11.py", "12.py"}
	if len(got.names) != len(want) {
		t.Fatalf("workspace held %v, want %v", got.names, want)
	}
	for i, name := range want {
		if got.names[i] != name {
			t.Errorf("workspace held %v, want %v", got.names, want)
			break
		}
	}

	waitFor(t, "the workspace to be removed", func() bool {
		_, err := os.Stat(got.dir)
		return os.IsNotExist(err)
	})

	// Nothing else should be left behind in the root either.
	leftovers, err := filepath.Glob(filepath.Join(root, "sim-*"))
	if err != nil {
		t.Fatalf("globbing root: %v", err)
	}
	if len(leftovers) != 0 {
		t.Errorf("workspaces left behind: %v", leftovers)
	}
}
