package rmq

import (
	"context"
	"fmt"
	"sync"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"

	"github.com/magecode/shared/go/apperror"
)

// connection wraps one AMQP connection with redial-on-demand. Publishers and
// consumers hold their own connection (AMQP best practice: never share a
// connection between publishing and consuming).
type connection struct {
	cfg Config

	mu   sync.Mutex
	conn *amqp.Connection
}

func newConnection(ctx context.Context, cfg Config) (*connection, error) {
	c := &connection{cfg: cfg}
	if _, err := c.channel(ctx); err != nil {
		return nil, err
	}
	return c, nil
}

// channel returns a fresh channel, redialing the connection with capped
// exponential backoff if it is closed. The caller owns the channel.
func (c *connection) channel(ctx context.Context) (*amqp.Channel, error) {
	c.mu.Lock()
	defer c.mu.Unlock()

	for attempt := 0; ; attempt++ {
		if c.conn == nil || c.conn.IsClosed() {
			conn, err := amqp.Dial(c.cfg.URL)
			if err != nil {
				delay := reconnectDelay(attempt, c.cfg.ReconnectBaseDelay, c.cfg.ReconnectMaxDelay)
				c.cfg.Logger.Warn("rabbitmq dial failed, retrying",
					"error", err.Error(), "data", map[string]any{"attempt": attempt, "delay": delay.String()})
				select {
				case <-ctx.Done():
					return nil, apperror.Wrap(apperror.Transient, "dialing rabbitmq", ctx.Err())
				case <-time.After(delay):
					continue
				}
			}
			c.conn = conn
		}
		ch, err := c.conn.Channel()
		if err != nil {
			// Connection died between IsClosed and Channel — force redial.
			c.conn = nil
			continue
		}
		return ch, nil
	}
}

func (c *connection) close() error {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.conn == nil || c.conn.IsClosed() {
		return nil
	}
	return c.conn.Close()
}

// declareTopology declares the durable per-queue trio of D-79e: the main
// queue, its retry queue (messages dead-letter back to the main queue when
// their per-message TTL expires), and its DLQ. Idempotent.
func declareTopology(ch *amqp.Channel, queue string) error {
	if _, err := ch.QueueDeclare(dlqName(queue), true, false, false, false, nil); err != nil {
		return apperror.Wrap(apperror.Transient, fmt.Sprintf("declaring %s", dlqName(queue)), err)
	}
	retryArgs := amqp.Table{
		"x-dead-letter-exchange":    "",
		"x-dead-letter-routing-key": queue,
	}
	if _, err := ch.QueueDeclare(retryQueueName(queue), true, false, false, false, retryArgs); err != nil {
		return apperror.Wrap(apperror.Transient, fmt.Sprintf("declaring %s", retryQueueName(queue)), err)
	}
	if _, err := ch.QueueDeclare(queue, true, false, false, false, nil); err != nil {
		return apperror.Wrap(apperror.Transient, fmt.Sprintf("declaring %s", queue), err)
	}
	return nil
}

// headerString reads a string header, tolerating absent or non-string values.
func headerString(headers amqp.Table, key string) string {
	if value, ok := headers[key].(string); ok {
		return value
	}
	return ""
}

// headerInt reads a numeric header across the integer types amqp091 may
// deliver, defaulting to 0.
func headerInt(headers amqp.Table, key string) int {
	switch value := headers[key].(type) {
	case int:
		return value
	case int8:
		return int(value)
	case int16:
		return int(value)
	case int32:
		return int(value)
	case int64:
		return int(value)
	default:
		return 0
	}
}
