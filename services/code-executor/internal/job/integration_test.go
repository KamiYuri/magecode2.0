//go:build integration

// Integration tests for the CES consumer loop against the compose broker. Run:
//
//	RMQ_TEST_URL="amqp://user:pass@localhost:5672/vhost" \
//	  go test -tags integration ./internal/job/ -v
//
// The suite skips when RMQ_TEST_URL is unset.
//
// These cover C4's contract — what the loop does with a message it cannot
// read, one that fails for a reason that might pass later, and a shutdown
// arriving mid-job. The handler stands in for C5/C6, which do not exist yet;
// what is under test is the settling, not the work.
package job_test

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"os"
	"sync/atomic"
	"testing"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"

	"github.com/magecode/code-executor/internal/job"
	"github.com/magecode/shared/go/apperror"
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
	queue := fmt.Sprintf("it-ces-%s", hex.EncodeToString(suffix))

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

func queueDepth(t *testing.T, url, queue string) int {
	t.Helper()
	conn, err := amqp.Dial(url)
	if err != nil {
		t.Fatalf("dialing for inspect: %v", err)
	}
	defer conn.Close()
	ch, err := conn.Channel()
	if err != nil {
		t.Fatalf("opening inspect channel: %v", err)
	}
	defer ch.Close()
	state, err := ch.QueueDeclarePassive(queue, true, false, false, false, nil)
	if err != nil {
		t.Fatalf("passive declare %s: %v", queue, err)
	}
	return state.Messages
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

func validJob(submissionID int64) []byte {
	return fmt.Appendf(nil,
		`{"submission_id":%d,"trace_id":"1b4e28ba-2fa1-11d2-883f-0016d3cca427",`+
			`"timestamp":"2026-08-14T09:30:00Z","version":"1.0"}`, submissionID)
}

// consume runs the consumer in the background and returns a cancel func.
func consume(t *testing.T, ctx context.Context, url, queue string, handler rmq.Handler) {
	t.Helper()
	con, err := rmq.NewConsumer(ctx, rmq.Config{URL: url, PrefetchCount: 5, Concurrency: 5})
	if err != nil {
		t.Fatalf("NewConsumer: %v", err)
	}
	t.Cleanup(func() { _ = con.Close() })

	go func() { _ = con.Consume(ctx, queue, handler) }()
}

func publish(t *testing.T, ctx context.Context, url, queue string, body []byte) {
	t.Helper()
	pub, err := rmq.NewPublisher(ctx, rmq.Config{URL: url})
	if err != nil {
		t.Fatalf("NewPublisher: %v", err)
	}
	defer pub.Close()
	if err := pub.Publish(ctx, queue, body, "trace-ces"); err != nil {
		t.Fatalf("Publish: %v", err)
	}
}

// C4: a message CES cannot read goes straight to the DLQ. Retrying it would
// occupy a worker three more times to reach the same conclusion.
func TestMalformedMessageIsDeadLetteredWithoutRetrying(t *testing.T) {
	url := brokerURL(t)
	queue := testQueue(t, url)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	publish(t, ctx, url, queue, []byte(`{"submission_id":"not-a-number"}`))

	var attempts atomic.Int64
	consume(t, ctx, url, queue, func(_ context.Context, d rmq.Delivery) error {
		attempts.Add(1)
		_, err := job.Decode(d.Body)
		return err
	})

	waitFor(t, "message in DLQ", func() bool { return queueDepth(t, url, queue+".dlq") == 1 })

	// Give a retry a chance to appear before asserting there was none.
	time.Sleep(500 * time.Millisecond)
	if got := attempts.Load(); got != 1 {
		t.Errorf("handler ran %d times, want 1 (a permanent failure must not retry)", got)
	}
	if depth := queueDepth(t, url, queue); depth != 0 {
		t.Errorf("main queue depth = %d, want 0", depth)
	}
}

// C4: a failure that might pass later is retried, and only up to the budget.
func TestTransientFailureIsRedeliveredUpToTheRetryBudget(t *testing.T) {
	url := brokerURL(t)
	queue := testQueue(t, url)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	publish(t, ctx, url, queue, validJob(7))

	var attempts atomic.Int64
	con, err := rmq.NewConsumer(ctx, rmq.Config{
		URL:            url,
		PrefetchCount:  5,
		Concurrency:    5,
		RetryBaseDelay: 100 * time.Millisecond,
	})
	if err != nil {
		t.Fatalf("NewConsumer: %v", err)
	}
	defer con.Close()

	go func() {
		_ = con.Consume(ctx, queue, func(_ context.Context, d rmq.Delivery) error {
			if _, err := job.Decode(d.Body); err != nil {
				return err
			}
			attempts.Add(1)
			// Stands in for a database or Judge0 outage in C5/C6.
			return apperror.New(apperror.Transient, "judge0 unreachable")
		})
	}()

	waitFor(t, "message in DLQ", func() bool { return queueDepth(t, url, queue+".dlq") == 1 })

	// D-79e: the first delivery plus three retries.
	if got := attempts.Load(); got != 4 {
		t.Errorf("handler ran %d times, want 4 (1 delivery + 3 retries)", got)
	}
}

// C4: SIGTERM mid-job finishes the job rather than abandoning a student's
// submission halfway through grading.
func TestShutdownMidJobCompletesTheJob(t *testing.T) {
	url := brokerURL(t)
	queue := testQueue(t, url)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	publish(t, ctx, url, queue, validJob(11))

	con, err := rmq.NewConsumer(ctx, rmq.Config{URL: url, PrefetchCount: 5, Concurrency: 5})
	if err != nil {
		t.Fatalf("NewConsumer: %v", err)
	}
	defer con.Close()

	started := make(chan struct{})
	var completed atomic.Bool
	consumeDone := make(chan error, 1)
	go func() {
		consumeDone <- con.Consume(ctx, queue, func(_ context.Context, d rmq.Delivery) error {
			if _, err := job.Decode(d.Body); err != nil {
				return err
			}
			close(started)
			time.Sleep(400 * time.Millisecond) // grading outlives the signal
			completed.Store(true)
			return nil
		})
	}()

	<-started
	cancel() // signal.NotifyContext's effect on SIGTERM

	select {
	case err := <-consumeDone:
		if err != nil {
			t.Fatalf("Consume returned error: %v", err)
		}
	case <-time.After(waitTimeout):
		t.Fatal("Consume did not return after shutdown")
	}

	if !completed.Load() {
		t.Error("shutdown abandoned the in-flight job")
	}
	if depth := queueDepth(t, url, queue); depth != 0 {
		t.Errorf("main queue depth = %d, want 0 (the job must be acked, not redelivered)", depth)
	}
}
