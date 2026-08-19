// Package rmq provides the shared RabbitMQ publisher and consumer for
// MageCode Go services: durable queues with a per-queue retry queue and DLQ
// (D-79e: 3 retries, exponential backoff via per-message TTL), persistent
// publishing with confirms, manual ack with configurable prefetch (D-76),
// reconnect with capped backoff, and graceful drain on shutdown (D-73).
package rmq

import (
	"context"
	"io"
	"log/slog"
	"time"
)

const (
	defaultMaxRetries         = 3
	defaultPrefetchCount      = 1
	defaultConcurrency        = 1
	defaultRetryBaseDelay     = time.Second
	defaultReconnectBaseDelay = 500 * time.Millisecond
	defaultReconnectMaxDelay  = 30 * time.Second

	headerRetryCount = "x-retry-count"
	headerLastError  = "x-last-error"
	headerTraceID    = "trace_id"
)

// Config configures a Publisher or Consumer. URL is required; zero values
// elsewhere take the documented defaults.
type Config struct {
	URL string
	// PrefetchCount is the per-consumer unacked message limit (D-76:
	// CES 5, SIM 1, AID/VUL 3). Default 1.
	PrefetchCount int
	// Concurrency is how many handlers run at once inside the prefetch
	// window (D-75: CES 5). Default 1, which processes deliveries one at a
	// time. Values above PrefetchCount are capped to it, since a worker the
	// broker will never feed is only a goroutine.
	Concurrency int
	// MaxRetries is the number of delayed retries before a message is
	// dead-lettered. Default 3 (D-79e).
	MaxRetries int
	// RetryBaseDelay is the first retry delay; each retry doubles it.
	// Default 1s.
	RetryBaseDelay     time.Duration
	ReconnectBaseDelay time.Duration
	ReconnectMaxDelay  time.Duration
	Logger             *slog.Logger
}

func (c Config) withDefaults() Config {
	if c.PrefetchCount == 0 {
		c.PrefetchCount = defaultPrefetchCount
	}
	if c.Concurrency < 1 {
		c.Concurrency = defaultConcurrency
	}
	if c.Concurrency > c.PrefetchCount {
		c.Concurrency = c.PrefetchCount
	}
	if c.MaxRetries == 0 {
		c.MaxRetries = defaultMaxRetries
	}
	if c.RetryBaseDelay == 0 {
		c.RetryBaseDelay = defaultRetryBaseDelay
	}
	if c.ReconnectBaseDelay == 0 {
		c.ReconnectBaseDelay = defaultReconnectBaseDelay
	}
	if c.ReconnectMaxDelay == 0 {
		c.ReconnectMaxDelay = defaultReconnectMaxDelay
	}
	if c.Logger == nil {
		c.Logger = slog.New(slog.NewJSONHandler(io.Discard, nil))
	}
	return c
}

// Delivery is one message handed to a Handler.
type Delivery struct {
	Queue string
	Body  []byte
	// TraceID is the cross-service tracing ID from the message header,
	// empty when absent (D-88).
	TraceID string
	// RetryCount is the number of retries this message has already
	// consumed (0 on first delivery).
	RetryCount int
}

// Handler processes one delivery. A nil return acks the message. An error
// return schedules a retry or dead-letters it based on the error's
// apperror classification and the remaining retry budget.
type Handler func(ctx context.Context, d Delivery) error

// Publisher publishes persistent JSON messages to a queue.
type Publisher interface {
	// Publish sends body to queue with delivery_mode persistent and
	// content type application/json, waiting for broker confirm.
	// traceID may be empty.
	Publish(ctx context.Context, queue string, body []byte, traceID string) error
	io.Closer
}

// Consumer consumes a queue with manual ack until its context is canceled.
type Consumer interface {
	// Consume blocks, delivering messages to handler one prefetch window
	// at a time. On ctx cancellation it stops accepting new deliveries,
	// lets in-flight handlers finish (D-73), and returns nil.
	Consume(ctx context.Context, queue string, handler Handler) error
	// Healthy reports whether the broker connection is currently usable.
	// A consumer that has lost it still has a process, so compose needs to
	// be able to ask (D-72).
	Healthy() bool
	io.Closer
}
