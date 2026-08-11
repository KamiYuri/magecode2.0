package rmq

import (
	"errors"
	"testing"
	"time"

	"github.com/magecode/shared/go/apperror"
)

func TestRouteFailurePermanentGoesToDLQ(t *testing.T) {
	err := apperror.New(apperror.Permanent, "malformed payload")

	action, _ := routeFailure(err, 0, 3, time.Second)

	if action != actionDeadLetter {
		t.Errorf("action = %v, want actionDeadLetter for permanent error on first attempt", action)
	}
}

func TestRouteFailureTransientRetriesWithExponentialBackoff(t *testing.T) {
	err := apperror.New(apperror.Transient, "broker hiccup")
	base := time.Second

	tests := []struct {
		retryCount int
		wantAction failureAction
		wantDelay  time.Duration
	}{
		{0, actionRetry, 1 * time.Second},
		{1, actionRetry, 2 * time.Second},
		{2, actionRetry, 4 * time.Second},
		{3, actionDeadLetter, 0},
	}
	for _, tt := range tests {
		action, delay := routeFailure(err, tt.retryCount, 3, base)
		if action != tt.wantAction {
			t.Errorf("retryCount=%d: action = %v, want %v", tt.retryCount, action, tt.wantAction)
		}
		if delay != tt.wantDelay {
			t.Errorf("retryCount=%d: delay = %v, want %v", tt.retryCount, delay, tt.wantDelay)
		}
	}
}

func TestRouteFailureUnclassifiedTreatedAsTransient(t *testing.T) {
	err := errors.New("third-party library error")

	action, delay := routeFailure(err, 0, 3, time.Second)

	if action != actionRetry {
		t.Errorf("action = %v, want actionRetry (unclassified defaults to transient)", action)
	}
	if delay != time.Second {
		t.Errorf("delay = %v, want 1s", delay)
	}
}

func TestConfigDefaults(t *testing.T) {
	cfg := Config{URL: "amqp://localhost"}.withDefaults()

	if cfg.MaxRetries != 3 {
		t.Errorf("MaxRetries = %d, want 3 (D-79e)", cfg.MaxRetries)
	}
	if cfg.PrefetchCount != 1 {
		t.Errorf("PrefetchCount = %d, want 1", cfg.PrefetchCount)
	}
	if cfg.RetryBaseDelay != time.Second {
		t.Errorf("RetryBaseDelay = %v, want 1s", cfg.RetryBaseDelay)
	}
	if cfg.ReconnectBaseDelay != 500*time.Millisecond {
		t.Errorf("ReconnectBaseDelay = %v, want 500ms", cfg.ReconnectBaseDelay)
	}
	if cfg.ReconnectMaxDelay != 30*time.Second {
		t.Errorf("ReconnectMaxDelay = %v, want 30s", cfg.ReconnectMaxDelay)
	}
	if cfg.Logger == nil {
		t.Error("Logger default must not be nil")
	}
}

func TestConfigExplicitValuesKept(t *testing.T) {
	cfg := Config{URL: "amqp://localhost", PrefetchCount: 5, MaxRetries: 1}.withDefaults()

	if cfg.PrefetchCount != 5 {
		t.Errorf("PrefetchCount = %d, want explicit 5 (CES, D-76)", cfg.PrefetchCount)
	}
	if cfg.MaxRetries != 1 {
		t.Errorf("MaxRetries = %d, want explicit 1", cfg.MaxRetries)
	}
}

func TestQueueNaming(t *testing.T) {
	if got := retryQueueName("code-executor"); got != "code-executor.retry" {
		t.Errorf("retryQueueName = %q", got)
	}
	if got := dlqName("code-executor"); got != "code-executor.dlq" {
		t.Errorf("dlqName = %q", got)
	}
}

func TestReconnectDelayCapped(t *testing.T) {
	base, max := 500*time.Millisecond, 30*time.Second

	tests := []struct {
		attempt int
		want    time.Duration
	}{
		{0, 500 * time.Millisecond},
		{1, time.Second},
		{4, 8 * time.Second},
		{10, 30 * time.Second}, // 512s uncapped — must clamp to max
		{63, 30 * time.Second}, // shift overflow guard
	}
	for _, tt := range tests {
		if got := reconnectDelay(tt.attempt, base, max); got != tt.want {
			t.Errorf("attempt %d: delay = %v, want %v", tt.attempt, got, tt.want)
		}
	}
}
