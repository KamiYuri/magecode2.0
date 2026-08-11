package rmq

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
)

type consumer struct {
	conn *connection
	cfg  Config
}

// NewConsumer connects to the broker and returns a Consumer. It fails only
// when the first connection cannot be established before ctx is done.
func NewConsumer(ctx context.Context, cfg Config) (Consumer, error) {
	cfg = cfg.withDefaults()
	conn, err := newConnection(ctx, cfg)
	if err != nil {
		return nil, err
	}
	return &consumer{conn: conn, cfg: cfg}, nil
}

// Consume blocks processing queue until ctx is canceled. Deliveries are
// handled sequentially within the prefetch window; on cancellation the
// in-flight handler finishes and its message is settled before returning
// (graceful drain, D-73). Lost connections are redialed with backoff.
func (c *consumer) Consume(ctx context.Context, queue string, handler Handler) error {
	consumerTag := fmt.Sprintf("magecode-%s-%d", queue, time.Now().UnixNano())
	for {
		if ctx.Err() != nil {
			return nil
		}
		err := c.consumeSession(ctx, queue, consumerTag, handler)
		if err != nil {
			return err
		}
		if ctx.Err() != nil {
			return nil
		}
		// Session ended without cancellation: the channel or connection
		// died. Loop redials via connection.channel's backoff.
		c.cfg.Logger.Warn("rabbitmq consume session ended, reconnecting",
			"data", map[string]any{"queue": queue})
	}
}

// consumeSession runs one channel lifetime: declare, qos, consume, dispatch.
// It returns nil both on ctx cancellation and on channel loss; the caller
// distinguishes via ctx.Err().
func (c *consumer) consumeSession(ctx context.Context, queue, consumerTag string, handler Handler) error {
	ch, err := c.conn.channel(ctx)
	if err != nil {
		if ctx.Err() != nil {
			return nil
		}
		return err
	}
	defer ch.Close()

	if err := declareTopology(ch, queue); err != nil {
		return err
	}
	if err := ch.Qos(c.cfg.PrefetchCount, 0, false); err != nil {
		return fmt.Errorf("setting prefetch: %w", err)
	}
	deliveries, err := ch.Consume(queue, consumerTag, false, false, false, false, nil)
	if err != nil {
		return fmt.Errorf("starting consume: %w", err)
	}

	for {
		select {
		case <-ctx.Done():
			// Stop new deliveries; buffered-but-unacked messages are
			// requeued by the broker when the channel closes.
			_ = ch.Cancel(consumerTag, false)
			return nil
		case delivery, open := <-deliveries:
			if !open {
				return nil // channel died; caller reconnects
			}
			c.handleDelivery(ctx, ch, queue, delivery, handler)
		}
	}
}

// handleDelivery runs the handler and settles the message: ack on success,
// republish to the retry queue with per-message TTL on transient failure,
// park on the DLQ when permanent or out of retry budget (D-79e).
func (c *consumer) handleDelivery(ctx context.Context, ch *amqp.Channel, queue string, msg amqp.Delivery, handler Handler) {
	retryCount := headerInt(msg.Headers, headerRetryCount)
	traceID := headerString(msg.Headers, headerTraceID)

	handlerErr := handler(ctx, Delivery{
		Queue:      queue,
		Body:       msg.Body,
		TraceID:    traceID,
		RetryCount: retryCount,
	})
	if handlerErr == nil {
		if err := msg.Ack(false); err != nil {
			c.cfg.Logger.Error("acking delivery", "error", err.Error(),
				"trace_id", traceID, "data", map[string]any{"queue": queue})
		}
		return
	}

	action, delay := routeFailure(handlerErr, retryCount, c.cfg.MaxRetries, c.cfg.RetryBaseDelay)
	var routeErr error
	switch action {
	case actionRetry:
		routeErr = c.publishCopy(ch, retryQueueName(queue), msg, amqp.Table{
			headerRetryCount: int32(retryCount + 1),
			headerTraceID:    traceID,
			headerLastError:  handlerErr.Error(),
		}, delay)
	case actionDeadLetter:
		routeErr = c.publishCopy(ch, dlqName(queue), msg, amqp.Table{
			headerRetryCount: int32(retryCount),
			headerTraceID:    traceID,
			headerLastError:  handlerErr.Error(),
		}, 0)
	}
	if routeErr != nil {
		// Routing failed (broker hiccup): requeue the original so the
		// message is not lost; it will be retried without count increase.
		c.cfg.Logger.Error("routing failed delivery, requeueing", "error", routeErr.Error(),
			"trace_id", traceID, "data", map[string]any{"queue": queue})
		if err := msg.Nack(false, true); err != nil {
			c.cfg.Logger.Error("nacking delivery", "error", err.Error(), "trace_id", traceID)
		}
		return
	}
	c.cfg.Logger.Warn("delivery failed", "error", handlerErr.Error(), "trace_id", traceID,
		"data", map[string]any{
			"queue": queue, "retry_count": retryCount,
			"action": map[failureAction]string{actionRetry: "retry", actionDeadLetter: "dead-letter"}[action],
		})
	if err := msg.Ack(false); err != nil {
		c.cfg.Logger.Error("acking routed delivery", "error", err.Error(), "trace_id", traceID)
	}
}

// publishCopy republishes a delivery's body to target on the same channel.
// A non-zero ttl becomes the per-message expiration driving the exponential
// retry backoff.
func (c *consumer) publishCopy(ch *amqp.Channel, target string, msg amqp.Delivery, headers amqp.Table, ttl time.Duration) error {
	publishing := amqp.Publishing{
		ContentType:  msg.ContentType,
		DeliveryMode: amqp.Persistent,
		Timestamp:    time.Now().UTC(),
		Headers:      headers,
		Body:         msg.Body,
	}
	if ttl > 0 {
		publishing.Expiration = strconv.FormatInt(ttl.Milliseconds(), 10)
	}
	if err := ch.PublishWithContext(context.Background(), "", target, false, false, publishing); err != nil {
		return fmt.Errorf("republishing to %s: %w", target, err)
	}
	return nil
}

func (c *consumer) Close() error {
	err := c.conn.close()
	if err != nil && !errors.Is(err, amqp.ErrClosed) {
		return err
	}
	return nil
}
