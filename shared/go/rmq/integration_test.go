//go:build integration

// Integration tests against the compose RabbitMQ broker. Run with:
//
//	RMQ_TEST_URL="amqp://user:pass@localhost:5672/vhost" \
//	  go test -tags integration ./rmq/ -v
//
// The suite skips when RMQ_TEST_URL is unset.
package rmq_test

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

	"github.com/magecode/shared/go/apperror"
	"github.com/magecode/shared/go/rmq"
)

const waitTimeout = 15 * time.Second

func brokerURL(t *testing.T) string {
	t.Helper()
	url := os.Getenv("RMQ_TEST_URL")
	if url == "" {
		t.Skip("RMQ_TEST_URL not set; start compose rabbitmq and export it")
	}
	return url
}

// testQueue returns a unique queue name and registers cleanup that deletes
// the queue and its retry/dlq companions.
func testQueue(t *testing.T, url string) string {
	t.Helper()
	suffix := make([]byte, 4)
	if _, err := rand.Read(suffix); err != nil {
		t.Fatalf("generating queue suffix: %v", err)
	}
	queue := fmt.Sprintf("it-%s-%s", t.Name(), hex.EncodeToString(suffix))

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

// queueDepth returns the ready-message count of a queue via a passive declare.
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

func TestPublishConsumeRoundtrip(t *testing.T) {
	url := brokerURL(t)
	queue := testQueue(t, url)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	pub, err := rmq.NewPublisher(ctx, rmq.Config{URL: url})
	if err != nil {
		t.Fatalf("NewPublisher: %v", err)
	}
	defer pub.Close()
	if err := pub.Publish(ctx, queue, []byte(`{"submission_id":1}`), "trace-rt"); err != nil {
		t.Fatalf("Publish: %v", err)
	}

	con, err := rmq.NewConsumer(ctx, rmq.Config{URL: url})
	if err != nil {
		t.Fatalf("NewConsumer: %v", err)
	}
	defer con.Close()

	var got atomic.Pointer[rmq.Delivery]
	consumeDone := make(chan error, 1)
	go func() {
		consumeDone <- con.Consume(ctx, queue, func(_ context.Context, d rmq.Delivery) error {
			got.Store(&d)
			return nil
		})
	}()

	waitFor(t, "delivery", func() bool { return got.Load() != nil })
	cancel()
	if err := <-consumeDone; err != nil {
		t.Fatalf("Consume returned error: %v", err)
	}

	d := got.Load()
	if string(d.Body) != `{"submission_id":1}` {
		t.Errorf("Body = %s", d.Body)
	}
	if d.TraceID != "trace-rt" {
		t.Errorf("TraceID = %q, want trace-rt", d.TraceID)
	}
	if d.RetryCount != 0 {
		t.Errorf("RetryCount = %d, want 0", d.RetryCount)
	}
	if depth := queueDepth(t, url, queue); depth != 0 {
		t.Errorf("main queue depth = %d after ack, want 0", depth)
	}
}

func TestFailingHandlerDeadLettersAfterMaxRetries(t *testing.T) {
	url := brokerURL(t)
	queue := testQueue(t, url)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	cfg := rmq.Config{URL: url, MaxRetries: 3, RetryBaseDelay: 50 * time.Millisecond}
	pub, err := rmq.NewPublisher(ctx, cfg)
	if err != nil {
		t.Fatalf("NewPublisher: %v", err)
	}
	defer pub.Close()
	if err := pub.Publish(ctx, queue, []byte("poison"), "trace-dlx"); err != nil {
		t.Fatalf("Publish: %v", err)
	}

	con, err := rmq.NewConsumer(ctx, cfg)
	if err != nil {
		t.Fatalf("NewConsumer: %v", err)
	}
	defer con.Close()

	var attempts atomic.Int32
	consumeDone := make(chan error, 1)
	go func() {
		consumeDone <- con.Consume(ctx, queue, func(_ context.Context, d rmq.Delivery) error {
			attempts.Add(1)
			return apperror.New(apperror.Transient, "always failing")
		})
	}()

	// 1 original delivery + 3 retries, then parked on the DLQ.
	waitFor(t, "4 delivery attempts", func() bool { return attempts.Load() == 4 })
	waitFor(t, "message in DLQ", func() bool { return queueDepth(t, url, queue+".dlq") == 1 })

	cancel()
	if err := <-consumeDone; err != nil {
		t.Fatalf("Consume returned error: %v", err)
	}
	if n := attempts.Load(); n != 4 {
		t.Errorf("attempts = %d, want exactly 4", n)
	}
	if depth := queueDepth(t, url, queue); depth != 0 {
		t.Errorf("main queue depth = %d, want 0", depth)
	}
	if depth := queueDepth(t, url, queue+".retry"); depth != 0 {
		t.Errorf("retry queue depth = %d, want 0", depth)
	}
}

func TestPermanentErrorDeadLettersImmediately(t *testing.T) {
	url := brokerURL(t)
	queue := testQueue(t, url)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	cfg := rmq.Config{URL: url, RetryBaseDelay: 50 * time.Millisecond}
	pub, err := rmq.NewPublisher(ctx, cfg)
	if err != nil {
		t.Fatalf("NewPublisher: %v", err)
	}
	defer pub.Close()
	if err := pub.Publish(ctx, queue, []byte("malformed"), "trace-perm"); err != nil {
		t.Fatalf("Publish: %v", err)
	}

	con, err := rmq.NewConsumer(ctx, cfg)
	if err != nil {
		t.Fatalf("NewConsumer: %v", err)
	}
	defer con.Close()

	var attempts atomic.Int32
	consumeDone := make(chan error, 1)
	go func() {
		consumeDone <- con.Consume(ctx, queue, func(_ context.Context, d rmq.Delivery) error {
			attempts.Add(1)
			return apperror.New(apperror.Permanent, "unparseable payload")
		})
	}()

	waitFor(t, "message in DLQ", func() bool { return queueDepth(t, url, queue+".dlq") == 1 })
	cancel()
	if err := <-consumeDone; err != nil {
		t.Fatalf("Consume returned error: %v", err)
	}
	if n := attempts.Load(); n != 1 {
		t.Errorf("attempts = %d, want 1 (no retries for permanent errors)", n)
	}
}

func TestGracefulDrainFinishesInFlightHandler(t *testing.T) {
	url := brokerURL(t)
	queue := testQueue(t, url)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	pub, err := rmq.NewPublisher(ctx, rmq.Config{URL: url})
	if err != nil {
		t.Fatalf("NewPublisher: %v", err)
	}
	defer pub.Close()
	if err := pub.Publish(ctx, queue, []byte("slow"), "trace-drain"); err != nil {
		t.Fatalf("Publish: %v", err)
	}

	con, err := rmq.NewConsumer(ctx, rmq.Config{URL: url})
	if err != nil {
		t.Fatalf("NewConsumer: %v", err)
	}
	defer con.Close()

	started := make(chan struct{})
	var finished atomic.Bool
	consumeDone := make(chan error, 1)
	go func() {
		consumeDone <- con.Consume(ctx, queue, func(_ context.Context, d rmq.Delivery) error {
			close(started)
			time.Sleep(300 * time.Millisecond) // simulates work outliving SIGTERM
			finished.Store(true)
			return nil
		})
	}()

	<-started
	cancel() // SIGTERM equivalent (signal.NotifyContext, D-73)

	select {
	case err := <-consumeDone:
		if err != nil {
			t.Fatalf("Consume returned error: %v", err)
		}
	case <-time.After(waitTimeout):
		t.Fatal("Consume did not return after context cancellation")
	}

	if !finished.Load() {
		t.Error("Consume returned before in-flight handler finished")
	}
	if depth := queueDepth(t, url, queue); depth != 0 {
		t.Errorf("main queue depth = %d, want 0 (message must be acked, not redelivered)", depth)
	}
}
