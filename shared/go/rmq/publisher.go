package rmq

import (
	"context"
	"sync"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"

	"github.com/magecode/shared/go/apperror"
)

type publisher struct {
	conn *connection

	mu       sync.Mutex
	ch       *amqp.Channel
	declared map[string]bool
}

// NewPublisher connects to the broker and returns a Publisher. It fails only
// when the first connection cannot be established before ctx is done.
func NewPublisher(ctx context.Context, cfg Config) (Publisher, error) {
	conn, err := newConnection(ctx, cfg.withDefaults())
	if err != nil {
		return nil, err
	}
	return &publisher{conn: conn, declared: make(map[string]bool)}, nil
}

func (p *publisher) Publish(ctx context.Context, queue string, body []byte, traceID string) error {
	p.mu.Lock()
	defer p.mu.Unlock()

	// Two attempts: a stale channel from a dropped connection gets one
	// transparent redial before the error surfaces to the caller.
	var lastErr error
	for attempt := 0; attempt < 2; attempt++ {
		if err := p.ensureChannel(ctx, queue); err != nil {
			return err
		}
		confirmation, err := p.ch.PublishWithDeferredConfirmWithContext(
			ctx, "", queue, false, false, amqp.Publishing{
				ContentType:  "application/json",
				DeliveryMode: amqp.Persistent,
				Timestamp:    time.Now().UTC(),
				Headers:      amqp.Table{headerTraceID: traceID},
				Body:         body,
			})
		if err != nil {
			lastErr = err
			p.resetChannel()
			continue
		}
		acked, err := confirmation.WaitContext(ctx)
		if err != nil {
			return apperror.Wrap(apperror.Transient, "awaiting publish confirm", err)
		}
		if !acked {
			return apperror.New(apperror.Transient, "broker nacked publish")
		}
		return nil
	}
	return apperror.Wrap(apperror.Transient, "publishing after reconnect", lastErr)
}

// ensureChannel lazily opens a confirm-mode channel and declares the target
// queue's topology once per publisher lifetime.
func (p *publisher) ensureChannel(ctx context.Context, queue string) error {
	if p.ch == nil || p.ch.IsClosed() {
		ch, err := p.conn.channel(ctx)
		if err != nil {
			return err
		}
		if err := ch.Confirm(false); err != nil {
			_ = ch.Close()
			return apperror.Wrap(apperror.Transient, "enabling confirm mode", err)
		}
		p.ch = ch
		// Channel state was lost; topology cache must be rebuilt too.
		p.declared = make(map[string]bool)
	}
	if !p.declared[queue] {
		if err := declareTopology(p.ch, queue); err != nil {
			return err
		}
		p.declared[queue] = true
	}
	return nil
}

func (p *publisher) resetChannel() {
	if p.ch != nil {
		_ = p.ch.Close()
	}
	p.ch = nil
}

func (p *publisher) Close() error {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.resetChannel()
	return p.conn.close()
}
