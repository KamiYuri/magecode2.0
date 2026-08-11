package rmq

import (
	"time"

	"github.com/magecode/shared/go/apperror"
)

// failureAction is the routing decision for a failed delivery per D-79e:
// schedule a delayed retry or park the message on the queue's DLQ.
type failureAction int

const (
	actionRetry failureAction = iota + 1
	actionDeadLetter
)

// routeFailure decides what happens to a delivery whose handler returned err.
// retryCount is the number of retries already consumed (0 on first delivery).
// Permanent errors dead-letter immediately; transient and unclassified errors
// retry with exponential backoff (base << retryCount) until maxRetries is
// exhausted. Unclassified errors default to transient so a flaky third-party
// failure still gets its 3 attempts before parking.
func routeFailure(err error, retryCount, maxRetries int, base time.Duration) (failureAction, time.Duration) {
	if apperror.IsPermanent(err) {
		return actionDeadLetter, 0
	}
	if retryCount >= maxRetries {
		return actionDeadLetter, 0
	}
	return actionRetry, base << retryCount
}

// reconnectDelay returns the capped exponential backoff for redial attempt
// (0-indexed). The shift is guarded so large attempts cannot overflow.
func reconnectDelay(attempt int, base, max time.Duration) time.Duration {
	const maxShift = 32
	if attempt > maxShift {
		return max
	}
	delay := base << attempt
	if delay > max || delay <= 0 {
		return max
	}
	return delay
}

func retryQueueName(queue string) string { return queue + ".retry" }
func dlqName(queue string) string        { return queue + ".dlq" }
