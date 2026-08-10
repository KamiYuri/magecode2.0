// Package apperror provides the shared error taxonomy for MageCode Go
// services. Errors are classified Transient (worth retrying) or Permanent
// (retry can never succeed); RabbitMQ consumers use the classification to
// route failures between requeue-with-backoff and the dead-letter exchange
// per D-79e.
package apperror

import (
	"errors"
	"fmt"
)

// Category classifies whether retrying the failed operation can succeed.
type Category int

const (
	// Transient failures (network timeouts, connection refused, broker
	// unavailable) may succeed on retry.
	Transient Category = iota + 1
	// Permanent failures (malformed payload, validation, business rule)
	// can never succeed and go straight to the DLX.
	Permanent
)

// Error is a classified error. When chains contain several *Error values,
// the outermost classification wins — a layer that exhausted retries may
// reclassify a Transient cause as Permanent.
type Error struct {
	Category Category
	msg      string
	cause    error
}

// New returns a classified error with no underlying cause.
func New(category Category, msg string) *Error {
	return &Error{Category: category, msg: msg}
}

// Wrap returns a classified error wrapping cause; the cause stays visible
// to errors.Is/As via Unwrap.
func Wrap(category Category, msg string, cause error) *Error {
	return &Error{Category: category, msg: msg, cause: cause}
}

func (e *Error) Error() string {
	if e.cause == nil {
		return e.msg
	}
	return fmt.Sprintf("%s: %s", e.msg, e.cause.Error())
}

func (e *Error) Unwrap() error { return e.cause }

// IsTransient reports whether the outermost classification in err's chain
// is Transient. Unclassified errors (and nil) report false.
func IsTransient(err error) bool { return categoryOf(err) == Transient }

// IsPermanent reports whether the outermost classification in err's chain
// is Permanent. Unclassified errors (and nil) report false.
func IsPermanent(err error) bool { return categoryOf(err) == Permanent }

// categoryOf returns the Category of the first (outermost) *Error in the
// chain, or 0 when the chain carries no classification.
func categoryOf(err error) Category {
	var appErr *Error
	if errors.As(err, &appErr) {
		return appErr.Category
	}
	return 0
}
