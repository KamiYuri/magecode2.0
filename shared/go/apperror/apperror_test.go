package apperror_test

import (
	"errors"
	"fmt"
	"io"
	"testing"

	"github.com/magecode/shared/go/apperror"
)

func TestNewCarriesCategory(t *testing.T) {
	tests := []struct {
		name          string
		err           error
		wantTransient bool
		wantPermanent bool
	}{
		{"transient", apperror.New(apperror.Transient, "rabbitmq unreachable"), true, false},
		{"permanent", apperror.New(apperror.Permanent, "malformed payload"), false, true},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := apperror.IsTransient(tt.err); got != tt.wantTransient {
				t.Errorf("IsTransient = %v, want %v", got, tt.wantTransient)
			}
			if got := apperror.IsPermanent(tt.err); got != tt.wantPermanent {
				t.Errorf("IsPermanent = %v, want %v", got, tt.wantPermanent)
			}
		})
	}
}

func TestCategorySurvivesFmtErrorfWrapping(t *testing.T) {
	base := apperror.New(apperror.Transient, "minio timeout")
	wrapped := fmt.Errorf("downloading submission: %w", fmt.Errorf("layer two: %w", base))

	if !apperror.IsTransient(wrapped) {
		t.Error("IsTransient lost through fmt.Errorf chain")
	}
	if apperror.IsPermanent(wrapped) {
		t.Error("IsPermanent = true for a transient chain")
	}
}

func TestWrapPreservesCauseThroughErrorsIs(t *testing.T) {
	wrapped := apperror.Wrap(apperror.Permanent, "reading object", io.ErrUnexpectedEOF)

	if !errors.Is(wrapped, io.ErrUnexpectedEOF) {
		t.Error("errors.Is cannot see the wrapped cause")
	}
	if !apperror.IsPermanent(wrapped) {
		t.Error("Wrap did not attach the Permanent category")
	}
}

func TestErrorsAsExtractsError(t *testing.T) {
	wrapped := fmt.Errorf("outer: %w", apperror.Wrap(apperror.Transient, "db ping", io.EOF))

	var appErr *apperror.Error
	if !errors.As(wrapped, &appErr) {
		t.Fatal("errors.As failed to extract *apperror.Error")
	}
	if appErr.Category != apperror.Transient {
		t.Errorf("Category = %v, want Transient", appErr.Category)
	}
}

func TestReclassificationOutermostWins(t *testing.T) {
	// A transient cause reclassified as permanent (e.g. retries exhausted,
	// or a validation layer deciding the failure can never succeed).
	inner := apperror.New(apperror.Transient, "judge0 timeout")
	outer := apperror.Wrap(apperror.Permanent, "retries exhausted", inner)

	if !apperror.IsPermanent(outer) {
		t.Error("outermost Permanent classification not honored")
	}
	if apperror.IsTransient(outer) {
		t.Error("IsTransient = true despite outermost Permanent wrap")
	}
}

func TestUnclassifiedErrorsAreNeither(t *testing.T) {
	plain := errors.New("some library error")

	if apperror.IsTransient(plain) {
		t.Error("IsTransient = true for unclassified error")
	}
	if apperror.IsPermanent(plain) {
		t.Error("IsPermanent = true for unclassified error")
	}
	if apperror.IsTransient(nil) || apperror.IsPermanent(nil) {
		t.Error("nil must be neither transient nor permanent")
	}
}

func TestErrorMessageFormat(t *testing.T) {
	if got := apperror.New(apperror.Transient, "queue full").Error(); got != "queue full" {
		t.Errorf("New Error() = %q, want %q", got, "queue full")
	}
	wrapped := apperror.Wrap(apperror.Permanent, "parsing job", io.EOF)
	if got := wrapped.Error(); got != "parsing job: EOF" {
		t.Errorf("Wrap Error() = %q, want %q", got, "parsing job: EOF")
	}
}
